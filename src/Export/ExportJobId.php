<?php

declare(strict_types=1);

namespace StarDust\Export;

/**
 * Identifier returned from {@see ExportJobSubmitter::submit()}: the
 * `stardust_export_jobs.id` of the freshly inserted job row. Wrapped
 * in a class so caller code never confuses an export job id with an
 * entry id or an import job id. Mirrors
 * {@see \StarDust\Write\ImportJobId}.
 */
final class ExportJobId
{
    public function __construct(public readonly int $jobId)
    {
    }
}
