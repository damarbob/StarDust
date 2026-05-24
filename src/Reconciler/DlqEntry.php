<?php

declare(strict_types=1);

namespace StarDust\Reconciler;

/**
 * Readonly DTO describing one row to quarantine via {@see DlqWriter}.
 *
 * `source` and `reason` are constrained to the ADR 0018 closed enums
 * mirrored in the `stardust_reconciler_dlq` table:
 *   - source: `sync_queue` | `bulk_import`
 *   - reason: `malformed_json` | `missing_entry_data`
 *             | `schema_incompatibility` | `other`
 *
 * `entry_id` is nullable — `missing_entry_data` and `malformed_json`
 * (in the `bulk_import` flow) can carry no id at all.
 *
 * `errorMessage` MUST NOT include unbounded payload content (PII risk
 * per ADR 0018); stick to short failure descriptors.
 */
final class DlqEntry
{
    public function __construct(
        public readonly string $source,
        public readonly ?int $entryId,
        public readonly int $tenantId,
        public readonly int $modelId,
        public readonly string $reason,
        public readonly ?string $errorMessage,
        public readonly string $chunkCorrelationId,
    ) {
    }
}
