<?php

declare(strict_types=1);

namespace StarDust\Rename;

/**
 * One claimed `rename_field_{id}` row from `backfill_checkpoints`,
 * hydrated with the partition tuple the work source needs.
 *
 * `previousName` comes from `stardust_fields.previous_name` rather than
 * the checkpoint row: a rename overwrites `name` but writes the old
 * value to a purpose-built spare column that survives the whole window,
 * so there is no need to duplicate it onto the checkpoint the way
 * retype must with `source_declared_type`. One source of truth, and it
 * is the same column the read and write paths already consult.
 */
final class RenameCheckpoint
{
    public function __construct(
        public readonly int $id,
        public readonly int $fieldId,
        public readonly int $tenantId,
        public readonly int $modelId,
        public readonly int $lastProcessedId,
        public readonly string $currentName,
        public readonly string $previousName,
    ) {
    }
}
