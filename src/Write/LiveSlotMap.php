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
 * The separate `$registeredFieldFilterability` map (populated via LEFT
 * JOIN) carries each registered field's `is_filterable` flag, letting
 * `PayloadSplitter` tell three cases apart:
 *   - *filterable* registered field with no live slot — ADR 0007
 *     exhaustion enqueue;
 *   - non-filterable registered field — JSON-only per ADR 0034, so it
 *     never holds a slot and never enqueues;
 *   - unknown payload key — silent drop per ADR 0013.
 * In all three the value persists in `entry_data.fields`.
 *
 * `LiveSlotEntry` deliberately does not carry `is_filterable`: the
 * decision point has to work for fields with no entry at all (a
 * JSON-only field is the ADR 0034 steady state), so the name-keyed map
 * predicate is the only viable form. The column is already selected, so
 * adding it to the entry later is a three-line change if a consumer of
 * {@see self::all()} ever needs it.
 *
 * A `LiveSlotMap` is read once per write via {@see self::loadFor()}.
 * It is not cached across requests — the registry's
 * `stardust_schema_version` version-bump invariants make per-request
 * reads safe, and Phase 4 will add the request-scoped cache.
 */
final class LiveSlotMap
{
    /**
     * Slot statuses the write path treats as live.
     *
     * Public because the Watcher's demand reader needs the same
     * definition of "this field already has a slot" — a second literal
     * would let the two drift. Note {@see \StarDust\Retype\RetypeBackfillWorkSource}
     * deliberately uses a narrower `('backfilling','ready')` set; that
     * one is not this invariant.
     */
    public const LIVE_STATUSES = ['assigned', 'backfilling', 'ready'];

    /**
     * @param array<string, LiveSlotEntry> $byFieldName
     * @param array<string, bool> $registeredFieldFilterability field name → is_filterable
     * @param array<string, string> $canonicalByPreviousName ADR 0036: old field name → current name
     * @param list<string> $pendingDeletionNames ADR 0037: names of fields whose deletion is
     *                     in flight. NOT present in the other two maps — the mapping is
     *                     severed — but carried so `canonicalise()` can strip them.
     * @param bool $modelDeleted ADR 0038: `stardust_models.deleted_at` is non-null.
     *                     **Not derivable from the maps being empty** — a fieldless model is
     *                     legal, and a model id with no registry row must read `false`.
     */
    public function __construct(
        private readonly array $byFieldName,
        private readonly array $registeredFieldFilterability,
        private readonly array $canonicalByPreviousName = [],
        private readonly array $pendingDeletionNames = [],
        private readonly bool $modelDeleted = false,
    ) {
    }

    /** Load the live-slot map for a given model from the registry. */
    public static function loadFor(PDO $pdo, int $modelId): self
    {
        $placeholders = implode(',', array_fill(0, count(self::LIVE_STATUSES), '?'));
        // LEFT JOIN so rows with no live slot still appear in the result —
        // needed to distinguish "registered field, slot exhausted" (enqueue)
        // from "unknown payload key" (silent drop, per ADR 0007 + 0013).
        //
        // ADR 0038: `stardust_models` drives, joined out to the fields.
        // Driving off `stardust_fields` cannot tell a fieldless model from
        // a nonexistent one, and the model-deletion marker has to be
        // readable in exactly that case. Both `deleted_at` columns are
        // aliased — under one name `FETCH_ASSOC` keeps only the last, and
        // "this field is being deleted" would silently become "this model
        // is". No tenant predicate: see {@see \StarDust\Read\SlotResolver}.
        $stmt = $pdo->prepare(
            'SELECT f.id AS field_id, f.name AS field_name, f.declared_type,'
            . ' f.is_filterable, f.previous_name,'
            . ' f.deleted_at AS field_deleted_at,'
            . ' m.deleted_at AS model_deleted_at,'
            . ' a.slot_column, a.page_id, a.status'
            . ' FROM stardust_models m'
            . ' LEFT JOIN stardust_fields f ON f.model_id = m.id'
            . ' LEFT JOIN stardust_slot_assignments a'
            . "   ON a.field_id = f.id AND a.status IN ({$placeholders})"
            . ' WHERE m.id = ?'
        );
        // Statuses first, model id last — unchanged. Reordering the joins
        // means reordering these, and static analysis will not catch it.
        $stmt->execute(array_merge(self::LIVE_STATUSES, [$modelId]));

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Read outside the loop: it runs zero times for a fieldless model.
        // Zero rows means the model row is absent, which must read `false`
        // — absent is not the same as deleting.
        $modelDeleted = $rows !== [] && $rows[0]['model_deleted_at'] !== null;

        $entries = [];
        $registeredFieldFilterability = [];
        $canonicalByPreviousName = [];
        $pendingDeletionNames = [];
        foreach ($rows as $row) {
            // The outer join's all-null row for a model with no fields.
            // Keyed on `field_id`: `name` is NOT NULL in the table, so
            // `(string) null` yields `''`, which would register a phantom
            // field under the empty string.
            if ($row['field_id'] === null) {
                continue;
            }

            $name = (string) $row['field_name'];

            // ADR 0037: severed from the write path immediately, so it
            // is deliberately absent from every map below. It is NOT
            // enough to simply drop it: an unregistered key is an
            // *unknown* key, and ADR 0013 preserves those verbatim in
            // `entry_data.fields` — so a client still sending the name
            // would keep writing it back into new entries forever, and
            // the purge's single forward pass would never catch them.
            // `canonicalise()` strips it instead.
            if ($row['field_deleted_at'] !== null) {
                $pendingDeletionNames[] = $name;
                continue;
            }

            $registeredFieldFilterability[$name] = (bool) $row['is_filterable'];

            if ($row['previous_name'] !== null) {
                $canonicalByPreviousName[(string) $row['previous_name']] = $name;
            }

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

        return new self(
            $entries,
            $registeredFieldFilterability,
            $canonicalByPreviousName,
            $pendingDeletionNames,
            $modelDeleted,
        );
    }

    /**
     * True while this model's ADR 0038 deletion is in flight.
     *
     * The write path **refuses** on this, inverting the ADR 0037 rule
     * that a deleted *field's* key is stripped rather than rejected.
     * There is no residual valid entry to preserve: the write would
     * create a row in a partition being erased, landing either behind the
     * purge cursor (so the acceptance was a lie) or ahead of it (a
     * permanent orphan with a dangling `model_id`). Rejection costs the
     * caller nothing real, because the row would be destroyed inside the
     * drain window regardless.
     *
     * Independent of {@see self::hasPendingDeletions()}: a model deletion
     * sets both, a field deletion sets only that one.
     */
    public function isModelDeleting(): bool
    {
        return $this->modelDeleted;
    }

    /**
     * True while any field in this model has an ADR 0036 rename in
     * flight. Lets the write path skip {@see self::canonicalise()}
     * entirely in the steady state.
     */
    public function hasAliases(): bool
    {
        return $this->canonicalByPreviousName !== [];
    }

    /**
     * True while any field in this model has an ADR 0037 deletion in
     * flight whose payload purge has not finished.
     */
    public function hasPendingDeletions(): bool
    {
        return $this->pendingDeletionNames !== [];
    }

    /**
     * Rewrites any payload key naming a field by its pre-rename name to
     * the field's current name, and removes any key naming a field whose
     * deletion is in flight.
     *
     * **This is a data-loss guard, not a convenience.** A rename flips
     * `stardust_fields.name` immediately, so a client that has not yet
     * redeployed keeps sending the old name. Without this, that key is
     * unknown to the map, {@see PayloadSplitter} drops it from the slot
     * plan, and the value lands in `entry_data.fields` under the stale
     * key with no slot write and no exhaustion enqueue — so a filter on
     * the new name matches a slot value the entry no longer has. Worse,
     * for a row the rename backfill has already passed, that key is
     * never migrated and the value disappears entirely when
     * `previous_name` is cleared.
     *
     * Canonicalising here makes the write path converge instead: the
     * value lands under the new key and hits the slot, so the backfill's
     * single forward pass really is sufficient.
     *
     * If a payload somehow carries both names, the current name wins and
     * the stale one is dropped — unreachable through a sane client, but
     * it must be deterministic.
     *
     * **The ADR 0037 half is the same class of guard, for the opposite
     * reason.** Simply leaving a deleted field out of the map does NOT
     * drop its key: an unregistered key is an *unknown* key, and ADR
     * 0013 preserves those verbatim in `entry_data.fields`. So a client
     * still sending the deleted name would write it back into every new
     * entry indefinitely, and into existing ones on update — including
     * rows the purge cursor has already passed, which its single forward
     * pass will never revisit. The deletion would then never actually
     * complete in any observable sense. Stripping here is what bounds
     * the purge.
     *
     * @param  array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function canonicalise(array $fields): array
    {
        if (! $this->hasAliases() && ! $this->hasPendingDeletions()) {
            return $fields;
        }

        foreach ($this->canonicalByPreviousName as $previous => $current) {
            if (! array_key_exists($previous, $fields)) {
                continue;
            }
            if (! array_key_exists($current, $fields)) {
                $fields[$current] = $fields[$previous];
            }
            unset($fields[$previous]);
        }

        foreach ($this->pendingDeletionNames as $deleted) {
            unset($fields[$deleted]);
        }

        return $fields;
    }

    /** True if the field name exists in stardust_fields for this model, regardless of slot status. */
    public function isKnown(string $fieldName): bool
    {
        return array_key_exists($fieldName, $this->registeredFieldFilterability);
    }

    /**
     * True iff the field is registered for this model AND filterable.
     *
     * An unknown name returns `false`, making this a total predicate:
     * one check covers both "unknown payload key" and "JSON-only field",
     * which is exactly the set of names that must never reach a slot or
     * the exhaustion queue (ADR 0034).
     */
    public function isFilterable(string $fieldName): bool
    {
        return $this->registeredFieldFilterability[$fieldName] ?? false;
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
