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
 * Two triggers, one transaction shape:
 *   - **Retype** (`$newDeclaredType !== null`): `stardust_fields.declared_type`
 *     is updated; (old, new) declared_type is checked against the
 *     ADR 0024 categorical rejections (`int↔datetime`,
 *     `numeric↔datetime`) before any mutation.
 *   - **Filterability promotion** (`$newIsFilterable === true`):
 *     `stardust_fields.is_filterable` is updated; declared_type
 *     stays the same; the new slot reservation demands an indexed
 *     column.
 *
 * Both triggers share the rest of the registry tuple:
 *   - The field's current live slot (if any) flips
 *     `assigned/ready → tombstoned` with `field_id = NULL`. Liberator
 *     (Phase 6a) reclaims it asynchronously.
 *   - A new slot of the target shape is reserved
 *     `free → backfilling`. If no matching free slot exists, the
 *     reservation is deferred — the Reconciler's retype work source
 *     attempts it on every subsequent tick until capacity returns
 *     (per ADR 0016 commitment 4: no eager page provisioning).
 *   - `stardust_schema_version.version` is bumped once for the
 *     whole tuple (ADR 0017 §4.6 invariant #2).
 *   - A `backfill_checkpoints` row is inserted with
 *     `job_name = 'retype_field_{field_id}'`, `status='running'`,
 *     `last_processed_id = 0`.
 *
 * All four mutations commit together or roll back together. On
 * success a `retype_started` event is emitted on the registry
 * source.
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

            // 3. Reserve a new `backfilling` slot. Indexed if the
            //    field is now filterable; the work source will retry
            //    the reservation on each tick if it returns null.
            $newSlot = $this->slotReserver->reserveForBackfillWithinTransaction(
                $fieldId,
                requireIndexed: $effectiveIsFilterable,
            );

            // 4. Bump schema_version once for the whole tuple.
            //    SlotReserver::reserveCore() already bumps it when it
            //    finds a slot; if no slot was found we still need to
            //    record the field + tombstone mutation as a schema
            //    change.
            if ($newSlot === null) {
                $bump = $this->pdo->prepare(
                    'UPDATE stardust_schema_version'
                    . ' SET version = version + 1, updated_at = ?'
                    . ' WHERE id = 1'
                );
                $bump->execute([$now]);
            }

            // 5. Insert the running checkpoint row. `source_declared_type`
            //    preserves the field's pre-retype type so the work source
            //    can pick the right ADR 0024 matrix cell — the field's
            //    `declared_type` column has already been overwritten with
            //    the target above.
            $this->checkpointRepository->insert($fieldId, $oldDeclaredType, $now);

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
            'deferred_assignment'    => $newSlot === null,
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
