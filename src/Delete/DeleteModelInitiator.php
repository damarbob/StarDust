<?php

declare(strict_types=1);

namespace StarDust\Delete;

use DateTimeZone;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Exception\FieldDeletionInProgressException;
use StarDust\Exception\RenameInProgressException;
use StarDust\Exception\RetypeInProgressException;
use StarDust\Rename\RenameCheckpointRepository;
use StarDust\Retype\RetypeCheckpointRepository;
use StarDust\Slot\LiveSlotTombstoner;
use StarDust\Support\LikePattern;
use StarDust\Support\UuidV4;
use Throwable;

/**
 * The ADR 0038 model-deletion registry transaction.
 *
 * Structurally {@see DeleteFieldInitiator} widened to every field of a
 * model, plus one new marker on `stardust_models`. It severs the model
 * from every read, write, filter, introspection and export surface in one
 * commit, then leaves the Reconciler to destroy the entries and drop the
 * registry row on the final chunk.
 *
 * ## Why the field markers are set too
 *
 * Marking every field is what makes this cheap. Every existing
 * field-severance guard in the engine — the read snapshot, the write-path
 * canonicalisation, both `SchemaReader` queries, both `SchemaBuilder`
 * get-or-create guards, both CSV header resolvers, the Watcher's demand
 * reader, the ADR 0007 exhaustion reserver, the slot reserver, the search
 * driver, and the rename/retype/delete initiators — fires with **zero new
 * predicates**.
 *
 * The model marker is not redundant with them. A guard built only from
 * field markers reads "no field of this model is live", which is true of
 * every brand-new empty model; and a model registered with no fields at
 * all is legal, so its severance would mark nothing and the purge's claim
 * query would have nothing to join through.
 *
 * ## Why `is_filterable` is cleared in the same UPDATE
 *
 * ADR 0037's reason, N fields wide: a field with `is_filterable = 1` and
 * no live slot **is demand**. `PendingDemandReader` would have the Watcher
 * provision a page for a model being destroyed, and
 * `UnmappedFieldReserver` would reserve it a fresh slot on the ADR 0007
 * exhaustion path — re-taking `fk_slot_assignments_field`, so the purge's
 * final `DELETE FROM stardust_models` fails errno 1451, permanently,
 * because nothing retries it.
 *
 * ## Refuse, do not cancel
 *
 * A deletion is refused while **any** field of the model has a rename,
 * retype or field-delete checkpoint `running`, checked in that fixed
 * order. Cascading a field row away would strand a `running` checkpoint
 * no worker can claim and no dashboard can explain — and a model deletion
 * would do it N times.
 */
final class DeleteModelInitiator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly LiveSlotTombstoner $tombstoner,
        private readonly ModelDeleteCheckpointRepository $modelCheckpoints,
    ) {
    }

    /**
     * Severs the model and opens its purge checkpoint.
     *
     * Returns `false` rather than throwing when there is nothing to do —
     * the model does not exist, belongs to another tenant, or is already
     * being deleted. The three are deliberately indistinguishable, mirroring
     * `deleteField()` and `deleteEntry()` and the tenant-isolation rule
     * that a caller must not be able to probe another tenant's model ids.
     * The cost is that a typo in a model id is silent.
     *
     * @throws RenameInProgressException       a field of this model is mid-rename
     * @throws RetypeInProgressException       a field of this model is mid-retype
     * @throws FieldDeletionInProgressException a field of this model is mid-delete
     */
    public function initiate(int $tenantId, int $modelId): bool
    {
        $model = $this->loadModel($tenantId, $modelId);
        if ($model === null) {
            return false;
        }

        // Guards run before the transaction opens, so a refused deletion
        // takes no locks. Fixed order — rename, retype, delete — so two
        // concurrent initiators cannot each see the other's row as absent.
        if ($this->anyFieldHasRunning($modelId, RenameCheckpointRepository::JOB_NAME_PREFIX)) {
            throw new RenameInProgressException(
                "Model {$modelId} has a field rename in flight; it cannot be deleted"
                . ' until the rename backfill completes.'
            );
        }
        if ($this->anyFieldHasRunning($modelId, RetypeCheckpointRepository::JOB_NAME_PREFIX)) {
            throw new RetypeInProgressException(
                "Model {$modelId} has a field retype in flight; it cannot be deleted"
                . ' until the retype backfill completes.'
            );
        }
        if ($this->anyFieldHasRunning($modelId, DeleteCheckpointRepository::JOB_NAME_PREFIX)) {
            throw new FieldDeletionInProgressException(
                "Model {$modelId} has a field deletion in flight; it cannot be deleted"
                . ' until that purge completes.'
            );
        }

        $now = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        // The lifecycle id, persisted onto the checkpoint below so the
        // purge can emit `model_delete_complete` under it.
        $correlationId = UuidV4::generate();

        $this->pdo->beginTransaction();

        try {
            // 1. The model marker. A zero row count means another caller
            //    won the race between loadModel() and here.
            $mark = $this->pdo->prepare(
                'UPDATE stardust_models SET deleted_at = ? WHERE id = ? AND deleted_at IS NULL'
            );
            $mark->execute([$now, $modelId]);
            if ($mark->rowCount() === 0) {
                $this->pdo->rollBack();
                return false;
            }

            // 2. Field ids, locked, before anything mutates them. Needed
            //    for the tombstone loop and the checkpoint cleanup, and
            //    reported on the event as `field_count`.
            $fieldIds = $this->lockFieldIds($modelId);

            // 3. Every field, one UPDATE. `is_filterable = 0` is
            //    load-bearing — see the class docblock.
            $severFields = $this->pdo->prepare(
                'UPDATE stardust_fields'
                . ' SET deleted_at = ?, is_filterable = 0, updated_at = ?'
                . ' WHERE model_id = ? AND deleted_at IS NULL'
            );
            $severFields->execute([$now, $now, $modelId]);

            // 4. Tombstone each field's live slot. Looping the shared
            //    tombstoner rather than re-deriving a set-based two-step:
            //    it is twenty lines of index- and FK-defending SQL, and
            //    duplicating it is exactly what drifts. Bounded by field
            //    count — tens of rows, not millions.
            $tombstoned = [];
            foreach ($fieldIds as $fieldId) {
                $slotId = $this->tombstoner->tombstone($fieldId, $now);
                if ($slotId !== null) {
                    $tombstoned[] = $slotId;
                }
            }

            // 5. Every field-scoped checkpoint, all three namespaces.
            $this->modelCheckpoints->deleteLifecycleRowsForFields($fieldIds);

            // 6. One version bump for the whole tuple, which is what makes
            //    cached snapshots notice the model went dark.
            $bump = $this->pdo->prepare(
                'UPDATE stardust_schema_version'
                . ' SET version = version + 1, updated_at = ?'
                . ' WHERE id = 1'
            );
            $bump->execute([$now]);

            // 7. Open the purge.
            $this->modelCheckpoints->insertOrReset($modelId, $now, $correlationId);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // Post-commit: events describe committed state only.
        $this->logger->info('model deletion started', [
            'event'            => 'model_delete_started',
            'source'           => 'registry',
            'correlation_id'   => $correlationId,
            'tenant_id'        => $tenantId,
            'model_id'         => $modelId,
            'model_name'       => $model['name'],
            'field_count'      => count($fieldIds),
            'slots_tombstoned' => count($tombstoned),
        ]);

        return true;
    }

    /**
     * Tenant-scoped in the WHERE rather than fetched and compared, so a
     * model belonging to another tenant is indistinguishable from one
     * that does not exist. A model already being deleted returns null
     * too — that is the idempotent `false`.
     *
     * @return array{name: string}|null
     */
    private function loadModel(int $tenantId, int $modelId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT name FROM stardust_models'
            . ' WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$modelId, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return ['name' => (string) $row['name']];
    }

    /**
     * Is any field of this model carrying a `running` checkpoint in the
     * given namespace?
     *
     * The `LIKE` is escaped for the reason
     * {@see ModelDeleteCheckpointRepository} documents, and the join to
     * `stardust_fields` is what scopes it to this model — an
     * operator-named job matching the wildcard shape has no field row and
     * cannot pass it.
     */
    private function anyFieldHasRunning(int $modelId, string $prefix): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM backfill_checkpoints c'
            . ' JOIN stardust_fields f'
            . '   ON f.id = CAST(SUBSTRING(c.job_name, '
                . (strlen($prefix) + 1) . ') AS UNSIGNED)'
            . " WHERE c.status = 'running'"
            . "   AND c.job_name LIKE ? ESCAPE '\\\\'"
            . '   AND f.model_id = ?'
            . ' LIMIT 1'
        );
        $stmt->execute([LikePattern::escapedPrefix($prefix), $modelId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * `FOR UPDATE` so a concurrent field-lifecycle initiator cannot slip
     * a row in between the guards above and the severance below.
     *
     * @return list<int>
     */
    private function lockFieldIds(int $modelId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM stardust_fields WHERE model_id = ? ORDER BY id FOR UPDATE'
        );
        $stmt->execute([$modelId]);

        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }
}
