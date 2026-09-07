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
        /**
         * The originating write's correlation id, read off the
         * `stardust_sync_queue` row this quarantine came from.
         *
         * **Not a duplicate of `$chunkCorrelationId`, which stays
         * required.** They answer different questions — "which tick
         * failed this" and "which write created it" — and only the first
         * was recordable before. Null for the `bulk_import` source,
         * which has no single originating write, and for a queue row
         * enqueued before the column existed.
         */
        public readonly ?string $originCorrelationId = null,
    ) {
    }
}
