<?php

declare(strict_types=1);

namespace StarDust\Liberator;

use InvalidArgumentException;
use PDO;

/**
 * Reads `stardust_slot_assignments` for rows in `status = 'tombstoned'`
 * and joins `stardust_pages.table_name` so the sweeper can target the
 * extension page directly.
 *
 * Ordering matches AC#10 of the Liberator blueprint:
 * `tombstoned_at ASC, page_id, slot_column` — oldest tombstone first,
 * deterministic tie-break for cross-restart parity.
 *
 * No `FOR UPDATE` claim: the Liberator is a strict singleton
 * (ADR 0009), so no other Liberator can race for these rows. Concurrent
 * registry mutations (e.g. an operator tombstoning new fields) just
 * surface in the next batch.
 *
 * SRP: this class only reads. Sweep mutations live on
 * {@see SlotSweeper}; cycle orchestration lives on {@see Liberator}.
 */
final class TombstonedSlotRepository
{
    /**
     * Same whitelist shape as {@see \StarDust\Page\EmptyTableGuard} —
     * the table name is interpolated into dynamic SQL in
     * {@see SlotSweeper}, so we validate it here before that ever
     * happens.
     */
    private const PAGE_TABLE_PATTERN = '/^entry_slots_page_[1-9]\d*$/';

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $batchSize,
    ) {
        if ($this->batchSize < 1) {
            throw new InvalidArgumentException('TombstonedSlotRepository batchSize must be >= 1.');
        }
    }

    /** @return list<TombstonedSlot> */
    public function loadBatch(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sa.id, sa.page_id, sa.slot_column, sa.sweep_cursor_id, p.table_name'
            . ' FROM stardust_slot_assignments sa'
            . ' JOIN stardust_pages p ON p.id = sa.page_id'
            . " WHERE sa.status = 'tombstoned'"
            . ' ORDER BY sa.tombstoned_at ASC, sa.page_id ASC, sa.slot_column ASC'
            . ' LIMIT ?'
        );
        $stmt->bindValue(1, $this->batchSize, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $tableName = (string) $row['table_name'];
            if (preg_match(self::PAGE_TABLE_PATTERN, $tableName) !== 1) {
                throw new InvalidArgumentException(
                    "TombstonedSlotRepository: '{$tableName}' is not a recognised entry_slots_page_X identifier."
                );
            }
            $out[] = new TombstonedSlot(
                slotAssignmentId: (int) $row['id'],
                pageId: (int) $row['page_id'],
                slotColumn: (string) $row['slot_column'],
                tableName: $tableName,
                sweepCursorId: $row['sweep_cursor_id'] === null ? null : (int) $row['sweep_cursor_id'],
            );
        }
        return $out;
    }
}
