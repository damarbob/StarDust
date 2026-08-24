<?php

declare(strict_types=1);

namespace StarDust\Read;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Phase 4 row-to-DTO materialiser.
 *
 * Consumes raw rows from {@see BoundedFetch} and produces a
 * `list<Entry>` ordered by `entry_data.id ASC`. For each requested
 * field, the value source is:
 *
 *   - **Slot column** — when the field's current status is
 *     `assigned` or `ready` AND the caller selected it. The value
 *     is whatever the SQL row materialised under the column's alias.
 *   - **JSON payload** — for every other case: `backfilling`,
 *     `tombstoned`, or unmapped fields. The value is read from the
 *     decoded `entry_data.fields` payload, satisfying Phase 4 exit
 *     criterion #6 ("the slot column is not consulted"). Sourcing
 *     from the decoded payload column is equivalent to a
 *     `JSON_EXTRACT(fields, '$.<name>')` projection — same byte
 *     source, no slot reference — and skips the per-row JSON parse
 *     cost MySQL would incur per JSON_EXTRACT call.
 *
 * Fields absent from `entry_data.fields` materialise as `null` (per
 * ADR 0013 the JSON payload may legitimately omit a field).
 *
 * **The JSON_EXTRACT equivalence above holds except during an ADR 0036
 * rename window.** While `stardust_fields.previous_name` is non-null,
 * rows behind the backfill cursor are still keyed by the old name, so
 * the assembler falls back to that key when the current one is absent.
 * A single-path `JSON_EXTRACT` on the new name would return null for
 * every un-migrated row. The fallback costs one `array_key_exists` on
 * the miss path only, and disappears when the backfill clears
 * `previous_name`.
 */
final class ResultAssembler
{
    /**
     * @param list<array<string,mixed>> $rows               raw assoc fetches from BoundedFetch
     * @param array<string,string>      $slotColumnByField  fieldName → SELECT alias (BoundedFetch output)
     * @param list<string>|null         $selectFields       caller-requested field names; null → all
     * @return list<Entry>
     */
    public function assemble(
        array $rows,
        SnapshotEntry $snapshot,
        array $slotColumnByField,
        ?array $selectFields,
    ): array {
        $fieldNames = $selectFields ?? array_keys($snapshot->fieldsByName);

        // ADR 0037: `$selectFields` is caller-supplied and is not
        // validated against the snapshot, so it is the one way a
        // deleted field's name can re-enter this loop after
        // `SlotResolver` excluded it. Without this filter an explicit
        // `selectFields: ['colour']` would fall through to the payload
        // branch below and hand back the residual value for every row
        // the purge has not yet reached — the field would read as gone
        // through a default read and present through an explicit one.
        //
        // Dropped rather than nulled, so the key set matches a default
        // read and `get()`.
        if ($selectFields !== null && $snapshot->hasPendingDeletions()) {
            $fieldNames = array_values(array_diff($fieldNames, $snapshot->pendingDeletionNames));
        }

        $out = [];
        foreach ($rows as $row) {
            $payload = $this->decodePayload($row['fields_json'] ?? 'null');

            $fields = [];
            foreach ($fieldNames as $name) {
                if (isset($slotColumnByField[$name])) {
                    $fields[$name] = $row[$slotColumnByField[$name]] ?? null;
                    continue;
                }
                // JSON-payload fallback. Missing keys legitimately
                // materialise as null per ADR 0013.
                if (array_key_exists($name, $payload)) {
                    $fields[$name] = $payload[$name];
                    continue;
                }
                // ADR 0036 rename window: the registry already carries
                // the new name, but rows behind the backfill cursor are
                // still keyed by the old one. Without this the field
                // would silently read null for the whole drain.
                $previous = $snapshot->field($name)?->previousName;
                $fields[$name] = ($previous !== null && array_key_exists($previous, $payload))
                    ? $payload[$previous]
                    : null;
            }

            $out[] = new Entry(
                id: (int) $row['id'],
                tenantId: (int) $row['tenant_id'],
                modelId: (int) $row['model_id'],
                fields: $fields,
                createdAt: $this->parseDatetime((string) $row['created_at']),
                deletedAt: $row['deleted_at'] === null
                    ? null
                    : $this->parseDatetime((string) $row['deleted_at']),
            );
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function parseDatetime(string $value): DateTimeImmutable
    {
        // entry_data.created_at / deleted_at are stored as MySQL
        // DATETIME (UTC by convention of the EntryWriter). Construct
        // the DTO with an explicit UTC zone so downstream consumers
        // never see a mis-attributed local-time DateTimeImmutable.
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
