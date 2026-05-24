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
 * registered fields that still have no live slot (capacity still
 * exhausted — caller rolls back and emits `capacity_wait`).
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
