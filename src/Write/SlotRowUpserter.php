<?php

declare(strict_types=1);

namespace StarDust\Write;

use PDO;

/**
 * INSERT … ON DUPLICATE KEY UPDATE into a single `entry_slots_page_N`
 * row, keyed by `entry_id` (Architecture Blueprint §5).
 *
 * Extracted from {@see EntryWriter} so Phase 5's
 * {@see BackfillExecutor} can perform the same UPSERT during sync-queue
 * drain without going through `EntryWriter::writeWithinTransaction()`
 * (which would also re-INSERT into `entry_data`).
 *
 * Column names come from `stardust_slot_assignments.slot_column` whose
 * universe is `i_{str|int|num|dt}_NN` and is populated only by
 * {@see \StarDust\Page\PageProvisioner}. Interpolating those into the
 * SQL string is safe; every user-supplied value still flows through
 * prepared-statement parameter binding.
 */
final class SlotRowUpserter
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $columnsToValues slot_column → coerced value
     */
    public function upsert(
        string $tableName,
        int $entryId,
        int $tenantId,
        array $columnsToValues,
    ): void {
        $columns = array_keys($columnsToValues);
        $cols = array_merge(['entry_id', 'tenant_id'], $columns);

        $placeholders = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';

        $assignments = [];
        foreach ($columns as $c) {
            $assignments[] = "{$c} = VALUES({$c})";
        }
        // Keep tenant_id in sync on idempotent re-submission — the
        // partitioned read path relies on it.
        $assignments[] = 'tenant_id = VALUES(tenant_id)';

        $sql = "INSERT INTO {$tableName} (" . implode(',', $cols) . ') VALUES '
            . $placeholders
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$entryId, $tenantId], array_values($columnsToValues)));
    }
}
