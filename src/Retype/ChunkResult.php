<?php

declare(strict_types=1);

namespace StarDust\Retype;

/**
 * Output of one {@see RetypeBackfillExecutor::processChunk()} call.
 *
 *   - `rowsProcessed` — entries seen this chunk (informational).
 *   - `newCursor` — the highest `entry_data.id` processed; the work
 *     source advances the `backfill_checkpoints.last_processed_id`
 *     to this in the same transaction.
 *   - `nullEvents` — per-entry attempted-but-failed coercions. The
 *     work source emits one `coercion_null` event per element after
 *     the chunk commits.
 *   - `isFinalChunk` — true when `rowsProcessed < chunkSize`, i.e.
 *     the partition has no more candidates. The work source promotes
 *     the slot `backfilling → ready` in the same transaction.
 */
final class ChunkResult
{
    /**
     * @param list<CoercionNullEvent> $nullEvents
     */
    public function __construct(
        public readonly int $rowsProcessed,
        public readonly int $newCursor,
        public readonly array $nullEvents,
        public readonly bool $isFinalChunk,
    ) {
    }
}
