<?php

declare(strict_types=1);

namespace StarDust\Chronicler;

/**
 * Internal DTO returned by {@see ExportJobProcessor::commitChunk()}.
 *
 * The processor's per-chunk transaction either commits the new
 * cursor + heartbeat + skip count (lease held) or no-ops because
 * the `WHERE worker_identity = self` predicate matched zero rows
 * (lease lost — re-claimer overwrote the row). Bundling both
 * signals onto one value keeps the call site readable: a single
 * post-commit branch on `leaseLost` covers the `lease_lost` exit,
 * and the other fields are quoted into the `chunk_written` event.
 */
final class ChunkOutcome
{
    public function __construct(
        public readonly int $newCursor,
        public readonly int $rowsStreamed,
        public readonly bool $isFinal,
        public readonly bool $leaseLost,
    ) {
    }
}
