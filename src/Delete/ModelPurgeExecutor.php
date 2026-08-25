<?php

declare(strict_types=1);

namespace StarDust\Delete;

use PDO;

/**
 * Deletes one bounded chunk of a model's entries.
 *
 * **The caller owns the transaction.** The `stardust_sync_queue` delete
 * and the `entry_data` delete must commit together, or a crash between
 * them leaves queue rows whose entries are gone — the exact orphan the
 * queue delete exists to prevent.
 *
 * ## What one chunk actually costs
 *
 * More than the row count suggests, and this is why the purge has its
 * own chunk-size knob rather than sharing `reconcilerChunkSize`.
 * `entry_slots_page_X.entry_id` is a real foreign key with
 * `ON DELETE CASCADE`, so deleting N entries also deletes N × (pages the
 * model occupies) extension rows, with undo and redo for all of them —
 * the first time anything in the engine deletes from a page table rather
 * than nullifying a column in one.
 *
 * ## No `deleted_at IS NULL` predicate
 *
 * Deliberate, matching the rename, retype and field-delete drains:
 * entries an operator already soft-deleted are hard-deleted too. There is
 * nothing left for a soft-deleted row to be a soft-deleted instance *of*
 * once its model is gone.
 */
final class ModelPurgeExecutor
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function processChunk(ModelDeleteCheckpoint $checkpoint, int $chunkSize): ModelDeleteChunkResult
    {
        $ids = $this->fetchChunkIds(
            $checkpoint->tenantId,
            $checkpoint->modelId,
            $checkpoint->lastProcessedId,
            $chunkSize,
        );

        if ($ids === []) {
            return new ModelDeleteChunkResult(
                rowsScanned: 0,
                rowsDeleted: 0,
                syncRowsDeleted: 0,
                newCursor: $checkpoint->lastProcessedId,
                isFinalChunk: true,
            );
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        // Sync queue FIRST, deliberately. It is the statement most likely
        // to block — `SyncQueueWorkSource` holds PK locks on claimed rows
        // for a whole chunk transaction — and blocking here costs a
        // rollback of nothing, whereas blocking after the entry deletes
        // rolls back N `entry_data` X-locks plus their page cascades.
        //
        // Literal ids, never `IN (SELECT …)`: measured on MySQL 8.0.13,
        // the subquery form reverts to a full table scan and defeats
        // `ix_sync_queue_entry` entirely.
        $sync = $this->pdo->prepare(
            "DELETE FROM stardust_sync_queue WHERE entry_id IN ({$placeholders})"
        );
        $sync->execute($ids);
        $syncRowsDeleted = $sync->rowCount();

        $delete = $this->pdo->prepare(
            "DELETE FROM entry_data WHERE id IN ({$placeholders})"
        );
        $delete->execute($ids);
        $rowsDeleted = $delete->rowCount();

        return new ModelDeleteChunkResult(
            rowsScanned: count($ids),
            rowsDeleted: $rowsDeleted,
            syncRowsDeleted: $syncRowsDeleted,
            newCursor: (int) end($ids),
            isFinalChunk: count($ids) < $chunkSize,
        );
    }

    /**
     * The tenant predicate is the access path, not belt-and-braces.
     *
     * `stardust_models.id` is globally unique, so `WHERE model_id = ?`
     * selects exactly the same rows — but both `entry_data` secondary
     * indexes lead on `tenant_id`, and measured on MySQL 8.0.13 for a
     * model whose rows are not spread uniformly across the primary key
     * (which is every model created after the first), the tenant-scoped
     * form is a **covering** range scan of exactly that model's rows
     * while dropping the predicate collapses it to a primary-key range
     * scan of the whole table. Index skip scan exists in 8.0.13 and the
     * optimiser did not choose it. On 150 000 rows the difference was
     * 43×, per chunk, growing with the table.
     *
     * `LIMIT ?` requires the bindings to be explicitly typed as integers.
     *
     * @return list<int>
     */
    private function fetchChunkIds(int $tenantId, int $modelId, int $cursor, int $chunkSize): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM entry_data'
            . ' WHERE tenant_id = ? AND model_id = ? AND id > ?'
            . ' ORDER BY id ASC'
            . ' LIMIT ?'
        );
        $stmt->bindValue(1, $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(2, $modelId, PDO::PARAM_INT);
        $stmt->bindValue(3, $cursor, PDO::PARAM_INT);
        $stmt->bindValue(4, $chunkSize, PDO::PARAM_INT);
        $stmt->execute();

        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }
}
