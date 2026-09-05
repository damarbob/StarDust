<?php

declare(strict_types=1);

namespace StarDust\Watcher;

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
 *
 * ## The floor is read off real pages, not off the page layout (ADR 0044)
 *
 * Until ADR 0044 the minimum divided by the *layout* capacity —
 * 25/15/10/10, counted from `PageProvisioner` — which was a page's real
 * capacity only while every page carried all sixty columns and all sixty
 * were claimable. ADR 0034 ended the second half and ADR 0043 the first,
 * so pages are heterogeneous by design and there is no page-independent
 * capacity number left to divide by. ADR 0031 predicted this in its own
 * Consequences: *"if a future ADR introduces heterogeneous page layouts,
 * the min-pages formula must be revised in lockstep, or the metric will
 * misreport excess."*
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
     * @param list<int>                      $pageIds      `page_id` of each live slot (duplicates expected)
     * @param list<string>                   $slotColumns  `slot_column` of each live slot, same cardinality
     * @param array<int, array<string, int>> $freeByPage   pageId ⇒ family ⇒ indexed free slot count
     */
    public static function fromLiveSlots(
        int $tenantId,
        int $modelId,
        array $pageIds,
        array $slotColumns,
        array $freeByPage,
    ): self {
        $countsByFamily = [];
        $ownByPage = [];

        foreach ($slotColumns as $i => $slotColumn) {
            $family = self::familyOf($slotColumn);
            if ($family === null) {
                // Unreachable for engine-built pages, whose columns are
                // always `i_{str|int|num|dt}_NN`. Skipped rather than
                // thrown: an advisory sampler must never take the
                // Watcher down over one unrecognised registry row.
                continue;
            }
            $countsByFamily[$family] = ($countsByFamily[$family] ?? 0) + 1;

            $pageId = $pageIds[$i];
            $ownByPage[$pageId][$family] = ($ownByPage[$pageId][$family] ?? 0) + 1;
        }

        // The candidate set is the pages the model occupies, matching
        // ADR 0033's v1 restriction that compaction consolidates and
        // never migrates a model onto a page it has never touched. Free
        // capacity elsewhere is not reachable, so counting it would
        // report a floor no operation can deliver.
        $hostable = self::hostableByPage(
            array_intersect_key($freeByPage, $ownByPage),
            $ownByPage,
        );

        return new self(
            tenantId: $tenantId,
            modelId: $modelId,
            pagesOccupied: count(array_unique($pageIds)),
            theoreticalMinPages: self::theoreticalMinPages($countsByFamily, $hostable),
            liveSlotCount: count($slotColumns),
        );
    }

    /**
     * What each candidate page could hold of each family, for *this*
     * model: the slots it already holds there, plus the free slots it
     * could still claim.
     *
     * Shared so the sampler and the compaction planner cannot build the
     * input differently — the same reason the formula below is shared.
     * Note the asymmetry that makes the arithmetic correct:
     * {@see \StarDust\Compaction\CompactionPlanner} computes the *floor*
     * from this, but assigns relocations against `free` alone. A slot
     * this model already holds is capacity for staying put, never
     * capacity for taking a move.
     *
     * @param  array<int, array<string, int>> $freeByPage pageId ⇒ family ⇒ indexed free count
     * @param  array<int, array<string, int>> $ownByPage  pageId ⇒ family ⇒ this model's live slots
     * @return array<int, array<string, int>> pageId ⇒ family ⇒ hostable count
     */
    public static function hostableByPage(array $freeByPage, array $ownByPage): array
    {
        $hostable = [];

        foreach ([$freeByPage, $ownByPage] as $source) {
            foreach ($source as $pageId => $byFamily) {
                foreach ($byFamily as $family => $count) {
                    $hostable[$pageId][$family] = ($hostable[$pageId][$family] ?? 0) + $count;
                }
            }
        }

        return $hostable;
    }

    /**
     * The fewest of the candidate pages that could hold these filterable
     * fields.
     *
     * A single page provides all four families simultaneously, so the
     * minimum is governed by the most-constrained family alone — never
     * the sum, which is the classic error and would make every
     * multi-family model look permanently fragmented. Per family, take
     * the roomiest pages first and count how many it takes to cover the
     * field count:
     *
     *     m[f] = fewest pages whose hostable capacity for f covers count[f]
     *     minimum = max over families f of m[f]
     *
     * Where every page carries the same number of columns this reduces
     * exactly to `ceil(count[f] / capacity[f])`, so ADR 0031's worked
     * example still holds: 30 string + 5 int over 25-column pages ⇒
     * `max(2, 1)` ⇒ 2.
     *
     * Taking each family's roomiest pages *independently* makes this a
     * lower bound rather than an exact packing — two families may want
     * different pages. That is the safe direction and is what
     * "theoretical minimum" means; on engine-provisioned pages it is also
     * exact, because ADR 0042 headroom gives every page columns in every
     * family, so the per-family choices coincide.
     *
     * @param array<string, int>            $countsByFamily family ⇒ filterable field count
     * @param array<int, array<string,int>> $hostableByPage pageId ⇒ family ⇒ hostable count,
     *                                                      from {@see self::hostableByPage()}
     */
    public static function theoreticalMinPages(array $countsByFamily, array $hostableByPage): int
    {
        $min = 0;
        foreach (self::FAMILIES as $family) {
            $count = $countsByFamily[$family] ?? 0;
            if ($count <= 0) {
                continue;
            }
            $min = max($min, self::pagesToCover($count, $family, $hostableByPage));
        }

        return $min;
    }

    /**
     * Fewest pages whose capacity for one family covers `$count`.
     *
     * Falls back to the number of candidate pages when they cannot cover
     * it at all. That is unreachable from either caller — a model's own
     * live slots are themselves hostable capacity on the pages it
     * occupies, so the candidates always cover the counts — but it keeps
     * the result a page count rather than an infinity if a caller ever
     * passes a narrower candidate set.
     *
     * @param array<int, array<string, int>> $hostableByPage
     */
    private static function pagesToCover(int $count, string $family, array $hostableByPage): int
    {
        $capacities = [];
        foreach ($hostableByPage as $byFamily) {
            $capacity = $byFamily[$family] ?? 0;
            if ($capacity > 0) {
                $capacities[] = $capacity;
            }
        }

        rsort($capacities);

        $covered = 0;
        foreach ($capacities as $i => $capacity) {
            $covered += $capacity;
            if ($covered >= $count) {
                return $i + 1;
            }
        }

        return max(1, count($hostableByPage));
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
