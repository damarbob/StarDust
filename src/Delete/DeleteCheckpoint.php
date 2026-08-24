<?php

declare(strict_types=1);

namespace StarDust\Delete;

/**
 * One claimed ADR 0037 delete-purge checkpoint, hydrated with the
 * partition tuple the executor needs.
 *
 * `fieldName` comes from `stardust_fields.name` via the repository's
 * JOIN, not from the checkpoint row — the same single-source-of-truth
 * choice {@see \StarDust\Rename\RenameCheckpoint} makes for
 * `previousName`. It is the whole reason the field row outlives the
 * purge: `backfill_checkpoints` has no column for a field name, a model
 * or a tenant, and the executor needs all three.
 */
final class DeleteCheckpoint
{
    public function __construct(
        public readonly int $id,
        public readonly int $fieldId,
        public readonly int $tenantId,
        public readonly int $modelId,
        public readonly int $lastProcessedId,
        public readonly string $fieldName,
    ) {
    }
}
