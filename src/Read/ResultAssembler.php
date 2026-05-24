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
 *     from the decoded payload column is functionally identical to a
 *     `JSON_EXTRACT(fields, '$.<name>')` projection — same byte
 *     source, no slot reference — and skips the per-row JSON parse
 *     cost MySQL would incur per JSON_EXTRACT call.
 *
 * Fields absent from `entry_data.fields` materialise as `null` (per
 * ADR 0013 the JSON payload may legitimately omit a field).
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
        $fieldNames = $selectFields ?? array_values(array_keys($snapshot->fieldsByName));

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
                $fields[$name] = $payload[$name] ?? null;
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
