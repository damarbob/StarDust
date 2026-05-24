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
 */
final class CapacitySnapshot
{
    /**
     * @param array<string, int> $freeByFamily  slot_type → free count
     * @param array<string, int> $totalByFamily slot_type → row count (all statuses)
     */
    public function __construct(
        public readonly array $freeByFamily,
        public readonly array $totalByFamily,
        public readonly int $totalFree,
        public readonly int $totalSlots,
    ) {
    }

    public function globalFreeRatio(): float
    {
        if ($this->totalSlots === 0) {
            return 0.0;
        }
        return $this->totalFree / $this->totalSlots;
    }
}
