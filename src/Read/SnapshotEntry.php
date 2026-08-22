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
    private readonly bool $renamesInFlight;

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
        // Computed once here rather than memoised lazily: the snapshot
        // is immutable and cached per schema version, so the answer
        // cannot change, and an eager bool keeps the DTO free of
        // mutable state.
        $found = false;
        foreach ($fieldsByName as $descriptor) {
            if ($descriptor->isRenameInFlight()) {
                $found = true;
                break;
            }
        }
        $this->renamesInFlight = $found;
    }

    public function field(string $fieldName): ?FieldDescriptor
    {
        return $this->fieldsByName[$fieldName] ?? null;
    }

    /**
     * True when any field in this model has an ADR 0036 rename in
     * flight (`stardust_fields.previous_name` non-null).
     *
     * Computed once at construction. Callers use it to keep the
     * steady-state cost of the rename fallback at one boolean check.
     */
    public function hasRenamesInFlight(): bool
    {
        return $this->renamesInFlight;
    }

    /**
     * Returns `$payload` with any in-flight rename's old key rewritten
     * to the field's current name. Keys the registry does not know are
     * passed through untouched (ADR 0013 preserves unknown keys).
     *
     * Used by the point read, which returns the payload verbatim and so
     * would otherwise expose the old key for rows behind the backfill
     * cursor.
     *
     * @param  array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function canonicalisePayloadKeys(array $payload): array
    {
        if (! $this->hasRenamesInFlight()) {
            return $payload;
        }

        foreach ($this->fieldsByName as $name => $descriptor) {
            $previous = $descriptor->previousName;
            if ($previous === null || ! array_key_exists($previous, $payload)) {
                continue;
            }
            // A row already migrated carries the new key; the old key
            // should not survive alongside it.
            if (! array_key_exists($name, $payload)) {
                $payload[$name] = $payload[$previous];
            }
            unset($payload[$previous]);
        }

        return $payload;
    }
}
