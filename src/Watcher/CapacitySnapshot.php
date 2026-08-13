<?php

declare(strict_types=1);

namespace StarDust\Watcher;

/**
 * Per-family slot counts plus the derived global free-ratio that the
 * Watcher compares against `Config::$watcherCapacityThreshold`.
 *
 * `totalSlots` is the sum across all slot states (`free | assigned |
 * tombstoned | backfilling | ready`) — i.e. every row in
 * `stardust_slot_assignments`. `freeSlots` counts the `free` state only.
 * On a freshly-bootstrapped database with no pages, both are `0` and
 * `globalFreeRatio()` returns `0.0` — i.e. "definitely below
 * threshold", which is the right answer (the Watcher should provision
 * page 1).
 *
 * `pagesInspected` is the count of distinct pages backing the slot
 * inventory (`COUNT(DISTINCT page_id)` over `stardust_slot_assignments`)
 * — i.e. the pages the capacity scan actually read this cycle. It is
 * `0` on a fresh database with no provisioned pages.
 *
 * The `indexed*` maps narrow the same counts to slot columns that
 * actually carry an index on their page. Every reservation path with a
 * production caller demands an indexed slot, so an unindexed free slot
 * is inventory nobody can claim — counting it as capacity is what let a
 * page full of unindexed columns mask a real shortage. The
 * {@see ProvisioningPlanner} reads these; this DTO only carries them.
 */
final class CapacitySnapshot
{
    /**
     * @param array<string, int> $freeByFamily         slot_type → free count
     * @param array<string, int> $totalByFamily        slot_type → row count (all statuses)
     * @param array<string, int> $indexedFreeByFamily  slot_type → free count, indexed columns only
     * @param array<string, int> $indexedTotalByFamily slot_type → row count, indexed columns only
     */
    public function __construct(
        public readonly array $freeByFamily,
        public readonly array $totalByFamily,
        public readonly int $totalFree,
        public readonly int $totalSlots,
        public readonly int $pagesInspected,
        public readonly array $indexedFreeByFamily,
        public readonly array $indexedTotalByFamily,
    ) {
    }

    /** Free slots of `$family` that a `requireIndexed` reservation could actually claim. */
    public function indexedFreeFor(string $family): int
    {
        return $this->indexedFreeByFamily[$family] ?? 0;
    }

    /** Slots of `$family` on indexed columns, whatever their status. */
    public function indexedTotalFor(string $family): int
    {
        return $this->indexedTotalByFamily[$family] ?? 0;
    }

    public function globalFreeRatio(): float
    {
        if ($this->totalSlots === 0) {
            return 0.0;
        }
        return $this->totalFree / $this->totalSlots;
    }
}
