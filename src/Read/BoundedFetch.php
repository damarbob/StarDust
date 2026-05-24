<?php

declare(strict_types=1);

namespace StarDust\Read;

use PDO;

/**
 * Phase 4 Query 2 of the two-query bounded read of ADR 0005.
 *
 * Given the fixed set of `entry_data.id` values produced by
 * {@see PaginatedProbe}, materialises the entry rows plus every slot
 * column needed to assemble the caller's `selectFields`. Slots in
 * `backfilling`/`tombstoned` / unmapped status are *not* fetched
 * here — the {@see ResultAssembler} picks them up from the
 * `entry_data.fields` JSON, satisfying Phase 4 exit criterion #6
 * (the slot column is never consulted for those states).
 *
 * Page tables are LEFT JOINed: an entry may have no row in a given
 * page (e.g. if every field on that page lacked a live slot at write
 * time and only the JSON payload landed), and the absence must not
 * filter the entry out. The tenant_id predicate is bound on both
 * sides of every JOIN per Architecture Blueprint §1.2.
 *
 * Result rows come back as raw assoc-array fetches, keyed by their
 * SELECT-list aliases — `ResultAssembler` is the only consumer.
 */
final class BoundedFetch
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * @param list<int> $entryIds
     * @return array{rows: list<array<string,mixed>>, slotColumnByField: array<string,string>}
     *         `slotColumnByField` maps requested field names to the
     *         SELECT-list alias of their slot column (only populated
     *         for fields actually retrieved from a slot). Fields not
     *         present in this map are JSON_EXTRACT fallbacks at
     *         assembly time.
     */
    public function fetch(EntryQuery $query, SnapshotEntry $snapshot, array $entryIds): array
    {
        if ($entryIds === []) {
            return ['rows' => [], 'slotColumnByField' => []];
        }

        $selectFieldNames = $this->resolveSelectFieldNames($query, $snapshot);

        // Pick slot-served fields: those with isIndexedNow() true
        // (filterable AND status IN assigned|ready). Everything else
        // — including backfilling/tombstoned/unmapped — assembles
        // from entry_data.fields JSON.
        /** @var array<int, string> $aliasByPage  pageId → alias */
        $aliasByPage = [];
        /** @var array<string, string> $aliasByField fieldName → SELECT alias */
        $aliasByField = [];
        $selectColumns = [
            'entry_data.id            AS id',
            'entry_data.tenant_id     AS tenant_id',
            'entry_data.model_id      AS model_id',
            'entry_data.created_at    AS created_at',
            'entry_data.deleted_at    AS deleted_at',
            'entry_data.fields        AS fields_json',
        ];

        foreach ($selectFieldNames as $name) {
            $descriptor = $snapshot->field($name);
            if ($descriptor === null || ! $descriptor->isIndexedNow()) {
                continue;
            }
            $pageId = $descriptor->pageId;
            $slotColumn = $descriptor->slotColumn;
            // QueryValidator + isIndexedNow guarantee non-null page/column for indexed fields.
            if ($pageId === null || $slotColumn === null) {
                continue;
            }
            $aliasByPage[$pageId] ??= 'p' . count($aliasByPage);
            $pageAlias = $aliasByPage[$pageId];
            // Field-level alias prefix avoids collisions when two
            // different fields share the same slot column name across
            // different pages.
            $fieldAlias = 'f_' . $descriptor->fieldId;
            $aliasByField[$name] = $fieldAlias;
            $selectColumns[] = "{$pageAlias}.{$slotColumn} AS {$fieldAlias}";
        }

        $joins = [];
        foreach ($aliasByPage as $pageId => $alias) {
            $tableName = $snapshot->pageTableNames[$pageId];
            $joins[] = "LEFT JOIN {$tableName} {$alias}"
                . " ON {$alias}.entry_id = entry_data.id"
                . " AND {$alias}.tenant_id = entry_data.tenant_id";
        }

        $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
        $sql = 'SELECT ' . implode(', ', $selectColumns)
            . ' FROM entry_data'
            . ($joins === [] ? '' : ' ' . implode(' ', $joins))
            . " WHERE entry_data.id IN ({$placeholders})"
            . ' AND entry_data.tenant_id = ?'
            . ' ORDER BY entry_data.id ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([...$entryIds, $query->tenantId]);

        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'rows' => $rows,
            'slotColumnByField' => $aliasByField,
        ];
    }

    /**
     * @return list<string>
     */
    private function resolveSelectFieldNames(EntryQuery $query, SnapshotEntry $snapshot): array
    {
        if ($query->selectFields !== null) {
            return array_values($query->selectFields);
        }
        return array_values(array_keys($snapshot->fieldsByName));
    }
}
