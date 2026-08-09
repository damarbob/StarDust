<?php

declare(strict_types=1);

namespace StarDust\Retype;

use DateTimeZone;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Exception\FieldNotFoundException;
use StarDust\Exception\IncompatibleRetypeException;
use StarDust\Exception\RetypeInProgressException;
use StarDust\Slot\SlotReserver;
use Throwable;

/**
 * Phase 6b atomic registry transaction for `retype → tombstone →
 * assign → backfill → promote` lifecycle initiation (ADR 0016).
 *
 * Two triggers, three transaction shapes. The trigger decides what
 * changes on `stardust_fields`; whether the *target* is filterable
 * decides whether there is any backfill at all, because under ADR 0034
 * only a filterable field may hold a slot.
 *
 * Triggers:
 *   - **Retype** (`$newDeclaredType !== null`): `stardust_fields.declared_type`
 *     is updated; (old, new) declared_type is checked against the
 *     ADR 0024 categorical rejections (`int↔datetime`,
 *     `numeric↔datetime`) before any mutation.
 *   - **Filterability change** (`$newIsFilterable !== null`):
 *     `stardust_fields.is_filterable` is updated; declared_type stays
 *     the same.
 *
 * Shapes:
 *   - **Filterable target** (retype of a filterable field, or a
 *     `false → true` promotion) — the full tuple: tombstone any live
 *     slot, reserve a new indexed `free → backfilling` slot, bump the
 *     schema version, insert a `running` checkpoint for the Reconciler
 *     to drain. If no matching free slot exists the reservation is
 *     deferred — the retype work source retries on every subsequent
 *     tick until capacity returns (ADR 0016 commitment 4: no eager
 *     page provisioning).
 *   - **Non-filterable target** (retype of a JSON-only field, or a
 *     `true → false` demotion) — registry-only: update the field,
 *     tombstone a live slot if one exists, bump the schema version,
 *     and stop. No reservation, no checkpoint, nothing for the
 *     Reconciler to drain. The JSON payload is authoritative (ADR
 *     0013), so there is nothing to backfill; on demotion reads fall
 *     straight back to `JSON_EXTRACT`.
 *
 * Common to every shape:
 *   - The field's current live slot (if any) flips
 *     `assigned/ready → tombstoned` with `field_id = NULL`. Liberator
 *     (Phase 6a) reclaims it asynchronously. Under ADR 0034 a
 *     promotion normally has *no* old slot to tombstone — only a
 *     grandfathered pre-0034 field does.
 *   - `stardust_schema_version.version` is bumped exactly once for the
 *     whole tuple (ADR 0017 §4.6 invariant #2), whichever shape ran.
 *
 * All mutations commit together or roll back together. On success a
 * `retype_started` event is emitted on the registry source, carrying
 * `backfill_required` so operators can tell a lifecycle that will be
 * continued by the Reconciler from one that is already complete.
 */
final class RetypeInitiator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly SlotReserver $slotReserver,
        private readonly RetypeCheckpointRepository $checkpointRepository,
    ) {
    }

    /**
     * Atomically initiate a retype or filterability promotion for a
     * field. Exactly one of `$newDeclaredType` and `$newIsFilterable`
     * must be non-null — the StarDust facade enforces this by
     * exposing two separate public methods.
     */
    public function initiate(
        int $tenantId,
        int $fieldId,
        ?string $newDeclaredType,
        ?bool $newIsFilterable,
    ): void {
        $field = $this->loadField($tenantId, $fieldId);

        $oldDeclaredType = $field['declared_type'];
        $oldIsFilterable = $field['is_filterable'];

        $effectiveDeclaredType = $newDeclaredType ?? $oldDeclaredType;
        $effectiveIsFilterable = $newIsFilterable ?? $oldIsFilterable;

        // ADR 0034: only a filterable field can hold a slot, so only a
        // filterable target has anything to backfill. A non-filterable
        // target — a retype of a JSON-only field, or a demotion — is
        // registry-only: update, tombstone any grandfathered legacy
        // slot, bump, done.
        $backfillRequired = $effectiveIsFilterable;

        if ($newDeclaredType !== null
            && RetypeCoercionEngine::isCategoricallyRejected($oldDeclaredType, $newDeclaredType)
        ) {
            throw new IncompatibleRetypeException(
                "Retype rejected: '{$oldDeclaredType}' → '{$newDeclaredType}' is categorically"
                . ' incompatible (ADR 0024). Bridge through a `string` intermediate field if'
                . ' you require epoch-style migration.'
            );
        }

        if ($this->checkpointRepository->existsRunningForField($fieldId)) {
            throw new RetypeInProgressException(
                "Field {$fieldId} already has a running retype-backfill checkpoint."
            );
        }

        $now = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $newSlot = null;
        $newSlotEmittedStatus = 'backfilling';
        $oldSlotId = null;

        $this->pdo->beginTransaction();
        try {
            // 1. Mutate stardust_fields.
            if ($newDeclaredType !== null || $newIsFilterable !== null) {
                $update = $this->pdo->prepare(
                    'UPDATE stardust_fields'
                    . ' SET declared_type = ?, is_filterable = ?, updated_at = ?'
                    . ' WHERE id = ?'
                );
                $update->execute([
                    $effectiveDeclaredType,
                    $effectiveIsFilterable ? 1 : 0,
                    $now,
                    $fieldId,
                ]);
            }

            // 2. Tombstone the field's current live slot, if any.
            $oldSlotId = $this->tombstoneLiveSlot($fieldId, $now);

            // 3. Reserve a new `backfilling` slot — only for a
            //    filterable target. The reservation is unreachable for
            //    a non-filterable field (ADR 0034 would reject it
            //    anyway), so `requireIndexed` is always true here: a
            //    filterable field's slot must be indexed per ADR 0016
            //    commitment 1. The work source retries the reservation
            //    on each tick if it returns null.
            if ($backfillRequired) {
                $newSlot = $this->slotReserver->reserveForBackfillWithinTransaction(
                    $fieldId,
                    requireIndexed: true,
                );
            }

            // 4. Bump schema_version once for the whole tuple.
            //    SlotReserver::reserveCore() already bumps it when it
            //    finds a slot; we bump here whenever no slot was
            //    reserved — either the reservation deferred, or this is
            //    a registry-only transition that never attempted one —
            //    so the field + tombstone mutation is still recorded as
            //    a schema change. Exactly one bump on every path.
            if ($newSlot === null) {
                $bump = $this->pdo->prepare(
                    'UPDATE stardust_schema_version'
                    . ' SET version = version + 1, updated_at = ?'
                    . ' WHERE id = 1'
                );
                $bump->execute([$now]);
            }

            // 5. Insert the running checkpoint row — only when there is
            //    a backfill to run. `source_declared_type` preserves the
            //    field's pre-retype type so the work source can pick the
            //    right ADR 0024 matrix cell; the field's `declared_type`
            //    column has already been overwritten with the target
            //    above. A non-filterable field is JSON-only and
            //    authoritative (ADR 0013), so no checkpoint is written
            //    and the Reconciler has nothing to claim.
            if ($backfillRequired) {
                $this->checkpointRepository->insert($fieldId, $oldDeclaredType, $now);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        if ($newSlot !== null) {
            $this->slotReserver->emitSlotReservedEvent($fieldId, $newSlot, $newSlotEmittedStatus);
        }

        $this->logger->info('retype started', [
            'event'                  => 'retype_started',
            'source'                 => 'registry',
            'tenant_id'              => $tenantId,
            'field_id'               => $fieldId,
            'old_declared_type'      => $oldDeclaredType,
            'new_declared_type'      => $effectiveDeclaredType,
            'old_is_filterable'      => $oldIsFilterable,
            'new_is_filterable'      => $effectiveIsFilterable,
            'old_slot_assignment_id' => $oldSlotId,
            'new_slot_assignment_id' => $newSlot?->slotAssignmentId,
            // `backfill_required` false means the lifecycle started and
            // finished in this one transaction: no Reconciler work will
            // follow and the absence of a later `promote_to_ready` is
            // not a stall. Guarding `deferred_assignment` on it matters
            // — a registry-only transition always leaves $newSlot null,
            // and reporting that as "deferred" would show operators a
            // permanent phantom backlog that will never be resumed.
            'backfill_required'      => $backfillRequired,
            'deferred_assignment'    => $backfillRequired && $newSlot === null,
        ]);
    }

    /**
     * @return array{declared_type: string, is_filterable: bool, model_id: int}
     */
    private function loadField(int $tenantId, int $fieldId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT f.declared_type, f.is_filterable, f.model_id, m.tenant_id'
            . ' FROM stardust_fields f'
            . ' JOIN stardust_models m ON m.id = f.model_id'
            . ' WHERE f.id = ?'
        );
        $stmt->execute([$fieldId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new FieldNotFoundException("Field {$fieldId} does not exist.");
        }
        if ((int) $row['tenant_id'] !== $tenantId) {
            throw new FieldNotFoundException(
                "Field {$fieldId} does not belong to tenant {$tenantId}."
            );
        }
        return [
            'declared_type' => (string) $row['declared_type'],
            'is_filterable' => (bool) $row['is_filterable'],
            'model_id'      => (int) $row['model_id'],
        ];
    }

    /**
     * Flips the field's current live slot (in any of the live
     * statuses) to `tombstoned`. Returns the old slot's assignment id
     * or `null` if the field had no live slot.
     */
    private function tombstoneLiveSlot(int $fieldId, string $now): ?int
    {
        $select = $this->pdo->prepare(
            'SELECT id FROM stardust_slot_assignments'
            . " WHERE field_id = ? AND status IN ('assigned','backfilling','ready')"
            . ' LIMIT 1 FOR UPDATE'
        );
        $select->execute([$fieldId]);
        $id = $select->fetchColumn();
        if ($id === false) {
            return null;
        }
        $slotId = (int) $id;

        // Two-step tombstone: clear field_id, then flip status. The
        // partial unique index `ux_slot_assignments_field_live` would
        // otherwise complain if a new slot reservation later tries to
        // take `field_id` while a non-tombstoned row still holds it.
        $clearField = $this->pdo->prepare(
            'UPDATE stardust_slot_assignments SET field_id = NULL, updated_at = ? WHERE id = ?'
        );
        $clearField->execute([$now, $slotId]);

        $tombstone = $this->pdo->prepare(
            'UPDATE stardust_slot_assignments'
            . " SET status = 'tombstoned', tombstoned_at = ?, updated_at = ?"
            . ' WHERE id = ?'
        );
        $tombstone->execute([$now, $now, $slotId]);

        return $slotId;
    }
}
