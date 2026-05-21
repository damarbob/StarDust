<?php

declare(strict_types=1);

namespace StarDust\Write;

/**
 * Result of {@see EntryWriter::write()}.
 *
 * `entryId` is the newly inserted `entry_data.id` (or the previously
 * inserted id for an idempotent re-submission once the engine grows
 * that path).
 *
 * `enqueuedForBackfill` is `true` iff the entry triggered the ADR 0007
 * exhaustion fallback — at least one field in the payload lacked a
 * live slot and the entry now sits in `stardust_sync_queue` waiting
 * for the Reconciler.
 *
 * `slotsWritten` records the (pageId, slotColumn) pairs that actually
 * received a value, so callers can plug into observability without
 * re-querying.
 */
final class EntryWriteResult
{
    /**
     * @param list<array{pageId: int, slotColumn: string}> $slotsWritten
     */
    public function __construct(
        public readonly int $entryId,
        public readonly bool $enqueuedForBackfill,
        public readonly array $slotsWritten,
    ) {
    }
}
