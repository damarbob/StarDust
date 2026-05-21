<?php

declare(strict_types=1);

namespace StarDust\Write;

/**
 * Identifier returned from {@see BulkIngestSubmitter::submit()}: the
 * `stardust_import_jobs.id` of the freshly inserted (or idempotency-
 * matched) job row. Wrapped in a class so caller code can pass it
 * around without confusing it with an entry id.
 */
final class ImportJobId
{
    public function __construct(public readonly int $jobId)
    {
    }
}
