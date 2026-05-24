<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Thrown when the Reconciler cannot read or parse an async-import
 * artifact file referenced by a `stardust_import_jobs.artifact_path`.
 *
 * Covers three failure modes per ADR 0028:
 *   - file missing or unreadable
 *   - `json_decode` failure (JSON_THROW_ON_ERROR)
 *   - top-level shape mismatch (`tenant_id` + `entries[]`)
 *
 * The Reconciler catches this, transitions the job to `failed` with
 * `failed_reason='malformed_json'`, and emits a `dlq_inserted` event.
 */
final class ImportJobArtifactException extends RuntimeException
{
}
