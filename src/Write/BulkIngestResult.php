<?php

declare(strict_types=1);

namespace StarDust\Write;

/**
 * Aggregate result of {@see BulkIngestor::ingest()}.
 *
 * `chunks` is the per-chunk manifest in order. `entriesCommitted` is
 * the sum of committed chunks' `entryIds` counts; callers can use it
 * to short-circuit retry logic without re-walking the manifest.
 */
final class BulkIngestResult
{
    /**
     * @param list<BulkChunkResult> $chunks
     */
    public function __construct(
        public readonly array $chunks,
        public readonly int $entriesCommitted,
    ) {
    }
}
