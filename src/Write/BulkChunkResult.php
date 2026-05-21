<?php

declare(strict_types=1);

namespace StarDust\Write;

/**
 * Per-chunk outcome in a {@see BulkIngestResult}. ADR 0011 calls this
 * the "per-chunk manifest" surface.
 *
 * `outcome` is `committed` if every entity in the chunk's transaction
 * was inserted and the transaction committed, or `rolled_back` if any
 * entity failed and the chunk's transaction was rolled back. There is
 * no partial-chunk state.
 *
 * `entryIds` lists the `entry_data.id` values for committed chunks,
 * in payload order. It is empty for rolled-back chunks.
 *
 * `failureReason` carries the rolled-back chunk's exception class +
 * message; null on success.
 */
final class BulkChunkResult
{
    public const OUTCOME_COMMITTED   = 'committed';
    public const OUTCOME_ROLLED_BACK = 'rolled_back';

    /**
     * @param list<int> $entryIds
     */
    public function __construct(
        public readonly int $chunkIndex,
        public readonly int $chunkSize,
        public readonly string $outcome,
        public readonly array $entryIds,
        public readonly ?string $failureReason = null,
    ) {
    }
}
