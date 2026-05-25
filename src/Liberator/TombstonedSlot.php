<?php

declare(strict_types=1);

namespace StarDust\Liberator;

/**
 * Immutable view of one `stardust_slot_assignments` row claimed by the
 * Liberator for sweep. The repository hydrates the page's
 * `table_name` from `stardust_pages` so the sweeper does not need to
 * re-query it per chunk.
 *
 * `$sweepCursorId` is null when the slot has never been swept; the
 * sweeper treats null as `0` (`WHERE entry_id > 0`).
 */
final class TombstonedSlot
{
    public function __construct(
        public readonly int $slotAssignmentId,
        public readonly int $pageId,
        public readonly string $slotColumn,
        public readonly string $tableName,
        public readonly ?int $sweepCursorId,
    ) {
    }
}
