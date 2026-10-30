<?php

declare(strict_types=1);

namespace StarDust\Read;

use PDO;
use StarDust\Support\StringKeyLayout;

/**
 * Pure loader for {@see SnapshotEntry} per `(modelId)`.
 *
 * Reads `stardust_models`, `stardust_fields` and
 * `stardust_slot_assignments` together with outer joins so the snapshot
 * carries one {@see FieldDescriptor} per registered field, regardless of
 * whether that field has a live slot. The same join carries the table name
 * of each page the slot assignments reference, so the SQL builder does not
 * need a second registry read at query time. It also resolves the index on
 * each of those pages that returns a slot column in
 * `entry_id` order (see {@see self::resolveOrderedIndexes()}).
 *
 * **There is deliberately no tenant predicate.** Tenant scoping is the
 * caller's job and is enforced in every probe/fetch WHERE clause.
 * {@see SchemaVersionCache} keys its cache on `modelId` alone, so adding
 * `m.tenant_id = ?` here would make the cached snapshot depend on
 * whichever tenant happened to miss first — a cross-tenant poisoning bug
 * that no query-level predicate would reveal.
 *
 * Phase 4 reads this at most once per `stardust_schema_version` bump
 * via {@see SchemaVersionCache}, so the per-query cost is bounded.
 */
final class SlotResolver
{
    /**
     * Statuses the read path treats as "has a slot row" — i.e. the
     * descriptor carries non-NULL slotColumn/slotStatus/pageId. Only
     * `assigned` and `ready` are filterable; `backfilling`/`tombstoned`
     * still carry the slot column for diagnostic / future use even
     * though reads never touch the slot in those states.
     */
    private const SLOT_STATUSES = ['assigned', 'backfilling', 'ready', 'tombstoned'];

    public static function load(PDO $pdo, int $modelId, int $atVersion): SnapshotEntry
    {
        $placeholders = implode(',', array_fill(0, count(self::SLOT_STATUSES), '?'));
        // ADR 0038: `stardust_models` is the DRIVING table, joined out to
        // the fields, not the other way round. Driving off
        // `stardust_fields` cannot distinguish a model with no fields from
        // a model that does not exist — both yield zero rows — and the
        // model-deletion marker has to be readable for a fieldless model,
        // which is exactly the case the marker exists for.
        //
        // Both `deleted_at` columns are aliased. Selecting them under one
        // name would make `FETCH_ASSOC` keep only the last, silently
        // conflating "this field is being deleted" with "this model is".
        $stmt = $pdo->prepare(
            'SELECT f.id            AS field_id,'
            . '       f.name          AS field_name,'
            . '       f.declared_type AS declared_type,'
            . '       f.is_filterable AS is_filterable,'
            . '       f.previous_name AS previous_name,'
            . '       f.deleted_at    AS field_deleted_at,'
            . '       m.deleted_at    AS model_deleted_at,'
            . '       a.slot_column   AS slot_column,'
            . '       a.status        AS slot_status,'
            . '       a.page_id       AS page_id,'
            . '       p.table_name    AS page_table'
            . ' FROM stardust_models m'
            . ' LEFT JOIN stardust_fields f ON f.model_id = m.id'
            . ' LEFT JOIN stardust_slot_assignments a'
            . "   ON a.field_id = f.id AND a.status IN ({$placeholders})"
            . ' LEFT JOIN stardust_pages p ON p.id = a.page_id'
            . ' WHERE m.id = ?'
        );
        // Binding order is unchanged: statuses first, model id last. If
        // the joins are ever reordered this must be reordered with them,
        // and static analysis will not catch it.
        $stmt->execute(array_merge(self::SLOT_STATUSES, [$modelId]));

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Read outside the loop: the loop runs zero times for a fieldless
        // model, and zero rows means the model row itself is absent —
        // which is emphatically NOT the same as "deleting", and must stay
        // `false` or every fixture that seeds `entry_data` with a
        // fabricated `model_id` changes behaviour.
        $modelDeleted = $rows !== [] && $rows[0]['model_deleted_at'] !== null;

        /** @var array<string, FieldDescriptor> $byName */
        $byName = [];
        /** @var array<int, string> $pageTableNames pageId → table name */
        $pageTableNames = [];
        /** @var list<string> $pendingDeletionNames */
        $pendingDeletionNames = [];
        foreach ($rows as $row) {
            // The outer join's all-null row for a model with no fields.
            // Keyed on `field_id`, never on `field_name`: that column is
            // NOT NULL in the table, so `(string) null` yields `''`, which
            // passes every check below and materialises a phantom
            // descriptor keyed by the empty string — which `ResultAssembler`
            // would then emit as `'' => null` on every row of every read.
            if ($row['field_id'] === null) {
                continue;
            }

            $name = (string) $row['field_name'];

            // ADR 0037: the row outlives the registry mapping so the
            // purge can find the field's name, but the mapping itself
            // is severed at initiation. Excluding the descriptor is
            // what makes `read()` stop returning the field and filters
            // raise `UnknownFieldException` from the moment the delete
            // commits. The name is still carried so the point read can
            // strip the key from payloads the purge has not reached.
            if ($row['field_deleted_at'] !== null) {
                $pendingDeletionNames[] = $name;
                continue;
            }

            $pageId = $row['page_id'] === null ? null : (int) $row['page_id'];
            if ($pageId !== null && $row['page_table'] !== null) {
                $pageTableNames[$pageId] = (string) $row['page_table'];
            }

            $byName[$name] = new FieldDescriptor(
                fieldId: (int) $row['field_id'],
                fieldName: $name,
                declaredType: (string) $row['declared_type'],
                isFilterable: (bool) $row['is_filterable'],
                slotColumn: $row['slot_column'] === null ? null : (string) $row['slot_column'],
                slotStatus: $row['slot_status'] === null ? null : (string) $row['slot_status'],
                pageId: $pageId,
                previousName: $row['previous_name'] === null ? null : (string) $row['previous_name'],
            );
        }

        $orderedIndexes = self::resolveOrderedIndexes($pdo, $pageTableNames);

        return new SnapshotEntry(
            modelId: $modelId,
            capturedAtVersion: $atVersion,
            capturedAtUnixTs: time(),
            fieldsByName: $byName,
            pageTableNames: $pageTableNames,
            pendingDeletionNames: $pendingDeletionNames,
            modelDeleted: $modelDeleted,
            orderedIndexes: $orderedIndexes,
            stringKeys: self::resolveStringKeys($pdo, $pageTableNames, $orderedIndexes),
        );
    }

    /**
     * For each slot column on the given pages, the name of an index that
     * returns equal values in `entry_id` order.
     *
     * An index qualifies when it has exactly two key parts, `tenant_id` and
     * then one column, both at full width and in a B-tree. InnoDB appends
     * the primary key (`entry_id`) to every secondary index, so with
     * `tenant_id` and the column both pinned by equality the rows come back
     * in `entry_id` order. A third key part would sit between the column
     * and `entry_id` and break that order. Requiring full width also leaves
     * out a string slot's 766 character prefix index, which cannot answer
     * an equality on its own. A string slot's generated key (ADR 0057)
     * qualifies as it stands, but under the key column's name and not the
     * slot's, because the slot itself is in no index on a key page.
     *
     * An index the optimiser has been told to skip (`INVISIBLE` on MySQL,
     * `IGNORED` on MariaDB) is left out as well, since a hint naming it
     * fails the read. When several indexes qualify the smallest name wins,
     * so the choice is the same on every refresh.
     *
     * This is one query per snapshot refresh and nothing on a warm read,
     * because the snapshot is cached per schema version. `SELECT *` is
     * deliberate: the visibility column has a different name on each engine,
     * and the keys come back in upper case on MySQL 8.
     *
     * @param array<int, string> $pageTableNames pageId → table name
     * @return array<int, array<string, string>> pageId → slotColumn → index name
     */
    private static function resolveOrderedIndexes(PDO $pdo, array $pageTableNames): array
    {
        if ($pageTableNames === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($pageTableNames), '?'));
        $stmt = $pdo->prepare(
            'SELECT * FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE()'
            . " AND TABLE_NAME IN ({$placeholders})"
            . ' ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX'
        );
        $stmt->execute(array_values($pageTableNames));

        /** @var array<string, int> $pageIdOfTable */
        $pageIdOfTable = array_flip($pageTableNames);

        /** @var array<string, array<string, list<array{column: ?string, subPart: mixed, type: string}>>> $keyParts */
        $keyParts = [];
        /** @var array<string, array<string, true>> $skipped */
        $skipped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $r = array_change_key_case($row, CASE_LOWER);
            $table = (string) $r['table_name'];
            $index = (string) $r['index_name'];

            if (($r['is_visible'] ?? 'YES') === 'NO' || ($r['ignored'] ?? 'NO') === 'YES') {
                $skipped[$table][$index] = true;
            }
            $keyParts[$table][$index][] = [
                'column'  => $r['column_name'] === null ? null : strtolower((string) $r['column_name']),
                'subPart' => $r['sub_part'],
                'type'    => strtoupper((string) $r['index_type']),
            ];
        }

        $out = [];
        foreach ($keyParts as $table => $indexes) {
            foreach ($indexes as $index => $parts) {
                $index = (string) $index;
                if (isset($skipped[$table][$index]) || count($parts) !== 2) {
                    continue;
                }
                [$tenant, $slot] = $parts;
                if (
                    $tenant['column'] !== 'tenant_id' || $tenant['subPart'] !== null
                    || $slot['column'] === null || $slot['subPart'] !== null
                    || $slot['type'] !== 'BTREE'
                ) {
                    continue;
                }

                if (! isset($pageIdOfTable[$table])) {
                    continue;
                }
                $pageId = $pageIdOfTable[$table];
                $current = $out[$pageId][$slot['column']] ?? null;
                if ($current === null || strcmp($index, $current) < 0) {
                    $out[$pageId][$slot['column']] = $index;
                }
            }
        }

        return $out;
    }

    /**
     * For each string slot whose page indexes it through a generated key
     * (ADR 0057), how to look it up through that key.
     *
     * A page's shape comes from the data dictionary of the page table and never
     * from a registry column. The table is the only fact: a crash between a
     * page's `CREATE TABLE` and the registry transaction can leave an orphan
     * table that a later provisioning reuses by name, so a flag in the registry
     * could name a shape the table does not have, and the compiler would then
     * name a column that is not there.
     *
     * The query runs only when the index map already holds a key column, so a
     * model that lives on prefix pages pays nothing, and a warm read pays
     * nothing either because the snapshot is cached per schema version. A slot
     * is left out when the index map has no usable index on its key (a hidden
     * one included), when the key column is not a `VARBINARY`, or when the
     * dictionary's collation or width cannot be trusted. A slot left out
     * compiles to the SQL it had before keys.
     *
     * @param array<int, string>                $pageTableNames pageId → table name
     * @param array<int, array<string, string>> $orderedIndexes pageId → column → index name
     * @return array<int, array<string, StringKey>> pageId → slotColumn → key
     */
    private static function resolveStringKeys(PDO $pdo, array $pageTableNames, array $orderedIndexes): array
    {
        /** @var array<int, array<string, string>> $candidates pageId → slotColumn → key column */
        $candidates = [];
        foreach ($orderedIndexes as $pageId => $byColumn) {
            foreach (array_keys($byColumn) as $column) {
                $slot = StringKeyLayout::slotColumn((string) $column);
                if ($slot !== null && isset($pageTableNames[$pageId])) {
                    $candidates[$pageId][$slot] = (string) $column;
                }
            }
        }
        if ($candidates === []) {
            return [];
        }

        $tables = [];
        foreach (array_keys($candidates) as $pageId) {
            $tables[] = $pageTableNames[$pageId];
        }
        $placeholders = implode(',', array_fill(0, count($tables), '?'));
        $stmt = $pdo->prepare(
            'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLLATION_NAME, CHARACTER_MAXIMUM_LENGTH'
            . ' FROM information_schema.COLUMNS'
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})"
        );
        $stmt->execute($tables);

        /** @var array<string, array<string, array<string, mixed>>> $columns table → column → row */
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $r = array_change_key_case($row, CASE_LOWER);
            $columns[(string) $r['table_name']][strtolower((string) $r['column_name'])] = $r;
        }

        $out = [];
        foreach ($candidates as $pageId => $bySlot) {
            $table = $pageTableNames[$pageId];
            foreach ($bySlot as $slot => $keyColumn) {
                $keyRow = $columns[$table][$keyColumn] ?? null;
                $slotRow = $columns[$table][$slot] ?? null;
                if ($keyRow === null || $slotRow === null || strtolower((string) $keyRow['data_type']) !== 'varbinary') {
                    continue;
                }

                $key = StringKey::fromDictionary(
                    $keyColumn,
                    $orderedIndexes[$pageId][$keyColumn],
                    (int) $keyRow['character_maximum_length'],
                    $slotRow['collation_name'] === null ? null : (string) $slotRow['collation_name'],
                );
                if ($key !== null) {
                    $out[$pageId][$slot] = $key;
                }
            }
        }

        return $out;
    }
}
