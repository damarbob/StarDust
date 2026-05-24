<?php

declare(strict_types=1);

namespace StarDust\Read;

use PDO;

/**
 * Phase 4 Query 1 of the two-query bounded read of ADR 0005.
 *
 * Selects only `entry_data.id` for at most `pageSize + 1` rows that
 * match the tenant/model/cursor predicates plus every {@see QueryFilter}
 * clause. The `+1` row is the sole next-page signal; no separate COUNT
 * query is issued anywhere (ADR 0006).
 *
 * Caller contract: the {@see EntryQuery} is assumed already validated
 * against the {@see SnapshotEntry} by {@see QueryValidator}, so every
 * filter target here is guaranteed to be on an `assigned` or `ready`
 * slot whose `(tenant_id, slot_column)` composite index exists by
 * construction (ADR 0003). The probe never touches `entry_data.fields`
 * directly — JSON_EXTRACT is exclusively a Phase 4 assembly concern.
 *
 * Tenant isolation invariant per Architecture Blueprint §1.2: the
 * `tenant_id = ?` predicate is bound on `entry_data` AND on every
 * joined `entry_slots_page_N` so the composite indexes can serve both
 * sides of the JOIN.
 */
final class PaginatedProbe
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /**
     * Returns up to `pageSize + 1` entry IDs in ascending order.
     *
     * @return list<int>
     */
    public function probe(EntryQuery $query, SnapshotEntry $snapshot): array
    {
        $sql = $this->buildSql($query, $snapshot, $bindings);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($bindings);
        return array_map(static fn ($v): int => (int) $v, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param-out list<mixed> $bindings
     */
    private function buildSql(EntryQuery $query, SnapshotEntry $snapshot, ?array &$bindings): string
    {
        $bindings = [];
        $joins = [];
        /** @var array<int, string> $aliasByPage  pageId → join alias */
        $aliasByPage = [];

        // Build one INNER JOIN per distinct page referenced by the filter
        // set. Multiple filters on fields that share a page reuse the
        // same alias.
        foreach ($query->filters as $filter) {
            $descriptor = $snapshot->field($filter->fieldName);
            // QueryValidator guarantees a non-null descriptor with a
            // non-null pageId / slot_column here; assert defensively.
            if ($descriptor === null || $descriptor->pageId === null || $descriptor->slotColumn === null) {
                continue;
            }
            $pageId = $descriptor->pageId;
            if (isset($aliasByPage[$pageId])) {
                continue;
            }
            $alias = 'p' . count($aliasByPage);
            $aliasByPage[$pageId] = $alias;
            $tableName = $snapshot->pageTableNames[$pageId];
            // tenant_id on both sides of the JOIN — Architecture
            // Blueprint §1.2 requires this for the composite index to
            // serve both ends.
            $joins[] = "INNER JOIN {$tableName} {$alias}"
                . " ON {$alias}.entry_id = entry_data.id"
                . " AND {$alias}.tenant_id = entry_data.tenant_id";
        }

        $where = [
            'entry_data.tenant_id = ?',
            'entry_data.model_id = ?',
            'entry_data.deleted_at IS NULL',
            'entry_data.id > ?',
        ];
        $bindings[] = $query->tenantId;
        $bindings[] = $query->modelId;
        $bindings[] = $this->resolveCursorId($query);

        foreach ($query->filters as $filter) {
            $descriptor = $snapshot->field($filter->fieldName);
            if ($descriptor === null || $descriptor->pageId === null || $descriptor->slotColumn === null) {
                continue;
            }
            $alias = $aliasByPage[$descriptor->pageId];
            $where[] = $this->buildPredicate($alias, $descriptor->slotColumn, $filter, $bindings);
        }

        // LIMIT bound positionally as an int — emulated prepares are
        // off, so the placeholder is genuinely numeric, not stringified.
        $bindings[] = $query->pageSize + 1;

        return 'SELECT entry_data.id FROM entry_data'
            . ($joins === [] ? '' : ' ' . implode(' ', $joins))
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY entry_data.id ASC'
            . ' LIMIT ?';
    }

    /**
     * @param list<mixed> $bindings  appended in place
     */
    private function buildPredicate(
        string $alias,
        string $slotColumn,
        QueryFilter $filter,
        array &$bindings,
    ): string {
        $column = "{$alias}.{$slotColumn}";

        return match ($filter->operator) {
            'eq'  => $this->scalarPredicate($column, '=',  $filter->value, $bindings),
            'neq' => $this->scalarPredicate($column, '<>', $filter->value, $bindings),
            'lt'  => $this->scalarPredicate($column, '<',  $filter->value, $bindings),
            'lte' => $this->scalarPredicate($column, '<=', $filter->value, $bindings),
            'gt'  => $this->scalarPredicate($column, '>',  $filter->value, $bindings),
            'gte' => $this->scalarPredicate($column, '>=', $filter->value, $bindings),
            'prefix' => $this->prefixPredicate($column, (string) $filter->value, $bindings),
            'in'  => $this->inListPredicate($column, 'IN',     (array) $filter->value, $bindings),
            'nin' => $this->inListPredicate($column, 'NOT IN', (array) $filter->value, $bindings),
            'between' => $this->betweenPredicate($column, (array) $filter->value, $bindings),
            'is_null'     => "{$column} IS NULL",
            'is_not_null' => "{$column} IS NOT NULL",
        };
    }

    /** @param list<mixed> $bindings */
    private function scalarPredicate(string $column, string $op, mixed $value, array &$bindings): string
    {
        $bindings[] = $value;
        return "{$column} {$op} ?";
    }

    /** @param list<mixed> $bindings */
    private function prefixPredicate(string $column, string $prefix, array &$bindings): string
    {
        // Escape LIKE metacharacters so the consumer's literal prefix is
        // treated as one — `prefix` is a substring-match-from-start, not
        // a glob.
        $escaped = strtr($prefix, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
        $bindings[] = $escaped . '%';
        return "{$column} LIKE ? ESCAPE '\\\\'";
    }

    /**
     * @param list<mixed> $values
     * @param list<mixed> $bindings
     */
    private function inListPredicate(string $column, string $op, array $values, array &$bindings): string
    {
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        foreach ($values as $v) {
            $bindings[] = $v;
        }
        return "{$column} {$op} ({$placeholders})";
    }

    /**
     * @param list<mixed> $range
     * @param list<mixed> $bindings
     */
    private function betweenPredicate(string $column, array $range, array &$bindings): string
    {
        $bindings[] = $range[0];
        $bindings[] = $range[1];
        return "{$column} BETWEEN ? AND ?";
    }

    private function resolveCursorId(EntryQuery $query): int
    {
        if ($query->cursor === null) {
            return 0;
        }
        return CursorCodec::decode($query->cursor);
    }
}
