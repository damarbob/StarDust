<?php

declare(strict_types=1);

namespace StarDust\Search\Mysql;

use LogicException;
use StarDust\Filter\Ast\AndNode;
use StarDust\Filter\Ast\FilterNode;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Filter\Ast\NotNode;
use StarDust\Filter\Ast\OrNode;
use StarDust\Filter\Operator;
use StarDust\Read\EntryQuery;
use StarDust\Read\SnapshotEntry;

/**
 * Phase 8 adaptive SQL compiler for the bounded probe (ADR 0005 Query 1).
 *
 * Walks a {@see FilterNode} AST and emits the complete
 * `SELECT entry_data.id FROM …` shape. Two strategies coexist behind
 * one entry point:
 *
 *   - **JOIN strategy** (when the root filter is `null` or a pure-AND
 *     subtree of {@see LeafNode}s): one `INNER JOIN entry_slots_page_N`
 *     per distinct page referenced by the leaves; predicates ANDed in
 *     the outer `WHERE`. This is the verbatim Phase 4 shape and
 *     preserves the AC#4 composite-index range scan.
 *   - **EXISTS strategy** (any subtree contains an `OrNode` or
 *     `NotNode`): every leaf compiles to an `EXISTS (SELECT 1 FROM
 *     entry_slots_page_N s WHERE s.tenant_id = entry_data.tenant_id
 *     AND s.entry_id = entry_data.id AND <pred>)`. Composites compose
 *     with native SQL `AND` / `OR` / `NOT`. Each EXISTS still hits the
 *     composite index on its page.
 *
 * Tenant isolation invariant (Architecture Blueprint §1.2) preserved
 * on both strategies — `entry_data.tenant_id = ?` is bound at the
 * outer level and replayed inside every JOIN / EXISTS.
 *
 * The compiler assumes every {@see LeafNode}'s {@see \StarDust\Filter\Ast\FieldRef}
 * has been resolved (descriptor populated with `pageId` + `slotColumn`)
 * — pre-flight runs first. A leaf with an unresolved field is a logic
 * error, not a user error.
 */
final class SqlFilterCompiler
{
    public function compile(?FilterNode $filter, EntryQuery $query, SnapshotEntry $snapshot): SqlFragment
    {
        $bindings = [];

        $joinsSql      = '';
        $filterWhere   = '';
        $strategy      = $this->chooseStrategy($filter);

        if ($strategy === 'joins') {
            $leaves = $this->collectLeaves($filter);
            [$joinsSql, $filterWhere] = $this->compileAsJoins($leaves, $snapshot, $bindings);
        } else {
            // EXISTS strategy — $filter is guaranteed non-null because
            // chooseStrategy() never picks 'exists' on a null tree.
            assert($filter !== null);
            $filterWhere = $this->compileAsExists($filter, $snapshot, $bindings);
        }

        $whereClauses = [
            'entry_data.tenant_id = ?',
            'entry_data.model_id = ?',
            'entry_data.deleted_at IS NULL',
            'entry_data.id > ?',
        ];
        $bindings[] = $query->tenantId;
        $bindings[] = $query->modelId;
        $bindings[] = $this->cursorIdOf($query);

        if ($filterWhere !== '') {
            $whereClauses[] = $filterWhere;
        }

        $bindings[] = $query->pageSize + 1;

        $sql = 'SELECT entry_data.id FROM entry_data'
            . ($joinsSql === '' ? '' : ' ' . $joinsSql)
            . ' WHERE ' . implode(' AND ', $whereClauses)
            . ' ORDER BY entry_data.id ASC'
            . ' LIMIT ?';

        // Reorder bindings so they line up with the SQL string. The
        // outer WHERE / LIMIT bindings were appended *after* the
        // strategy bindings to keep the code linear, but the SQL string
        // emits tenant/model/cursor BEFORE the filter clause and LIMIT
        // AFTER. Splice the binding list into the right order.
        return new SqlFragment(
            sql:      $sql,
            bindings: $this->reorderBindings($bindings, $strategy, $filterWhere !== ''),
        );
    }

    public function chooseStrategy(?FilterNode $filter): string
    {
        if ($filter === null) {
            return 'joins';
        }
        return $this->containsDisjunction($filter) ? 'exists' : 'joins';
    }

    public function containsDisjunction(FilterNode $node): bool
    {
        if ($node instanceof OrNode || $node instanceof NotNode) {
            return true;
        }
        if ($node instanceof AndNode) {
            foreach ($node->args as $child) {
                if ($this->containsDisjunction($child)) {
                    return true;
                }
            }
        }
        // LeafNode never contains a disjunction.
        return false;
    }

    /**
     * Flattens a pure-AND tree into a list of leaves.
     *
     * @return list<LeafNode>
     */
    public function collectLeaves(?FilterNode $node): array
    {
        if ($node === null) {
            return [];
        }
        if ($node instanceof LeafNode) {
            return [$node];
        }
        if ($node instanceof AndNode) {
            $out = [];
            foreach ($node->args as $child) {
                foreach ($this->collectLeaves($child) as $leaf) {
                    $out[] = $leaf;
                }
            }
            return $out;
        }
        // chooseStrategy() guards against OR/NOT reaching here.
        throw new LogicException('collectLeaves only valid on pure-AND trees');
    }

    /**
     * @param list<LeafNode> $leaves
     * @param list<mixed>    $bindings (in/out)
     * @return array{0:string, 1:string} [joinsSql, predicatesSql]
     */
    private function compileAsJoins(array $leaves, SnapshotEntry $snapshot, array &$bindings): array
    {
        $joins = [];
        /** @var array<int, string> $aliasByPage */
        $aliasByPage = [];
        foreach ($leaves as $leaf) {
            $descriptor = $this->descriptorFor($leaf);
            $pageId = $descriptor->pageId;
            if (isset($aliasByPage[$pageId])) {
                continue;
            }
            $alias = 'p' . count($aliasByPage);
            $aliasByPage[$pageId] = $alias;
            $table = $snapshot->pageTableNames[$pageId];
            $joins[] = "INNER JOIN {$table} {$alias}"
                . " ON {$alias}.entry_id = entry_data.id"
                . " AND {$alias}.tenant_id = entry_data.tenant_id";
        }

        $predicates = [];
        foreach ($leaves as $leaf) {
            $descriptor = $this->descriptorFor($leaf);
            $alias = $aliasByPage[$descriptor->pageId];
            $column = "{$alias}.{$descriptor->slotColumn}";
            $predicates[] = $this->compileLeafPredicate($leaf, $column, $bindings);
        }

        return [implode(' ', $joins), implode(' AND ', $predicates)];
    }

    /**
     * @param list<mixed> $bindings (in/out)
     */
    private function compileAsExists(FilterNode $node, SnapshotEntry $snapshot, array &$bindings): string
    {
        if ($node instanceof LeafNode) {
            return $this->compileLeafAsExists($node, $snapshot, $bindings);
        }
        if ($node instanceof AndNode) {
            $parts = [];
            foreach ($node->args as $child) {
                $parts[] = $this->compileAsExists($child, $snapshot, $bindings);
            }
            return '(' . implode(' AND ', $parts) . ')';
        }
        if ($node instanceof OrNode) {
            $parts = [];
            foreach ($node->args as $child) {
                $parts[] = $this->compileAsExists($child, $snapshot, $bindings);
            }
            return '(' . implode(' OR ', $parts) . ')';
        }
        if ($node instanceof NotNode) {
            return 'NOT (' . $this->compileAsExists($node->arg, $snapshot, $bindings) . ')';
        }
        throw new LogicException('compileAsExists: unknown node type ' . $node::class);
    }

    /**
     * @param list<mixed> $bindings (in/out)
     */
    private function compileLeafAsExists(LeafNode $leaf, SnapshotEntry $snapshot, array &$bindings): string
    {
        $descriptor = $this->descriptorFor($leaf);
        $table = $snapshot->pageTableNames[$descriptor->pageId];
        $column = "s.{$descriptor->slotColumn}";
        $predicate = $this->compileLeafPredicate($leaf, $column, $bindings);
        return "EXISTS (SELECT 1 FROM {$table} s"
            . ' WHERE s.tenant_id = entry_data.tenant_id'
            . ' AND s.entry_id = entry_data.id'
            . " AND {$predicate})";
    }

    /**
     * Dispatch on operator; ported verbatim from Phase 4 PaginatedProbe.
     *
     * @param list<mixed> $bindings (in/out)
     */
    private function compileLeafPredicate(LeafNode $leaf, string $column, array &$bindings): string
    {
        return match ($leaf->operator) {
            Operator::EQ  => $this->scalarPredicate($column, '=',  $this->scalar($leaf), $bindings),
            Operator::NEQ => $this->scalarPredicate($column, '<>', $this->scalar($leaf), $bindings),
            Operator::LT  => $this->scalarPredicate($column, '<',  $this->scalar($leaf), $bindings),
            Operator::LTE => $this->scalarPredicate($column, '<=', $this->scalar($leaf), $bindings),
            Operator::GT  => $this->scalarPredicate($column, '>',  $this->scalar($leaf), $bindings),
            Operator::GTE => $this->scalarPredicate($column, '>=', $this->scalar($leaf), $bindings),
            Operator::PREFIX     => $this->prefixPredicate($column, (string) $this->scalar($leaf), $bindings),
            Operator::IN         => $this->inListPredicate($column, 'IN',     $this->listValue($leaf), $bindings),
            Operator::NIN        => $this->inListPredicate($column, 'NOT IN', $this->listValue($leaf), $bindings),
            Operator::BETWEEN    => $this->betweenPredicate($column, $this->listValue($leaf), $bindings),
            Operator::IS_NULL     => "{$column} IS NULL",
            Operator::IS_NOT_NULL => "{$column} IS NOT NULL",
            default => throw new LogicException("compiler reached operator '{$leaf->operator}' without a SQL emitter"),
        };
    }

    /** @param list<mixed> $bindings (in/out) */
    private function scalarPredicate(string $column, string $op, mixed $value, array &$bindings): string
    {
        $bindings[] = $value;
        return "{$column} {$op} ?";
    }

    /** @param list<mixed> $bindings (in/out) */
    private function prefixPredicate(string $column, string $prefix, array &$bindings): string
    {
        $escaped = strtr($prefix, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
        $bindings[] = $escaped . '%';
        return "{$column} LIKE ? ESCAPE '\\\\'";
    }

    /**
     * @param list<mixed> $values
     * @param list<mixed> $bindings (in/out)
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
     * @param list<mixed> $bindings (in/out)
     */
    private function betweenPredicate(string $column, array $range, array &$bindings): string
    {
        $bindings[] = $range[0];
        $bindings[] = $range[1];
        return "{$column} BETWEEN ? AND ?";
    }

    private function scalar(LeafNode $leaf): mixed
    {
        if ($leaf->value === null) {
            throw new LogicException("operator '{$leaf->operator}' requires a value");
        }
        return $leaf->value->value;
    }

    /**
     * @return list<mixed>
     */
    private function listValue(LeafNode $leaf): array
    {
        $v = $this->scalar($leaf);
        if (!is_array($v)) {
            throw new LogicException("operator '{$leaf->operator}' requires a list value");
        }
        /** @var list<mixed> $v */
        return $v;
    }

    private function descriptorFor(LeafNode $leaf): \StarDust\Read\FieldDescriptor
    {
        $d = $leaf->field->descriptor;
        if ($d === null || $d->pageId === null || $d->slotColumn === null) {
            throw new LogicException(
                "leaf for field '{$leaf->field->fieldName}' reached the compiler unresolved"
            );
        }
        return $d;
    }

    private function cursorIdOf(EntryQuery $query): int
    {
        if ($query->cursor === null) {
            return 0;
        }
        return \StarDust\Read\CursorCodec::decode($query->cursor);
    }

    /**
     * Reorders the accumulated binding list so it matches the SQL
     * placeholder order: outer (tenant, model, cursor) → filter clause
     * bindings → outer LIMIT. The strategy compilers append filter
     * bindings first because they emit the WHERE fragment first, but
     * the SQL string emits the outer clause first.
     *
     * @param list<mixed> $bindings
     * @return list<mixed>
     */
    private function reorderBindings(array $bindings, string $strategy, bool $hasFilter): array
    {
        $total = count($bindings);
        // Layout in $bindings as appended by compile():
        //   [ ...filterBindings, tenantId, modelId, cursorId, pageSize+1 ]
        // Need:
        //   [ tenantId, modelId, cursorId, ...filterBindings, pageSize+1 ]
        $tail = array_slice($bindings, $total - 4); // tenantId, modelId, cursorId, pageSize+1
        $filterBindings = array_slice($bindings, 0, $total - 4);
        return [
            $tail[0], // tenantId
            $tail[1], // modelId
            $tail[2], // cursorId
            ...$filterBindings,
            $tail[3], // pageSize+1
        ];
    }
}
