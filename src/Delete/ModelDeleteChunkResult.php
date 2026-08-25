<?php

declare(strict_types=1);

namespace StarDust\Delete;

/**
 * Outcome of one ADR 0038 model-purge chunk.
 *
 * `rowsDeleted` rather than `rowsPurged` — the ADR 0037 field purge
 * *rewrites* rows and this one *removes* them, and an operator watching a
 * destructive drain needs to see that distinction at a glance. ADR 0020
 * pins both names.
 *
 * `syncRowsDeleted` counts the `stardust_sync_queue` rows removed
 * alongside their entries in the same transaction. It is not cosmetic:
 * it is the number of `missing_entry_data` dead-letter rows that delete
 * prevented, and the only observable that would reveal the rule
 * regressing.
 *
 * `rowsScanned` and `rowsDeleted` are equal in the normal case — unlike
 * the field purge, where a `JSON_CONTAINS_PATH` guard skips rows that
 * never carried the key. Both are reported anyway so the two queues read
 * the same way on a dashboard.
 */
final class ModelDeleteChunkResult
{
    public function __construct(
        public readonly int $rowsScanned,
        public readonly int $rowsDeleted,
        public readonly int $syncRowsDeleted,
        public readonly int $newCursor,
        public readonly bool $isFinalChunk,
    ) {
    }
}
