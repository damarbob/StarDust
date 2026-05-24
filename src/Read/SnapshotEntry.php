<?php

declare(strict_types=1);

namespace StarDust\Read;

/**
 * Per-model snapshot of registry state, served from {@see SchemaVersionCache}.
 *
 * Captures everything the read path needs to plan a query without
 * touching the registry for each field lookup: the full set of
 * registered fields (so unknown filter targets can be rejected
 * pre-flight per ADR 0004), the slot status of each one (so
 * `backfilling`/`tombstoned`/unmapped targets can be uniformly rejected
 * for filters but still resolved via `JSON_EXTRACT` for assembly), and
 * the physical table name of each referenced page (so the SQL builder
 * does not need a second registry read).
 *
 * Cached for as long as `stardust_schema_version.version` is unchanged
 * per ADR 0015. Reload triggers an `api: cache_miss` event.
 */
final class SnapshotEntry
{
    /**
     * @param array<string, FieldDescriptor> $fieldsByName  fieldName → descriptor
     * @param array<int, string>             $pageTableNames pageId → `entry_slots_page_N`
     */
    public function __construct(
        public readonly int $modelId,
        public readonly int $capturedAtVersion,
        public readonly int $capturedAtUnixTs,
        public readonly array $fieldsByName,
        public readonly array $pageTableNames,
    ) {
    }

    public function field(string $fieldName): ?FieldDescriptor
    {
        return $this->fieldsByName[$fieldName] ?? null;
    }
}
