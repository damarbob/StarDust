<?php

declare(strict_types=1);

namespace StarDust\Schema;

use PDO;

/**
 * Read-only introspection over the schema registry.
 *
 * Answers the two questions every consumer of a dynamic-field engine
 * has to ask — "what models does this tenant have?" and "what fields
 * does this model have, and which can I filter on?" — without the
 * caller hand-writing SQL against `stardust_models` /
 * `stardust_fields`.
 *
 * ## Why this is not on `SchemaBuilder`
 *
 * `SchemaBuilder` is the *write* helper and is deliberately kept
 * narrow. Introspection is a separate concern with a separate
 * lifetime: it is safe to call on every request, takes no locks,
 * touches no `stardust_schema_version`, and never mutates. Keeping the
 * two apart is what lets `SchemaBuilder` stay a stopgap that a future
 * first-class definition API can replace without dragging the read
 * side along with it.
 *
 * ## Tenant scoping
 *
 * Both methods take `tenantId` and filter on it. `stardust_fields` has
 * no `tenant_id` column of its own — tenancy reaches a field only
 * through `model_id → stardust_models.tenant_id` — so
 * {@see self::describeModel()} resolves the model under the tenant
 * predicate first and returns `null` if that fails, rather than
 * joining tenancy into the field query. A model belonging to another
 * tenant is indistinguishable from one that does not exist.
 */
final class SchemaReader
{
    /**
     * Slot statuses that make a field *queryable*.
     *
     * Narrower than {@see \StarDust\Write\LiveSlotMap::LIVE_STATUSES},
     * which also counts `backfilling`. The write path materialises into
     * a backfilling slot, but the read path will not filter against one
     * (ADR 0004), so for introspection — whose whole job is telling a
     * caller what it may filter on — `backfilling` is not indexed. Same
     * set `SpreadSampler` uses, for the same reason.
     */
    private const QUERYABLE_STATUSES = ['assigned', 'ready'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Every model registered for this tenant, ordered by id.
     *
     * @return list<ModelSummary>
     */
    public function listModels(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name FROM stardust_models WHERE tenant_id = ? ORDER BY id'
        );
        $stmt->execute([$tenantId]);

        $models = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $models[] = new ModelSummary(
                modelId: (int) $row['id'],
                name: (string) $row['name'],
            );
        }

        return $models;
    }

    /**
     * One model and all of its fields, or `null` when the model does
     * not exist for this tenant.
     */
    public function describeModel(int $tenantId, int $modelId): ?ModelDescription
    {
        $model = $this->pdo->prepare(
            'SELECT id, name FROM stardust_models WHERE id = ? AND tenant_id = ?'
        );
        $model->execute([$modelId, $tenantId]);
        $modelRow = $model->fetch(PDO::FETCH_ASSOC);

        if ($modelRow === false) {
            return null;
        }

        return new ModelDescription(
            modelId: (int) $modelRow['id'],
            name: (string) $modelRow['name'],
            fields: $this->fieldsFor($modelId),
        );
    }

    /**
     * The LEFT JOIN is constrained to the queryable statuses, so it
     * yields at most one row per field: ADR 0017's partial unique index
     * already permits only one *live* slot per field, and the queryable
     * set is a subset of live. That is what lets this be a plain join
     * with no GROUP BY.
     *
     * @return list<FieldDescription>
     */
    private function fieldsFor(int $modelId): array
    {
        $placeholders = implode(',', array_fill(0, count(self::QUERYABLE_STATUSES), '?'));

        $stmt = $this->pdo->prepare(
            'SELECT f.id, f.name, f.declared_type, f.is_filterable,'
            . ' a.id AS slot_assignment_id'
            . ' FROM stardust_fields f'
            . ' LEFT JOIN stardust_slot_assignments a'
            . "   ON a.field_id = f.id AND a.status IN ({$placeholders})"
            . ' WHERE f.model_id = ?'
            . ' ORDER BY f.id'
        );
        $stmt->execute(array_merge(self::QUERYABLE_STATUSES, [$modelId]));

        $fields = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $fields[] = new FieldDescription(
                fieldId: (int) $row['id'],
                name: (string) $row['name'],
                declaredType: (string) $row['declared_type'],
                isFilterable: (bool) $row['is_filterable'],
                isIndexed: $row['slot_assignment_id'] !== null,
            );
        }

        return $fields;
    }
}
