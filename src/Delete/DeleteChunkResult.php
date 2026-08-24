<?php

declare(strict_types=1);

namespace StarDust\Delete;

/**
 * Outcome of one bounded `entry_data` purge chunk.
 *
 * `rowsPurged < rowsScanned` is normal rather than a symptom: the
 * executor's `JSON_CONTAINS_PATH` guard skips rows that never carried
 * the field, and a model where only some entries set an optional field
 * will always report a gap between the two.
 */
final class DeleteChunkResult
{
    public function __construct(
        public readonly int $rowsScanned,
        public readonly int $rowsPurged,
        public readonly int $newCursor,
        public readonly bool $isFinalChunk,
    ) {
    }
}
