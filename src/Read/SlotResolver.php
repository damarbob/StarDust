<?php

declare(strict_types=1);

namespace StarDust\Read;

use PDO;

/**
 * Pure loader for {@see SnapshotEntry} per `(modelId)`.
 *
 * Reads `stardust_fields` and `stardust_slot_assignments` together with
 * a LEFT JOIN so the snapshot carries one {@see FieldDescriptor} per
 * registered field, regardless of whether that field has a live slot.
 * Also resolves page-id → table-name mappings for any pages referenced
 * by the snapshot's slot assignments so the SQL builder does not need
 * a second registry read at query time.
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
        $stmt = $pdo->prepare(
            'SELECT f.id            AS field_id,'
            . '       f.name          AS field_name,'
            . '       f.declared_type AS declared_type,'
            . '       f.is_filterable AS is_filterable,'
            . '       a.slot_column   AS slot_column,'
            . '       a.status        AS slot_status,'
            . '       a.page_id       AS page_id'
            . ' FROM stardust_fields f'
            . ' LEFT JOIN stardust_slot_assignments a'
            . "   ON a.field_id = f.id AND a.status IN ({$placeholders})"
            . ' WHERE f.model_id = ?'
        );
        $stmt->execute(array_merge(self::SLOT_STATUSES, [$modelId]));

        /** @var array<string, FieldDescriptor> $byName */
        $byName = [];
        /** @var array<int, true> $pageIdsSeen */
        $pageIdsSeen = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string) $row['field_name'];
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
            );
        }

        $pageTableNames = self::resolvePageTableNames($pdo, array_keys($pageIdsSeen));

        return new SnapshotEntry(
            modelId: $modelId,
            capturedAtVersion: $atVersion,
            capturedAtUnixTs: time(),
            fieldsByName: $byName,
            pageTableNames: $pageTableNames,
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
