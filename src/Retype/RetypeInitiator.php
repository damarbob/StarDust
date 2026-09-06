<?php

declare(strict_types=1);

namespace StarDust\Retype;

use DateTimeZone;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Exception\CompactionCapacityException;
use StarDust\Exception\FieldDeletionInProgressException;
use StarDust\Exception\FieldNotFoundException;
use StarDust\Exception\IncompatibleRetypeException;
use StarDust\Exception\RenameInProgressException;
use StarDust\Exception\RetypeInProgressException;
use StarDust\Rename\RenameCheckpointRepository;
use StarDust\Slot\LiveSlotTombstoner;
use StarDust\Slot\SlotReserver;
use StarDust\Support\UuidV4;
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
        private readonly LiveSlotTombstoner $tombstoner,
        private readonly RetypeCheckpointRepository $checkpointRepository,
        private readonly RenameCheckpointRepository $renameCheckpointRepository,
    ) {
    }

    /**
     * Atomically initiate a retype or filterability promotion for a
     * field. Exactly one of `$newDeclaredType` and `$newIsFilterable`
     * must be non-null — the StarDust facade enforces this by
     * exposing two separate public methods.
     */
    /**
     * ADR 0033 compaction: relocate a field's slot to one exact page,
     * changing nothing about the field itself.
     *
     * Mechanically a same-type retype — the ADR 0024 coercion matrix
     * short-circuits its identity diagonal, so the backfill copies values
     * across unchanged and the only real effect is the new slot column.
     * `RetypeBackfillWorkSource` drains the resulting checkpoint
     * unmodified.
     *
     * Both type arguments are `null` on purpose. `initiate()` skips the
     * `stardust_fields` UPDATE entirely when neither is supplied, so a
     * relocation leaves the field row genuinely untouched — passing the
     * current type explicitly would issue a no-op UPDATE that still moved
     * `updated_at` on every compacted field.
     *
     * **Pin-or-fail.** Where `initiate()` treats an unavailable slot as a
     * deferral for the work source to retry, this throws. A deferred
     * compaction reservation would later land on whatever page the
     * reserver picks rather than the planner's choice, producing a
     * compaction that does not compact. Admissibility was already checked
     * up front, so reaching this failure means the registry changed under
     * the plan — re-running replans against the new state.
     *
     * `$correlationId` is compaction's operation id: a relocation is a
     * sub-event of the `compaction_planned` that ordered it, so the
     * retype it opens rides that id rather than minting its own.
     *
     * @throws CompactionCapacityException when the pinned page has no indexed
     *                                     free slot of the field's family
     */
    public function initiateRelocation(
        int $tenantId,
        int $fieldId,
        int $pinnedPageId,
        ?string $correlationId = null,
    ): void {
        $this->runTuple($tenantId, $fieldId, null, null, $pinnedPageId, $correlationId);
    }

    public function initiate(
        int $tenantId,
        int $fieldId,
        ?string $newDeclaredType,
        ?bool $newIsFilterable,
    ): void {
        $this->runTuple($tenantId, $fieldId, $newDeclaredType, $newIsFilterable, null, null);
    }

    /**
     * The ADR 0016 initiation tuple, shared by both entry points.
     *
     * `$pinnedPageId` is the only behavioural fork: when set, the
     * reservation is page-pinned and a miss is fatal rather than
     * deferred.
     *
     * `$correlationId` is the enclosing operation's id when there is one
     * (compaction), and `null` when this *is* the operation boundary
     * (`initiate()`), in which case one is minted below. Either way the
     * same id covers `retype_started` and the `slot_reserved` beside it,
     * and is persisted onto the checkpoint so `promote_to_ready` joins
     * them from the other side of the drain.
     */
    private function runTuple(
        int $tenantId,
        int $fieldId,
        ?string $newDeclaredType,
        ?bool $newIsFilterable,
        ?int $pinnedPageId,
        ?string $correlationId,
    ): void {
        $correlationId ??= UuidV4::generate();

        $newSlot = null;
        $newSlotEmittedStatus = 'backfilling';
        $oldSlotId = null;

        // The field read and all four guards run INSIDE the transaction
        // that performs the mutation, not before it. `loadField()` takes
        // `FOR UPDATE OF f` on the field row, and that lock is only
        // worth anything while a transaction holds it — in autocommit it
        // would be dropped the instant the SELECT finished, which is the
        // same reason `RenameInitiator::assertNameAvailable()` sits
        // inside its caller's transaction.
        //
        // What it protects is specific to this lifecycle. Two initiators
        // racing past the checkpoint guards would each read
        // `declared_type` before either committed, and the loser's
        // upsert would then stamp a stale `source_declared_type` onto
        // the checkpoint — sending the backfill through the wrong ADR
        // 0024 matrix cell, with no event and no exception. The other
        // three lifecycles reset a cursor and nothing else, which is why
        // they do not carry this lock.
        //
        // Nothing here mutates before step 1, so the guards still reject
        // "before any mutation": the catch rolls back an empty
        // transaction.
        $this->pdo->beginTransaction();
        try {
            $field = $this->loadField($tenantId, $fieldId);

            $oldDeclaredType = $field['declared_type'];
            $oldIsFilterable = $field['is_filterable'];

            $effectiveDeclaredType = $newDeclaredType ?? $oldDeclaredType;
            $effectiveIsFilterable = $newIsFilterable ?? $oldIsFilterable;

            // ADR 0034: only a filterable field can hold a slot, so only
            // a filterable target has anything to backfill. A
            // non-filterable target — a retype of a JSON-only field, or
            // a demotion — is registry-only: update, tombstone any
            // grandfathered legacy slot, bump, done.
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

            // ADR 0036: an in-flight rename blocks every retype shape, and
            // the guard lives HERE rather than on the StarDust facade on
            // purpose — initiateRelocation() shares runTuple(), so
            // compactModel() inherits the protection. A facade-level check
            // would leave compaction as an unguarded back door.
            //
            // This is a correctness guard, not hygiene: RetypeBackfillExecutor
            // locates values by field name, so mid-rename every row behind
            // the rename cursor reads as "value absent" and its slot is
            // written NULL — silently, with no coercion event, because no
            // coercion was attempted.
            //
            // Checked before the retype guard, in the same order the rename
            // initiator uses, so two concurrent initiators cannot each see
            // the other's row as absent.
            if ($this->renameCheckpointRepository->existsRunningForField($fieldId)) {
                throw new RenameInProgressException(
                    "Field {$fieldId} has a rename in progress; it cannot be retyped,"
                    . ' promoted, demoted, or relocated until the rename backfill completes.'
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
            $oldSlotId = $this->tombstoner->tombstone($fieldId, $now);

            // 3. Reserve a new `backfilling` slot — only for a
            //    filterable target. The reservation is unreachable for
            //    a non-filterable field (ADR 0034 would reject it
            //    anyway), so `requireIndexed` is always true here: a
            //    filterable field's slot must be indexed per ADR 0016
            //    commitment 1. The work source retries the reservation
            //    on each tick if it returns null.
            if ($backfillRequired) {
                $newSlot = $pinnedPageId !== null
                    ? $this->slotReserver->reserveForBackfillOnPageWithinTransaction(
                        $fieldId,
                        $pinnedPageId,
                    )
                    : $this->slotReserver->reserveForBackfillWithinTransaction(
                        $fieldId,
                        requireIndexed: true,
                    );

                // Pin-or-fail (ADR 0033). Throwing inside the transaction
                // means the catch below rolls the whole tuple back — the
                // old slot is un-tombstoned, no checkpoint is written, no
                // version is bumped. A failed relocation leaves nothing
                // for an operator to unpick.
                if ($pinnedPageId !== null && $newSlot === null) {
                    throw new CompactionCapacityException(sprintf(
                        'Cannot relocate field %d (tenant %d) to page %d: no indexed free slot of'
                        . ' its family remains there. The plan was admissible when built, so'
                        . ' capacity was taken in between — re-run to replan. Nothing was mutated.',
                        $fieldId,
                        $tenantId,
                        $pinnedPageId,
                    ));
                }
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
                $this->checkpointRepository->insertOrReset(
                    $fieldId,
                    $oldDeclaredType,
                    $now,
                    $correlationId,
                );
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        if ($newSlot !== null) {
            $this->slotReserver->emitSlotReservedEvent(
                $fieldId,
                $newSlot,
                $newSlotEmittedStatus,
                $tenantId,
                $correlationId,
            );
        }

        $this->logger->info('retype started', [
            'event'                  => 'retype_started',
            'source'                 => 'registry',
            'correlation_id'         => $correlationId,
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
     * Reads the field under a row lock, and rejects the three states no
     * shape may start from.
     *
     * **`FOR UPDATE OF f`, not a bare `FOR UPDATE`.** The statement
     * joins `stardust_models` only to resolve the tenant, and locking
     * that row too would contend with `deleteModel()` for nothing.
     * Verified on MySQL 8.0.13: the `OF` clause parses, a concurrent
     * `UPDATE stardust_models` on the joined row proceeds untouched, and
     * a second initiator's identical SELECT serialises behind this one.
     *
     * The lock only holds because `runTuple()` calls this inside its
     * transaction — see the note there for what it is defending.
     *
     * @return array{declared_type: string, is_filterable: bool, model_id: int}
     */
    private function loadField(int $tenantId, int $fieldId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT f.declared_type, f.is_filterable, f.model_id, f.deleted_at, m.tenant_id'
            . ' FROM stardust_fields f'
            . ' JOIN stardust_models m ON m.id = f.model_id'
            . ' WHERE f.id = ?'
            . ' FOR UPDATE OF f'
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
        // ADR 0037, the third lifecycle guard. Keyed on the registry
        // column rather than the checkpoint, so it still fires for a
        // field whose purge checkpoint was manually failed or deleted.
        // Rides the SELECT this method already runs, and sits here
        // rather than on the facade for the same reason the rename
        // guard does: `initiateRelocation()` shares `runTuple()`, so
        // `compactModel()` inherits it.
        if ($row['deleted_at'] !== null) {
            throw new FieldDeletionInProgressException(
                "Field {$fieldId} is being deleted; it cannot be retyped,"
                . ' promoted, demoted, or relocated.'
            );
        }
        return [
            'declared_type' => (string) $row['declared_type'],
            'is_filterable' => (bool) $row['is_filterable'],
            'model_id'      => (int) $row['model_id'],
        ];
    }
}
