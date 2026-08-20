<?php

declare(strict_types=1);

namespace StarDust\Compaction;

/**
 * The result of planning a compaction: which pages the model should end
 * up on, which fields have to move to get there, and how many were
 * already in place.
 *
 * Produced by {@see CompactionPlanner::plan()} as a **pure registry
 * computation** — building a plan reads nothing from the data plane,
 * holds no locks, and mutates nothing. `--dry-run` prints one of these
 * and stops.
 *
 * `pagesBefore` and `targetPageIds` are what the `compaction_planned`
 * and `compaction_complete` events report, and the difference between
 * `pagesBefore` and the final ADR 0031 spread sample is the operation's
 * built-in success check.
 */
final class CompactionPlan
{
    /**
     * @param list<int>              $targetPageIds the minimal page set, ascending
     * @param list<FieldRelocation>  $relocations   genuine moves only; no-ops are excluded
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $modelId,
        public readonly int $pagesBefore,
        public readonly int $theoreticalMinPages,
        public readonly array $targetPageIds,
        public readonly array $relocations,
        public readonly int $noopCount,
    ) {
    }

    public function isNoop(): bool
    {
        return $this->relocations === [];
    }

    public function relocationCount(): int
    {
        return count($this->relocations);
    }

    /**
     * Pages the model would occupy afterwards. Equal to the target set
     * by construction — every field either already sits on a target page
     * or is being moved onto one.
     */
    public function pagesAfter(): int
    {
        return count($this->targetPageIds);
    }

    /**
     * Avoidable joins the compaction removes. Zero when the model is
     * already at its family-ceiling floor, which is the case a plan
     * correctly declines to act on.
     */
    public function excessPagesRemoved(): int
    {
        return $this->pagesBefore - $this->pagesAfter();
    }
}
