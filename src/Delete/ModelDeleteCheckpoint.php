<?php

declare(strict_types=1);

namespace StarDust\Delete;

/**
 * One claimed ADR 0038 model-purge checkpoint.
 *
 * Deliberately narrower than {@see DeleteCheckpoint}: there is no
 * `fieldName`. A field purge carries one because it builds a JSON path
 * to remove a key; a model purge builds no path at all — it deletes the
 * rows whole.
 *
 * So the join to `stardust_models` in the claim query exists for exactly
 * two things, and it is worth knowing which:
 *
 * - **`tenantId`** — the only column the purge cannot recover from
 *   `job_name`, and the reason the model row must outlive the drain. It
 *   is not belt-and-braces: both `entry_data` secondary indexes lead on
 *   `tenant_id`, so it is the chunk query's access path.
 * - **`deleted_at IS NOT NULL`** — the integrity predicate that makes
 *   the join meaningful rather than incidental, and which no
 *   operator-named Backfill Pump job can satisfy.
 */
final class ModelDeleteCheckpoint
{
    public function __construct(
        public readonly int $id,
        public readonly int $modelId,
        public readonly int $tenantId,
        public readonly int $lastProcessedId,
    ) {
    }
}
