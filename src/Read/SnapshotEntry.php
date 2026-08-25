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
     * @param list<string>                   $pendingDeletionNames names of fields whose
     *                                       ADR 0037 deletion is initiated but not yet
     *                                       purged. Deliberately NOT in `$fieldsByName`:
     *                                       the mapping is severed, so nothing may resolve
     *                                       or filter them. Carried only so the point read
     *                                       can strip their keys from un-purged payloads.
     * @param bool                           $modelDeleted ADR 0038: `stardust_models.deleted_at`
     *                                       is non-null — a model deletion is in flight.
     *                                       **Not derivable from `$fieldsByName` being empty**:
     *                                       a model registered with no fields is legal, and a
     *                                       `modelId` with no registry row at all must read
     *                                       `false` here, not `true`.
     */
    public function __construct(
        public readonly int $modelId,
        public readonly int $capturedAtVersion,
        public readonly int $capturedAtUnixTs,
        public readonly array $fieldsByName,
        public readonly array $pageTableNames,
        public readonly array $pendingDeletionNames = [],
        public readonly bool $modelDeleted = false,
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
     * True when any field in this model has an ADR 0037 deletion
     * initiated whose payload purge has not finished.
     *
     * Same eager-boolean discipline as {@see self::hasRenamesInFlight()}:
     * the steady-state cost of the delete stripping is one check.
     */
    public function hasPendingDeletions(): bool
    {
        return $this->pendingDeletionNames !== [];
    }

    /**
     * True when this model's ADR 0038 deletion is initiated and its entry
     * purge has not finished.
     *
     * The read surfaces go **dark** on this rather than raising:
     * `read()` / `search()` return an empty page and `get()` returns
     * `null`, indistinguishable from a model that never existed. That
     * matches the tenant-isolation posture `SchemaReader::describeModel()`
     * already takes, and it is why the check belongs in the driver rather
     * than in the pre-flight — an unfiltered (match-all) request never
     * reaches pre-flight at all.
     *
     * Note this is independent of {@see self::hasPendingDeletions()}. A
     * model deletion marks every field too, so both are true during a
     * model purge — but a *field* deletion sets only the latter, and the
     * two must never be conflated. Reading them from one aliased column
     * is the mistake this pair exists to prevent.
     */
    public function isModelDeleting(): bool
    {
        return $this->modelDeleted;
    }

    /**
     * Returns `$payload` with any in-flight rename's old key rewritten
     * to the field's current name, and any in-flight deletion's key
     * removed. Keys the registry does not know are passed through
     * untouched (ADR 0013 preserves unknown keys).
     *
     * Used by the point read, which returns the payload verbatim and so
     * would otherwise expose the old key for rows behind the backfill
     * cursor, or a deleted field's values for rows behind the purge
     * cursor.
     *
     * **The delete half is what stops `get()` disagreeing with
     * `read()`.** The paginated read is driven by `$fieldsByName`, which
     * excludes a deleting field outright, so it stops returning the
     * field the instant the delete commits. Without the strip here, the
     * same entry would come back with the field through `get()` and
     * without it through `read()` for the whole purge window.
     *
     * The JSON export artifact remains deliberately unbridged, matching
     * the documented rename carve-out: it streams the payload as stored.
     *
     * @param  array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function canonicalisePayloadKeys(array $payload): array
    {
        if ($this->hasRenamesInFlight()) {
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
        }

        foreach ($this->pendingDeletionNames as $name) {
            unset($payload[$name]);
        }

        return $payload;
    }
}
