<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Internal Chronicler exception raised by an
 * {@see \StarDust\Chronicler\ArtifactStream} implementation when
 * `fwrite()` returns short or PHP signals an `ENOSPC` condition during
 * append. The surrounding
 * {@see \StarDust\Chronicler\ExportJobProcessor} catches it, marks the
 * job `failed` with `failed_reason='disk_full'`, deletes the partial
 * artifact (best-effort), and emits a `job_failed` event with
 * `reason='disk_full'`.
 *
 * Distinct from the pre-claim `low_disk` advisory event emitted by
 * {@see \StarDust\Chronicler\DiskPressureGate} (which only gates new
 * claims; in-flight jobs continue until they trip this exception).
 */
final class ChroniclerArtifactDiskFullException extends RuntimeException
{
}
