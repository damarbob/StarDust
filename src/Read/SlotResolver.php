<?php

declare(strict_types=1);

namespace StarDust\Read;

use PDO;

/**
 * Pure loader for {@see SnapshotEntry} per `(modelId)`.
 *
 * Reads `stardust_models`, `stardust_fields` and
 * `stardust_slot_assignments` together with outer joins so the snapshot
 * carries one {@see FieldDescriptor} per registered field, regardless of
 * whether that field has a live slot. Also resolves page-id → table-name
 * mappings for any pages referenced by the snapshot's slot assignments so
 * the SQL builder does not need a second registry read at query time.
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
            . '       a.page_id       AS page_id'
            . ' FROM stardust_models m'
            . ' LEFT JOIN stardust_fields f ON f.model_id = m.id'
            . ' LEFT JOIN stardust_slot_assignments a'
            . "   ON a.field_id = f.id AND a.status IN ({$placeholders})"
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
        /** @var array<int, true> $pageIdsSeen */
        $pageIdsSeen = [];
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
            if ($pageId !== null) {
                $pageIdsSeen[$pageId] = true;
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

        $pageTableNames = self::resolvePageTableNames($pdo, array_keys($pageIdsSeen));

        return new SnapshotEntry(
            modelId: $modelId,
            capturedAtVersion: $atVersion,
            capturedAtUnixTs: time(),
            fieldsByName: $byName,
            pageTableNames: $pageTableNames,
            pendingDeletionNames: $pendingDeletionNames,
            modelDeleted: $modelDeleted,
        );
    }

    /**
     * @param list<int> $pageIds
     * @return array<int, string>
     */
    private static function resolvePageTableNames(PDO $pdo, array $pageIds): array
    {
        if ($pageIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, table_name FROM stardust_pages WHERE id IN ({$placeholders})"
        );
        $stmt->execute($pageIds);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['id']] = (string) $row['table_name'];
        }
        return $out;
    }
}
