<?php

declare(strict_types=1);

namespace StarDust\Watcher;

use StarDust\Page\PageProvisioner;

/**
 * One ADR 0031 spread measurement for a single `(tenant_id, model_id)`,
 * plus the reusable `theoretical_min_pages` arithmetic.
 *
 * **Spread** is the number of distinct extension pages a model's live
 * filterable slots occupy. `SqlFilterCompiler` emits one
 * `INNER JOIN entry_slots_page_N` per *distinct page* a filter touches,
 * so a model scattered over three pages pays two extra index range-scans
 * versus the same model packed onto one. {@see self::excessPages()} is
 * the count of those avoidable joins, and `0` is optimal packing.
 *
 * The statics are public because ADR 0033's compaction planner needs the
 * same minimum-pages formula to choose a target page set, and a second
 * implementation would be free to disagree with this one about whether a
 * compaction actually achieved anything.
 */
final class SpreadSample
{
    /** Slot families, in `stardust_slot_assignments.slot_type` spelling. */
    public const FAMILIES = ['str', 'int', 'num', 'dt'];

    public function __construct(
        public readonly int $tenantId,
        public readonly int $modelId,
        public readonly int $pagesOccupied,
        public readonly int $theoreticalMinPages,
        public readonly int $liveSlotCount,
    ) {
    }

    /**
     * Avoidable joins: `pages_occupied - theoretical_min_pages`.
     *
     * Derived rather than stored so the two inputs can never disagree
     * with it. This is the number operators act on — `pages_occupied`
     * alone would flag a model that genuinely needs three pages
     * identically to one wastefully occupying three where one suffices.
     */
    public function excessPages(): int
    {
        return $this->pagesOccupied - $this->theoreticalMinPages;
    }

    /**
     * Build a sample from one model's live filterable slot rows.
     *
     * @param list<int>    $pageIds     `page_id` of each live slot (duplicates expected)
     * @param list<string> $slotColumns `slot_column` of each live slot, same cardinality
     */
    public static function fromLiveSlots(
        int $tenantId,
        int $modelId,
        array $pageIds,
        array $slotColumns,
    ): self {
        $countsByFamily = [];
        foreach ($slotColumns as $slotColumn) {
            $family = self::familyOf($slotColumn);
            if ($family === null) {
                // Unreachable for engine-built pages, whose columns are
                // always `i_{str|int|num|dt}_NN`. Skipped rather than
                // thrown: an advisory sampler must never take the
                // Watcher down over one unrecognised registry row.
                continue;
            }
            $countsByFamily[$family] = ($countsByFamily[$family] ?? 0) + 1;
        }

        return new self(
            tenantId: $tenantId,
            modelId: $modelId,
            pagesOccupied: count(array_unique($pageIds)),
            theoreticalMinPages: self::theoreticalMinPages($countsByFamily),
            liveSlotCount: count($slotColumns),
        );
    }

    /**
     * The fewest pages that could hold these filterable fields.
     *
     * A single page provides all four families simultaneously, so the
     * minimum is governed by the most-constrained family alone:
     *
     *     max over families f of ceil( count[f] / capacity[f] )
     *
     * 30 string + 5 int filterable fields ⇒ `max(ceil(30/25), ceil(5/15))`
     * ⇒ `max(2, 1)` ⇒ 2. Summing the per-family minima instead would be
     * the classic error — it would report 3 for that model and make every
     * multi-family model look permanently fragmented.
     *
     * @param array<string, int> $countsByFamily family ⇒ filterable field count
     */
    public static function theoreticalMinPages(array $countsByFamily): int
    {
        $min = 0;
        foreach (self::FAMILIES as $family) {
            $count = $countsByFamily[$family] ?? 0;
            if ($count <= 0) {
                continue;
            }
            $min = max($min, (int) ceil($count / self::capacityFor($family)));
        }

        return $min;
    }

    /**
     * Per-page capacity of one slot family.
     *
     * Counted from {@see PageProvisioner::slotColumnsForType()} rather
     * than restating 25/15/10/10, because capacity *is* "how many columns
     * of this family a page carries" — deriving it means the formula
     * cannot silently drift from the DDL if a page layout ever changes.
     */
    public static function capacityFor(string $family): int
    {
        return count(PageProvisioner::slotColumnsForType($family));
    }

    /** `i_str_01` ⇒ `str`; null for anything not an engine slot column. */
    public static function familyOf(string $slotColumn): ?string
    {
        foreach (self::FAMILIES as $family) {
            if (str_starts_with($slotColumn, "i_{$family}_")) {
                return $family;
            }
        }

        return null;
    }
}
