<?php

declare(strict_types=1);

namespace StarDust\Write;

use PDO;

/**
 * Snapshot of live slot assignments for one `(tenant_id, model_id)`,
 * plus the full set of registered field names for that model.
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
 * The separate `$registeredFieldNames` set (populated via LEFT JOIN)
 * lets `PayloadSplitter` distinguish "registered field, no live slot"
 * (ADR 0007 exhaustion enqueue) from "unknown payload key" (silent
 * drop per ADR 0013 — value persists in `entry_data.fields`).
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
     * @param array<string, true> $registeredFieldNames
     */
    public function __construct(
        private readonly array $byFieldName,
        private readonly array $registeredFieldNames,
    ) {
    }

    /** Load the live-slot map for a given model from the registry. */
    public static function loadFor(PDO $pdo, int $modelId): self
    {
        $placeholders = implode(',', array_fill(0, count(self::LIVE_STATUSES), '?'));
        // LEFT JOIN so rows with no live slot still appear in the result —
        // needed to distinguish "registered field, slot exhausted" (enqueue)
        // from "unknown payload key" (silent drop, per ADR 0007 + 0013).
        $stmt = $pdo->prepare(
            'SELECT f.id AS field_id, f.name AS field_name, f.declared_type,'
            . ' a.slot_column, a.page_id, a.status'
            . ' FROM stardust_fields f'
            . ' LEFT JOIN stardust_slot_assignments a'
            . "   ON a.field_id = f.id AND a.status IN ({$placeholders})"
            . ' WHERE f.model_id = ?'
        );
        $stmt->execute(array_merge(self::LIVE_STATUSES, [$modelId]));

        $entries = [];
        $registeredFieldNames = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string) $row['field_name'];
            $registeredFieldNames[$name] = true;

            if ($row['slot_column'] === null) {
                continue;
            }

            $entries[$name] = new LiveSlotEntry(
                fieldId: (int) $row['field_id'],
                fieldName: $name,
                declaredType: (string) $row['declared_type'],
                slotColumn: (string) $row['slot_column'],
                pageId: (int) $row['page_id'],
                status: (string) $row['status'],
            );
        }

        return new self($entries, $registeredFieldNames);
    }

    /** True if the field name exists in stardust_fields for this model, regardless of slot status. */
    public function isKnown(string $fieldName): bool
    {
        return isset($this->registeredFieldNames[$fieldName]);
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
