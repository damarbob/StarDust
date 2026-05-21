<?php

declare(strict_types=1);

namespace StarDust\Write;

/**
 * Single-entry write payload.
 *
 * `fields` is the consumer's logical entry data as an associative
 * array, keyed by `stardust_fields.name`. Per ADR 0013 this JSON
 * payload is the system of record — slot columns are materializations
 * — so unmapped field names are persisted in `entry_data.fields`
 * untouched and retrieved later via `JSON_EXTRACT`.
 *
 * The DTO is intentionally minimal. Callers that need to coordinate
 * with upstream identifiers should map their identifiers onto
 * `tenant_id` / `model_id` themselves; this engine does not perform
 * authentication or model resolution.
 */
final class EntryPayload
{
    /**
     * @param array<string, mixed> $fields Field name → value map.
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $modelId,
        public readonly array $fields,
    ) {
    }
}
