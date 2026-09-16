<?php

declare(strict_types=1);

namespace StarDust\Chronicler;

/**
 * Distinguishes the claim paths the Chronicler walks each tick.
 * Carried into the `job_claimed` event payload (chronicler_daemon.md §6).
 *
 * `Resumed` (ADR 0050) is a `pending`-status row that was previously
 * yielded rather than freshly submitted — `ExportJobClaimer` tells the
 * two apart by whether the row carries a usable resume anchor
 * (`artifact_path` + a positive `artifact_bytes`), the same predicate
 * {@see ArtifactStreamFactory::from()} applies. A never-run submission
 * has both columns NULL and claims as `Pending`.
 */
enum ClaimKind: string
{
    case Pending = 'pending';
    case Abandoned = 'abandoned';
    case Resumed = 'resumed';
}
