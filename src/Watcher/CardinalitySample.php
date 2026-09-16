<?php

declare(strict_types=1);

namespace StarDust\Watcher;

/**
 * One ADR 0019 cardinality observation: a `(tenant, live slot)` pair and
 * the aggregate measured over it.
 *
 * Returned only by {@see CardinalitySampler::report()}, which backs
 * `bin/stardust cardinality:report` — the periodic and post-backfill
 * triggers emit events and return nothing, exactly as
 * {@see SpreadSample} works for the spread advisory.
 *
 * `selectivity` is `distinctValues / rowCount`, or `0.0` for an empty
 * partition. It is carried rather than recomputed so the printed table
 * and the emitted event can never disagree.
 */
final class CardinalitySample
{
    public function __construct(
        public readonly int $slotAssignmentId,
        public readonly ?int $fieldId,
        public readonly int $tenantId,
        public readonly int $pageId,
        public readonly string $slotColumn,
        public readonly int $rowCount,
        public readonly int $distinctValues,
        public readonly float $selectivity,
    ) {
    }
}
