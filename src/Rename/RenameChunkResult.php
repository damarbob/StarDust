<?php

declare(strict_types=1);

namespace StarDust\Rename;

/**
 * Outcome of one {@see RenameBackfillExecutor::processChunk()} pass.
 *
 * `rowsRewritten` counts rows whose payload actually carried the old key;
 * rows already migrated, or that never held the field at all, are scanned
 * but not rewritten, so `rowsRewritten <= rowsScanned` is normal rather
 * than a symptom.
 *
 * `isFinalChunk` is derived from `rowsScanned < chunkSize` — the same
 * exhaustion signal the retype and Liberator drains use.
 */
final class RenameChunkResult
{
    public function __construct(
        public readonly int $rowsScanned,
        public readonly int $rowsRewritten,
        public readonly int $newCursor,
        public readonly bool $isFinalChunk,
    ) {
    }
}
