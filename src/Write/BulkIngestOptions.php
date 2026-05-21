<?php

declare(strict_types=1);

namespace StarDust\Write;

use InvalidArgumentException;

/**
 * Tuning parameters for {@see BulkIngestor::ingest()}.
 *
 * Defaults match ADR 0011: chunks of 500 entities, no inter-chunk
 * delay. The synchronous threshold (1 000 entities) is intentionally
 * not tunable — it is enforced by the BulkIngestor itself.
 */
final class BulkIngestOptions
{
    public function __construct(
        public readonly int $chunkSize = 500,
        public readonly int $interChunkDelayMicros = 0,
    ) {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException(
                "BulkIngestOptions: chunkSize must be >= 1; got {$chunkSize}."
            );
        }
        if ($interChunkDelayMicros < 0) {
            throw new InvalidArgumentException(
                'BulkIngestOptions: interChunkDelayMicros must be >= 0; got '
                . $interChunkDelayMicros . '.'
            );
        }
    }
}
