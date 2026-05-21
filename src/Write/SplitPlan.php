<?php

declare(strict_types=1);

namespace StarDust\Write;

/**
 * Output of {@see PayloadSplitter::split()}: the per-page slot write
 * plan + the list of field names that had no live slot.
 *
 * `slotWrites` is `pageId → (slotColumn → value)`. `EntryWriter`
 * issues one `INSERT … ON DUPLICATE KEY UPDATE` per page on the
 * `entry_slots_page_N` table named in `stardust_pages`.
 */
final class SplitPlan
{
    /**
     * @param array<int, array<string, mixed>> $slotWrites
     * @param list<string>                     $missingSlotFields
     */
    public function __construct(
        public readonly array $slotWrites,
        public readonly array $missingSlotFields,
    ) {
    }

    public function hasMissingSlotFields(): bool
    {
        return $this->missingSlotFields !== [];
    }
}
