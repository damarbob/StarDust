<?php

declare(strict_types=1);

namespace StarDust\Write;

use PDO;
use StarDust\Exception\EntryDataMissingException;

/**
 * Backfills slot rows for an `entry_data` row that already exists.
 *
 * The Reconciler (Phase 5) calls this on each `stardust_sync_queue`
 * row it claims. Unlike {@see EntryWriter::writeWithinTransaction()}
 * — which INSERTs into `entry_data` and may enqueue — this executor
 * ONLY upserts slot rows. The `entry_data` row is the system of record
 * (ADR 0013) and stays untouched; the queue row is the Reconciler's
 * own concern.
 *
 * Returns a {@see BackfillResult} listing every slot UPSERTed plus any
 * registered filterable fields that still have no live slot. The caller
 * rolls the chunk back and then tries to reserve one for each
 * (`UnmappedFieldReserver`, the ADR 0007 path), falling through to
 * `capacity_wait` only when no indexed free slot exists.
 *
 * Phase 6b's retype-backfill will extend this same executor with the
 * ADR 0024 coercion-matrix path; today it leans on
 * {@see PayloadSplitter}'s first-write coercion policy.
 */
final class BackfillExecutor
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SlotRowUpserter $slotRowUpserter,
    ) {
    }

    /**
     * Backfill slot rows for a single entry. Caller owns the surrounding
     * transaction. Throws {@see EntryDataMissingException} when the
     * `entry_data` row has been deleted — the Reconciler turns that into
     * a DLQ row with `reason='missing_entry_data'`.
     */
    public function backfill(int $entryId): BackfillResult
    {
        $row = $this->fetchEntryData($entryId);
        if ($row === null) {
            throw new EntryDataMissingException(
                "entry_data id {$entryId} not found (likely deleted between enqueue and drain)."
            );
        }

        $fields = json_decode($row['fields'], true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($fields)) {
            throw new EntryDataMissingException(
                "entry_data id {$entryId} has a malformed `fields` JSON payload."
            );
        }

        $map = LiveSlotMap::loadFor($this->pdo, (int) $row['model_id']);

        // ADR 0038: a queued entry whose model is being purged. This is
        // a skip, not a guard — nothing is wrong, there is simply no slot
        // work worth doing on a row that is about to be deleted.
        //
        // It is not merely an optimisation. This method reads `entry_data`
        // with a plain non-locking SELECT and then UPSERTs into the page
        // tables; if the purge commits the row's deletion in between, that
        // UPSERT hits `fk_<page>_entry` with errno 1452 and
        // `SyncQueueWorkSource`'s catch-all files it as a `reason: 'other'`
        // dead-letter row. That is precisely the self-inflicted DLQ noise
        // the purge's in-transaction sync-queue delete exists to prevent,
        // arriving through a different door. Returning empty lets the
        // Reconciler treat the queue row as drained and delete it.
        //
        // A residual window remains: a chunk claimed before severance
        // committed carries a transaction snapshot that predates the
        // marker. It is bounded by one chunk and costs one DLQ row.
        // Closing it would need a lock on the write path, which is not
        // worth it.
        if ($map->isModelDeleting()) {
            return new BackfillResult(slotsWritten: [], stillUnmapped: []);
        }

        $plan = PayloadSplitter::split($map, $fields);

        $pageTableNames = $this->resolvePageTableNames(array_keys($plan->slotWrites));

        $slotsWritten = [];
        foreach ($plan->slotWrites as $pageId => $columnsToValues) {
            $tableName = $pageTableNames[$pageId];
            $this->slotRowUpserter->upsert(
                $tableName,
                $entryId,
                (int) $row['tenant_id'],
                $columnsToValues,
            );
            foreach ($columnsToValues as $slotColumn => $_value) {
                $slotsWritten[] = ['pageId' => $pageId, 'slotColumn' => $slotColumn];
            }
        }

        return new BackfillResult(
            slotsWritten: $slotsWritten,
            stillUnmapped: $plan->missingSlotFields,
        );
    }

    /**
     * @return array{tenant_id: int|string, model_id: int|string, fields: string}|null
     */
    private function fetchEntryData(int $entryId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT tenant_id, model_id, fields FROM entry_data WHERE id = ?'
        );
        $stmt->execute([$entryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @param list<int> $pageIds
     * @return array<int, string> pageId → table_name
     */
    private function resolvePageTableNames(array $pageIds): array
    {
        if ($pageIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, table_name FROM stardust_pages WHERE id IN ({$placeholders})"
        );
        $stmt->execute($pageIds);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['id']] = (string) $row['table_name'];
        }
        return $out;
    }
}
