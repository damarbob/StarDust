<?php

declare(strict_types=1);

namespace StarDust\Retype;

/**
 * Hydrated row from `backfill_checkpoints` for one running retype.
 *
 * The repository loads only the columns the work source actually
 * consumes — id (for cursor advancement), fieldId (decoded from
 * `job_name`), lastProcessedId (the cursor), and tenantId/modelId
 * (the partition the executor scans).
 */
final class RetypeCheckpoint
{
    public function __construct(
        public readonly int $id,
        public readonly int $fieldId,
        public readonly int $tenantId,
        public readonly int $modelId,
        public readonly int $lastProcessedId,
        public readonly string $sourceDeclaredType,
        public readonly string $targetDeclaredType,
        public readonly bool $targetIsFilterable,
        public readonly string $fieldName,
    ) {
    }
}
