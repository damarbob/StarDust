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
 * `filter` is stored verbatim in the `filter` JSON column for
 * forward compatibility. Phase 7 MVP only consults `tenant_id` and
 * `modelId` from the request; predicate semantics are deferred to
 * the Phase 8 search-driver work. The submitter injects `model_id`
 * into the stored `filter` JSON so the Chronicler can hydrate it on
 * claim without a separate column.
 */
final class ExportJobRequest
{
    public const FORMAT_CSV = 'csv';
    public const FORMAT_JSON = 'json';

    /**
     * @param array<string,mixed> $filter Verbatim predicates / shape
     *   accepted by the engine; merged with `model_id` at storage time.
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $modelId,
        public readonly string $format,
        public readonly array $filter = [],
    ) {
        if ($format !== self::FORMAT_CSV && $format !== self::FORMAT_JSON) {
            throw new RuntimeException(
                "ExportJobRequest: format must be 'csv' or 'json'; got '{$format}'."
            );
        }
    }
}
