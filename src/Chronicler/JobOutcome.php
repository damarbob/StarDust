<?php

declare(strict_types=1);

namespace StarDust\Chronicler;

/**
 * Terminal state returned by {@see ExportJobProcessor::process()}.
 *
 * Five terminal failure flavours mirror the closed `failed_reason`
 * taxonomy in `stardust_export_jobs.failed_reason` (ADR 0025) plus the
 * `LeaseLost` non-failure exit (the row was overwritten by a re-claimer
 * — terminal-state ownership transfers).
 */
enum JobOutcome: string
{
    case Completed = 'completed';
    case FailedExcessiveSkips = 'failed:excessive_skips';
    case FailedQueryFailure = 'failed:query_failure';
    case FailedDiskFull = 'failed:disk_full';
    case FailedArtifactSizeExceeded = 'failed:artifact_size_exceeded';
    case LeaseLost = 'lease_lost';
}
