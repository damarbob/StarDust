<?php

declare(strict_types=1);

namespace StarDust\Write;

/**
 * Outcome of one {@see BackfillExecutor::backfill()} call.
 *
 * `slotsWritten` lists each `(pageId, slotColumn)` pair the backfill
 * UPSERTed; `stillUnmapped` carries the registered field names that
 * STILL have no live slot after the attempt. A non-empty
 * `stillUnmapped` is the Reconciler's signal that this entry needs to
 * remain enqueued — capacity is still exhausted for one or more of its
 * fields — and the Reconciler emits a `capacity_wait` event and rolls
 * back the chunk transaction so the queue rows stay claimable.
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
