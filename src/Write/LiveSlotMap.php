<?php

declare(strict_types=1);

namespace StarDust\Write;

use PDO;

/**
 * Snapshot of live slot assignments for one `(tenant_id, model_id)`.
 *
 * "Live" per the slot-status state machine in
 * [schema_reference.md §4.5] means status ∈ {assigned, backfilling, ready}.
 * Per implementation_phases.md §3 the write path materializes into every
 * one of these — writes during an active retype MUST land in the new
 * `backfilling` slot so promotion to `ready` yields complete data.
 *
 * The map is keyed by `stardust_fields.name`. Each entry carries the
 * declared_type (to drive coercion), the physical slot column, the
 * page id, and the slot's status (the write path treats all three
 * live states identically; status is kept on the entry for diagnostic
 * use and forward-compatibility).
 *
 * A `LiveSlotMap` is read once per write via {@see self::loadFor()}.
 * It is not cached across requests — the registry's
 * `stardust_schema_version` version-bump invariants make per-request
 * reads safe, and Phase 4 will add the request-scoped cache.
 */
final class LiveSlotMap
{
    private const LIVE_STATUSES = ['assigned', 'backfilling', 'ready'];

    /**
     * @param array<string, LiveSlotEntry> $byFieldName
     */
    public function __construct(
        private readonly array $byFieldName,
    ) {
    }

    /** Load the live-slot map for a given model from the registry. */
    public static function loadFor(PDO $pdo, int $modelId): self
    {
        $placeholders = implode(',', array_fill(0, count(self::LIVE_STATUSES), '?'));
        $stmt = $pdo->prepare(
            'SELECT f.id AS field_id, f.name AS field_name, f.declared_type,'
            . ' a.slot_column, a.page_id, a.status'
            . ' FROM stardust_fields f'
            . ' JOIN stardust_slot_assignments a ON a.field_id = f.id'
            . " WHERE f.model_id = ? AND a.status IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$modelId], self::LIVE_STATUSES));

        $entries = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $entries[(string) $row['field_name']] = new LiveSlotEntry(
                fieldId: (int) $row['field_id'],
                fieldName: (string) $row['field_name'],
                declaredType: (string) $row['declared_type'],
                slotColumn: (string) $row['slot_column'],
                pageId: (int) $row['page_id'],
                status: (string) $row['status'],
            );
        }

        return new self($entries);
    }

    public function has(string $fieldName): bool
    {
        return isset($this->byFieldName[$fieldName]);
    }

    public function get(string $fieldName): ?LiveSlotEntry
    {
        return $this->byFieldName[$fieldName] ?? null;
    }

    /** @return array<string, LiveSlotEntry> */
    public function all(): array
    {
        return $this->byFieldName;
    }
}
