<?php

declare(strict_types=1);

namespace StarDust\Write;

/**
 * Outcome of one {@see BackfillExecutor::backfill()} call.
 *
 * `slotsWritten` lists each `(pageId, slotColumn)` pair the backfill
 * UPSERTed; `stillUnmapped` carries the registered *filterable* field
 * names that STILL have no live slot after the attempt (ADR 0034 scopes
 * the enqueue to those, so a JSON-only field never appears here).
 *
 * A non-empty `stillUnmapped` is the Reconciler's signal that this entry
 * must stay enqueued: it rolls the chunk transaction back so the queue
 * rows stay claimable, and then feeds these names to
 * `UnmappedFieldReserver` to claim the slots they are waiting on
 * (ADR 0007). Only when that reserves nothing does the tick emit
 * `capacity_wait` and hand off to the Watcher.
 */
final class BackfillResult
{
    /**
     * @param list<array{pageId: int, slotColumn: string}> $slotsWritten
     * @param list<string>                                 $stillUnmapped
     */
    public function __construct(
        public readonly array $slotsWritten,
        public readonly array $stillUnmapped,
    ) {
    }

    public function hasStillUnmapped(): bool
    {
        return $this->stillUnmapped !== [];
    }
}
