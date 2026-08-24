<?php

declare(strict_types=1);

namespace StarDust\Delete;

use DateTimeZone;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Exception\RenameInProgressException;
use StarDust\Exception\RetypeInProgressException;
use StarDust\Rename\RenameCheckpointRepository;
use StarDust\Retype\RetypeCheckpointRepository;
use StarDust\Slot\LiveSlotTombstoner;
use Throwable;

/**
 * The ADR 0037 field-deletion registry transaction.
 *
 * Severs the field from every read, write, filter and export surface in
 * one commit, then leaves the Reconciler to erase its key from every
 * payload and hard-delete the registry row on the final chunk.
 *
 * Structurally this is {@see \StarDust\Rename\RenameInitiator} with
 * `previous_name` replaced by `deleted_at`, and it inherits that
 * design's central property: **a non-null `deleted_at` means exactly
 * "a deletion is in flight for this field"**, and every registry reader
 * excludes such a row from the instant this commits.
 *
 * ## Why the registry row does not die here
 *
 * It could. The two-step tombstone in {@see LiveSlotTombstoner} nulls
 * `field_id` before flipping status, which releases the RESTRICT
 * foreign key inside this transaction — verified against MySQL 8.0.13,
 * where the same DELETE fails with errno 1451 before that runs and
 * succeeds immediately after. Deletion is genuinely not gated on the
 * Liberator.
 *
 * It does not, because the purge needs the field's name, model and
 * tenant to build its JSON path and `backfill_checkpoints` has no
 * column for any of them. Keeping the row alive under `deleted_at` lets
 * the work source recover all three from the JOIN it already runs.
 *
 * ## `is_filterable` is cleared in the same UPDATE
 *
 * Not hygiene — a field with `is_filterable = 1` and no live slot *is*
 * demand. `PendingDemandReader` would have the Watcher provision a page
 * for a field being deleted (ADR 0035), and `UnmappedFieldReserver`
 * would reserve it a fresh slot on the ADR 0007 exhaustion path,
 * re-taking the foreign key and blocking the final DELETE. Both gate on
 * `is_filterable = 1`, so clearing it closes both. It also makes the
 * honest statement about what a delete does: it is a superset of a
 * demotion.
 */
final class DeleteFieldInitiator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly LiveSlotTombstoner $tombstoner,
        private readonly DeleteCheckpointRepository $deleteCheckpoints,
        private readonly RenameCheckpointRepository $renameCheckpoints,
        private readonly RetypeCheckpointRepository $retypeCheckpoints,
    ) {
    }

    /**
     * Returns `true` when a deletion was initiated, `false` when there
     * was nothing to do.
     *
     * `false` covers three cases deliberately made indistinguishable:
     * the field does not exist, it belongs to another tenant, or its
     * deletion is already in flight. The first two follow the tenant
     * isolation rule every other entry point observes (Architecture
     * Blueprint §1.2); the third is idempotence, matching
     * `deleteEntry()` — a repeated delete has already achieved what the
     * caller wanted.
     *
     * @throws RenameInProgressException when a rename backfill is running for the field
     * @throws RetypeInProgressException when a retype backfill is running for the field
     */
    public function initiate(int $tenantId, int $fieldId): bool
    {
        $field = $this->loadField($tenantId, $fieldId);

        // Unknown, foreign-tenant, or already being deleted. Returned
        // rather than thrown; see the method docblock.
        if ($field === null) {
            return false;
        }

        // Both guards, in the order every initiator uses — rename
        // first, then retype — so two concurrent initiators cannot each
        // see the other's row as absent. `ux_backfill_job_name` is the
        // real backstop; the ordering makes the common case
        // deterministic.
        //
        // Refuse rather than cancel. Deleting a field mid-backfill would
        // strand a `running` checkpoint that no worker can ever claim:
        // both sibling repositories recover the field id by INNER JOIN
        // on a substring of `job_name`, so the row becomes invisible to
        // the daemon while still counting as running on any dashboard.
        if ($this->renameCheckpoints->existsRunningForField($fieldId)) {
            throw new RenameInProgressException(
                "Field {$fieldId} has a rename in progress; it cannot be deleted"
                . ' until the rename backfill completes.'
            );
        }
        if ($this->retypeCheckpoints->existsRunningForField($fieldId)) {
            throw new RetypeInProgressException(
                "Field {$fieldId} has a retype in progress; it cannot be deleted"
                . ' until the retype backfill completes.'
            );
        }

        $now = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $oldSlotId = null;

        $this->pdo->beginTransaction();
        try {
            // 1. Sever. ADR 0009 requires the registry mapping to be
            //    severed immediately; from this commit on, every reader
            //    excludes the row and reads fall back to nothing.
            $update = $this->pdo->prepare(
                'UPDATE stardust_fields'
                . ' SET deleted_at = ?, is_filterable = 0, updated_at = ?'
                . ' WHERE id = ? AND deleted_at IS NULL'
            );
            $update->execute([$now, $now, $fieldId]);

            // Lost the race to a concurrent initiator that committed
            // between our SELECT and this UPDATE. Its transaction owns
            // the deletion; ours has changed nothing, so roll back and
            // report the same no-op the pre-check would have.
            if ($update->rowCount() === 0) {
                $this->pdo->rollBack();
                return false;
            }

            // 2. Hand any live slot to the Liberator. Nulls `field_id`
            //    first, which is what releases the RESTRICT foreign key
            //    for the final chunk's DELETE. Returns null for a
            //    JSON-only field, which under ADR 0034 is the common
            //    case and holds no slot at all.
            $oldSlotId = $this->tombstoner->tombstone($fieldId, $now);

            // 3. Clear terminal rename/retype checkpoints. Both are
            //    refused above while running, so these are `completed`
            //    or `failed` rows — harmless while the field exists,
            //    orphans the moment it does not.
            $this->deleteCheckpoints->deleteTerminalLifecycleRowsFor($fieldId);

            $bump = $this->pdo->prepare(
                'UPDATE stardust_schema_version'
                . ' SET version = version + 1, updated_at = ?'
                . ' WHERE id = 1'
            );
            $bump->execute([$now]);

            $this->deleteCheckpoints->insertOrReset($fieldId, $now);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->logger->info('field deletion started', [
            'event'                  => 'delete_started',
            'source'                 => 'registry',
            'tenant_id'              => $tenantId,
            'model_id'               => $field['model_id'],
            'field_id'               => $fieldId,
            'field_name'             => $field['name'],
            'was_filterable'         => $field['is_filterable'],
            'old_slot_assignment_id' => $oldSlotId,
        ]);

        return true;
    }

    /**
     * Null when the field does not exist, belongs to another tenant, or
     * is already being deleted — the three cases `initiate()` reports
     * as `false`.
     *
     * @return array{name: string, model_id: int, is_filterable: bool}|null
     */
    private function loadField(int $tenantId, int $fieldId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT f.name, f.model_id, f.is_filterable, f.deleted_at, m.tenant_id'
            . ' FROM stardust_fields f'
            . ' JOIN stardust_models m ON m.id = f.model_id'
            . ' WHERE f.id = ?'
        );
        $stmt->execute([$fieldId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }
        if ((int) $row['tenant_id'] !== $tenantId) {
            return null;
        }
        if ($row['deleted_at'] !== null) {
            return null;
        }

        return [
            'name'          => (string) $row['name'],
            'model_id'      => (int) $row['model_id'],
            'is_filterable' => (bool) $row['is_filterable'],
        ];
    }
}
