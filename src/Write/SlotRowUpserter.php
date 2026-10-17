<?php

declare(strict_types=1);

namespace StarDust\Write;

use InvalidArgumentException;
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

    /**
     * Multi-row form of {@see self::upsert()}: one
     * `INSERT … VALUES (…),(…) ON DUPLICATE KEY UPDATE` for many entries
     * on the same page table and tenant.
     *
     * Every row carries the same column set, in `$columns` order, because a
     * multi-row VALUES list cannot vary its shape per row. A caller
     * whose entries touch different columns must pad with NULL, and
     * that is only safe when it can prove every row takes the INSERT
     * branch: on the UPDATE branch a padded NULL would overwrite a
     * stored value. {@see EntryChunkWriter} can prove it (its entry ids
     * were minted by the same transaction); a caller that cannot must
     * group rows by column set instead.
     *
     * `VALUES(col)` rather than the 8.0.19+ row-alias syntax, for the
     * same reason as {@see self::upsert()}, MariaDB has no row alias.
     * The caller owns statement sizing: this issues exactly one
     * statement however many rows it is handed.
     *
     * @param list<string>            $columns        slot columns, uniform across rows
     * @param array<int, list<mixed>> $rowsByEntryId  entry_id → values aligned to `$columns`
     */
    public function upsertMany(
        string $tableName,
        int $tenantId,
        array $columns,
        array $rowsByEntryId,
    ): void {
        if ($rowsByEntryId === []) {
            return;
        }

        $width = count($columns);
        $cols = array_merge(['entry_id', 'tenant_id'], $columns);
        $tuple = '(' . implode(',', array_fill(0, $width + 2, '?')) . ')';

        $params = [];
        foreach ($rowsByEntryId as $entryId => $values) {
            if (count($values) !== $width) {
                throw new InvalidArgumentException(
                    "SlotRowUpserter::upsertMany(): row for entry {$entryId} has "
                    . count($values) . " values for {$width} columns."
                );
            }
            $params[] = $entryId;
            $params[] = $tenantId;
            foreach ($values as $v) {
                $params[] = $v;
            }
        }

        $assignments = [];
        foreach ($columns as $c) {
            $assignments[] = "{$c} = VALUES({$c})";
        }
        $assignments[] = 'tenant_id = VALUES(tenant_id)';

        $sql = "INSERT INTO {$tableName} (" . implode(',', $cols) . ') VALUES '
            . implode(',', array_fill(0, count($rowsByEntryId), $tuple))
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }
}
