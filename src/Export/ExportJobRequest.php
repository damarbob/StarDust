<?php

declare(strict_types=1);

namespace StarDust\Export;

use RuntimeException;

/**
 * Submission DTO for {@see ExportJobSubmitter::submit()}.
 *
 * `format` must be `csv` or `json`; the constructor validates so an
 * invalid format never reaches `stardust_export_jobs.format` (which
 * would otherwise truncate to an empty string under
 * `STRICT_TRANS_TABLES`).
 *
 * **`filter` must be empty.** Export predicate filtering is not
 * implemented — the Chronicler's pager selects on
 * `tenant_id / model_id / deleted_at` only and never reads the stored
 * filter back — so a non-empty filter is rejected by
 * {@see ExportJobSubmitter::submit()} with
 * {@see \StarDust\Exception\ExportFilterNotSupportedException}. It used
 * to be accepted and silently ignored, which turned a request for a
 * subset into a full extract of the model.
 *
 * The parameter itself is retained rather than removed: the stored
 * column shape stays `{model_id, filter}` (with `filter` always `[]`),
 * which `ExportJobClaimer::extractModelId()` depends on, and keeping
 * the argument means filtering can later be implemented without a
 * breaking signature change.
 */
final class ExportJobRequest
{
    public const FORMAT_CSV = 'csv';
    public const FORMAT_JSON = 'json';

    /**
     * @param array<string,mixed> $filter Must be empty; a non-empty
     *   value is rejected at submission. Reserved for a future
     *   filtering implementation.
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $modelId,
        public readonly string $format,
        public readonly array $filter = [],
        /**
         * The caller's own request id. Stamped onto `export_accepted`
         * and persisted to `stardust_export_jobs.correlation_id`, so the
         * Chronicler's `job_claimed` / `job_complete` — emitted from a
         * different process, possibly hours later — carry it too. Null
         * mints one at submission.
         */
        public readonly ?string $correlationId = null,
    ) {
        if ($format !== self::FORMAT_CSV && $format !== self::FORMAT_JSON) {
            throw new RuntimeException(
                "ExportJobRequest: format must be 'csv' or 'json'; got '{$format}'."
            );
        }
    }
}
