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
use StarDust\Read\CursorCodec;
use StarDust\Read\EntryQuery;
use StarDust\Read\NullPlacement;
use StarDust\Read\SnapshotEntry;
use StarDust\Read\SortDirection;
use StarDust\Read\SortSpec;
use StarDust\Read\SortTarget;
use StarDust\Read\StringKey;
use StarDust\Support\StringKeyLayout;

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
 * **Driving leaf (ADR 0056).** A filtered read in the default order used
 * to lead with `entry_data` and order by `entry_data.id`, so on MySQL an
 * equality matching a few percent of a tenant read the whole match set and
 * sorted it to find one page. When the filter takes the JOIN strategy, the
 * order is the default (or `Id`), and some leaf is an equality on a
 * full-width slot column (int, numeric or datetime) whose index the
 * snapshot knows, the query is emitted as
 * `SELECT STRAIGHT_JOIN … FROM {driving page} FORCE INDEX (…) INNER JOIN
 * entry_data … ORDER BY {driving}.entry_id`. Everything else keeps its
 * SQL byte for byte. Each part is there for a measured reason:
 *
 *   - `STRAIGHT_JOIN` with the driving page first. `FORCE INDEX` alone
 *     leaves `FROM entry_data` first, and the optimiser starts there and
 *     sorts the join: one equality matching 50% went from 222 rows to
 *     200 001 on 8.0.46.
 *   - `FORCE INDEX`. With a string leaf in the same AND, ordering by the
 *     page without it touched 13 618 rows against 768 with it.
 *   - The index name is the snapshot's, from the data dictionary, never the
 *     `ix_{table}_{column}` convention. Naming a missing index fails the
 *     read (errno 1176).
 *   - Equality only. `in` must not take the shape (238 rows became 200 001),
 *     and a range or `prefix` leaf gains nothing from it. A string equality
 *     drives only through its generated key (ADR 0057), and only when no
 *     integer, numeric or datetime equality qualified, so every query that
 *     drives today keeps its plan. A string leaf on a prefix page never
 *     drives, even when it comes first.
 *
 * The bound is the driving leaf's match count, not the page size, once
 * other leaves filter its rows further. The driving leaf is the first
 * qualifying one in filter order, not the most selective, so a common leaf
 * written before a rare one reads far more (150 025 rows against 31 in the
 * widest case measured). MariaDB already plans the old shape well and can
 * plan it better than this rule, so a green MariaDB run proves nothing
 * about the rule.
 *
 * **String keys (ADR 0057).** On a page that indexes a string slot through a
 * generated key, a JOIN-strategy leaf on that slot gets a key predicate ahead
 * of its original one: `{key predicate} AND {original}`. The key predicate only
 * has to find a superset of the matches, and the original always stays as the
 * recheck, so the result is whatever the collation says. `neq` and `nin` get
 * none, since they cannot use an index either way, and neither do `is_null` and
 * `is_not_null`, whose key rewrite changed no plan and cost one on MySQL 8.0.13.
 * The EXISTS strategy keeps
 * the SQL a string leaf had before keys, because it reaches the page by primary
 * key per entry and a key adds nothing there. A slot the snapshot records no
 * key for, whether it sits on a prefix page, a page from before pages carried
 * only their indexed columns or behind a key index the optimiser skips, keeps
 * its SQL byte for byte. {@see StringKeyLayout} builds every key predicate, and
 * its docblock lists the three ways a slightly wrong one returns wrong rows
 * without an error. A prefix or range bound narrows the index only within the
 * key's head, so a longer bound on a column whose values all share that head can
 * no longer narrow it.
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
        $strategy = $this->chooseStrategy($filter);

        /**
         * Bindings are collected per SQL clause rather than in one flat
         * list, then concatenated in clause order at the end. The old
         * flat list plus a fixed four-element tail splice worked only
         * while every query had exactly the same outer shape; a sort
         * adds an anchor subquery in the FROM clause — ahead of every
         * other binding — and makes the keyset clause variable-length.
         *
         * @var list<mixed> $filterBindings
         */
        $filterBindings = [];

        $joinsSql    = '';
        $filterWhere = '';
        /** @var array<int, string> $aliasByPage */
        $aliasByPage = [];
        /** @var ?array{pageId: int, indexName: string} $driving */
        $driving = null;

        $sort = $query->sort;

        if ($strategy === 'joins') {
            $leaves = $this->collectLeaves($filter);
            // Only the default order qualifies: a field sort has to order by
            // its own column, and `created_at` by the core table's.
            if ($sort === null || $sort->target === SortTarget::Id) {
                $driving = $this->selectDrivingLeaf($leaves, $snapshot);
            }
            [$joinsSql, $filterWhere, $aliasByPage] = $this->compileAsJoins(
                $leaves,
                $snapshot,
                $filterBindings,
                $driving['pageId'] ?? null,
            );
        } else {
            // EXISTS strategy — $filter is guaranteed non-null because
            // chooseStrategy() never picks 'exists' on a null tree.
            assert($filter !== null);
            $filterWhere = $this->compileAsExists($filter, $snapshot, $filterBindings);
        }

        // The column the default order, and its keyset, run on. With a driving
        // leaf it is the driving page's `entry_id`, which the INNER JOIN holds
        // equal to `entry_data.id`, so the ordering and the cursor are unchanged.
        $orderColumn = $driving === null
            ? 'entry_data.id'
            : $aliasByPage[$driving['pageId']] . '.entry_id';

        // The sort column must be reachable from the outer query, so a
        // field sort joins its page whatever the strategy chose — you
        // cannot ORDER BY a column that only exists inside an EXISTS
        // subquery.
        [$sortJoinSql, $sortExpression] = $this->compileSortTarget($sort, $snapshot, $aliasByPage);
        $joinsSql = trim($joinsSql . ' ' . $sortJoinSql);

        $anchorBindings = [];
        $keysetBindings = [];
        $anchorJoinSql  = '';
        $keysetWhere    = '';

        if ($query->cursor !== null) {
            $anchorId = CursorCodec::decodePayload($query->cursor)->entryId;

            if ($sortExpression === null) {
                // Sorting by entry_data.id: the cursor *is* the sort
                // value, so no anchor lookup is needed at all.
                $keysetWhere      = $orderColumn . ' ' . $this->directionOf($sort)->keysetOperator() . ' ?';
                $keysetBindings[] = $anchorId;
            } else {
                [$anchorJoinSql, $anchorBindings] =
                    $this->compileAnchorJoin($sort, $snapshot, $anchorId, $query->tenantId);
                $keysetWhere      = $this->compileKeysetPredicate(
                    $sortExpression,
                    $this->directionOf($sort),
                    $this->nullsOf($sort),
                );
                $keysetBindings[] = $anchorId;
            }
        }

        $whereClauses = [
            'entry_data.tenant_id = ?',
            'entry_data.model_id = ?',
        ];
        $outerBindings = [$query->tenantId, $query->modelId];

        if ($driving !== null) {
            // Stated on the driving page too, so its index range is bounded
            // by both key parts rather than by `tenant_id` through the join.
            // The placeholder sits ahead of the keyset and filter ones, which
            // is where its binding goes.
            $whereClauses[] = $aliasByPage[$driving['pageId']] . '.tenant_id = ?';
            $outerBindings[] = $query->tenantId;
        }

        $whereClauses[] = 'entry_data.deleted_at IS NULL';

        if ($keysetWhere !== '') {
            $whereClauses[] = $keysetWhere;
        }
        if ($filterWhere !== '') {
            $whereClauses[] = $filterWhere;
        }

        $orderBy = $driving === null
            ? $this->compileOrderBy($sortExpression, $this->directionOf($sort), $this->nullsOf($sort))
            : $orderColumn . ' ' . $this->directionOf($sort)->sql();

        $sql = ($driving === null
                ? 'SELECT entry_data.id FROM entry_data'
                : $this->compileDrivingFrom($driving, $aliasByPage[$driving['pageId']], $snapshot))
            . ($joinsSql === '' ? '' : ' ' . $joinsSql)
            . ($anchorJoinSql === '' ? '' : ' ' . $anchorJoinSql)
            . ' WHERE ' . implode(' AND ', $whereClauses)
            . ' ORDER BY ' . $orderBy
            . ' LIMIT ?';

        return new SqlFragment(
            sql:      $sql,
            // Clause order in the emitted SQL: FROM (anchor) → WHERE
            // (tenant, model, keyset, filter) → LIMIT.
            bindings: [
                ...$anchorBindings,
                ...$outerBindings,
                ...$keysetBindings,
                ...$filterBindings,
                $query->pageSize + 1,
            ],
        );
    }

    /**
     * Resolves the sort target to an outer-query SQL expression, adding
     * a join when the target lives on an extension page.
     *
     * Returns `[joinSql, expression]`. A `null` expression means "order
     * by entry_data.id alone" — the default, and the one case needing
     * neither a join nor an anchor lookup.
     *
     * @param array<int, string> $aliasByPage pages the filter already joined
     * @return array{0:string, 1:?string}
     */
    private function compileSortTarget(
        ?SortSpec $sort,
        SnapshotEntry $snapshot,
        array $aliasByPage,
    ): array {
        if ($sort === null || $sort->target === SortTarget::Id) {
            return ['', null];
        }
        if ($sort->target === SortTarget::CreatedAt) {
            return ['', 'entry_data.created_at'];
        }

        [$pageId, $slotColumn] = $this->resolvedSortSlot($sort, $snapshot);

        // Reuse the filter's join when it already covers this page —
        // adding a second join to the same table would multiply nothing
        // but would cost a redundant lookup per row.
        if (isset($aliasByPage[$pageId])) {
            return ['', $aliasByPage[$pageId] . '.' . $slotColumn];
        }

        $table = $snapshot->pageTableNames[$pageId];
        $alias = 'sp';
        // LEFT, not INNER. The filter's joins are INNER because a filter
        // demands a match; the sort's must not drop rows that simply have
        // no row on this page — that would silently shrink the result set
        // to "entries that happen to have a value for the sort field".
        $join = "LEFT JOIN {$table} {$alias}"
            . " ON {$alias}.entry_id = entry_data.id"
            . " AND {$alias}.tenant_id = entry_data.tenant_id";

        return [$join, "{$alias}.{$slotColumn}"];
    }

    /**
     * Builds the one-row derived table holding the anchor row's sort
     * value, so the keyset predicate can reference it three times
     * without repeating the subquery or correlating per row.
     *
     * Written as `SELECT (SELECT …) AS av` rather than `SELECT … FROM …`
     * deliberately: the inner form yields **zero** rows when the anchor
     * has no row on that page, and a CROSS JOIN against zero rows
     * annihilates the whole result set. The scalar-subquery form always
     * yields exactly one row, NULL when there is nothing to find — which
     * the predicate already handles as the NULL block.
     *
     * @return array{0:string, 1:list<mixed>}
     */
    private function compileAnchorJoin(
        ?SortSpec $sort,
        SnapshotEntry $snapshot,
        int $anchorId,
        int $tenantId,
    ): array {
        assert($sort !== null);

        if ($sort->target === SortTarget::CreatedAt) {
            $inner = 'SELECT created_at FROM entry_data WHERE id = ? AND tenant_id = ?';
        } else {
            [$pageId, $slotColumn] = $this->resolvedSortSlot($sort, $snapshot);
            $table = $snapshot->pageTableNames[$pageId];
            $inner = "SELECT {$slotColumn} FROM {$table} WHERE entry_id = ? AND tenant_id = ?";
        }

        return [
            "CROSS JOIN (SELECT ({$inner}) AS av) sort_anchor",
            [$anchorId, $tenantId],
        ];
    }

    /**
     * The keyset predicate for a non-id sort.
     *
     * Three branches, and every one of them is load-bearing because a
     * slot column is nullable — a row whose value has not been mirrored
     * into its slot (an ADR 0007 exhaustion fallback still awaiting the
     * Reconciler) reads NULL here. MySQL sorts NULL first ascending and
     * last descending, so the NULL block has to be walked in the right
     * place rather than dropped: `col > NULL` is UNKNOWN, and a naive
     * two-branch predicate silently loses every NULL row.
     *
     * `<=>` is the NULL-safe equality that makes the tiebreak branch work
     * inside the NULL block itself.
     *
     * Only the first branch depends on where the NULL block sits, so it
     * follows the placement. The direction decides the comparison operator
     * and nothing else.
     */
    private function compileKeysetPredicate(
        string $sortExpression,
        SortDirection $direction,
        NullPlacement $nulls,
    ): string {
        $op = $direction->keysetOperator();

        if ($nulls === NullPlacement::First) {
            // NULLs first: from a NULL anchor, every non-NULL row is still ahead.
            $leadingBlock = "(sort_anchor.av IS NULL AND {$sortExpression} IS NOT NULL)";
        } else {
            // NULLs last: from a non-NULL anchor, the NULL block is still ahead.
            $leadingBlock = "(sort_anchor.av IS NOT NULL AND {$sortExpression} IS NULL)";
        }

        return '('
            . $leadingBlock
            . " OR (sort_anchor.av IS NOT NULL AND {$sortExpression} {$op} sort_anchor.av)"
            . " OR ({$sortExpression} <=> sort_anchor.av AND entry_data.id {$op} ?)"
            . ')';
    }

    private function compileOrderBy(?string $sortExpression, SortDirection $direction, NullPlacement $nulls): string
    {
        $dir = $direction->sql();

        // entry_data.id always trails in the same direction. It is the
        // tiebreak that makes the ordering total, which is what makes the
        // cursor stable — without it two rows sharing a sort value could
        // swap between pages and be returned twice or never.
        if ($sortExpression === null) {
            return "entry_data.id {$dir}";
        }

        // The database's own placement needs no help, so that SQL stays as it
        // was. The other one is asked for with an IS NULL key ahead of the
        // sort key, where 1 sorts after 0.
        $nullKey = $nulls === NullPlacement::nativeFor($direction)
            ? ''
            : $sortExpression . ' IS NULL ' . ($nulls === NullPlacement::First ? 'DESC' : 'ASC') . ', ';

        return "{$nullKey}{$sortExpression} {$dir}, entry_data.id {$dir}";
    }

    private function directionOf(?SortSpec $sort): SortDirection
    {
        return $sort === null ? SortDirection::Asc : $sort->direction;
    }

    private function nullsOf(?SortSpec $sort): NullPlacement
    {
        return $sort === null ? NullPlacement::First : $sort->effectiveNulls();
    }

    /**
     * @return array{0:int, 1:string} [pageId, slotColumn]
     */
    private function resolvedSortSlot(SortSpec $sort, SnapshotEntry $snapshot): array
    {
        $fieldName  = $sort->fieldNameOrFail();
        $descriptor = $snapshot->field($fieldName);

        // Pre-flight resolved this field and the driver declared it
        // sortable, so a miss here is engine state disagreeing with
        // itself rather than a caller error.
        if ($descriptor === null || $descriptor->pageId === null || $descriptor->slotColumn === null) {
            throw new LogicException(
                "SqlFilterCompiler reached sort field '{$fieldName}' with no resolved slot."
            );
        }

        return [$descriptor->pageId, $descriptor->slotColumn];
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
     * Slot column prefixes of the families whose index holds the whole value.
     * A string slot on a prefix page is indexed on a prefix, which cannot
     * answer an equality alone, so it does not qualify here. On a key page it
     * qualifies in the second pass of {@see self::selectDrivingLeaf()}. The
     * family comes from the physical column, because that is what the index
     * covers.
     */
    private const FULL_WIDTH_SLOT_PREFIXES = ['i_int_', 'i_num_', 'i_dt_'];

    /**
     * The leaf a default-order read is driven from, or null when none qualifies
     * (ADR 0056). That is the first equality on a full-width slot column whose
     * index the snapshot recorded. Any qualifying leaf measured bounded, so
     * "first" is only the deterministic choice, not the cheapest one.
     *
     * When none qualifies, the first equality on a string slot whose page
     * indexes it through a generated key does (ADR 0057), and the index is the
     * key's. Numbers and dates go first so that no query that drives today
     * changes its plan.
     *
     * @param list<LeafNode> $leaves
     * @return ?array{pageId: int, indexName: string}
     */
    private function selectDrivingLeaf(array $leaves, SnapshotEntry $snapshot): ?array
    {
        foreach ($leaves as $leaf) {
            if ($leaf->operator !== Operator::EQ) {
                continue;
            }
            [$pageId, $slotColumn] = $this->resolvedSlotFor($leaf);
            if (! $this->isFullWidthSlot($slotColumn)) {
                continue;
            }
            $indexName = $snapshot->orderedIndexFor($pageId, $slotColumn);
            if ($indexName === null) {
                continue;
            }
            return ['pageId' => $pageId, 'indexName' => $indexName];
        }

        foreach ($leaves as $leaf) {
            if ($leaf->operator !== Operator::EQ) {
                continue;
            }
            [$pageId, $slotColumn] = $this->resolvedSlotFor($leaf);
            $key = $snapshot->stringKeyFor($pageId, $slotColumn);
            if ($key === null) {
                continue;
            }
            return ['pageId' => $pageId, 'indexName' => $key->indexName];
        }

        return null;
    }

    private function isFullWidthSlot(string $slotColumn): bool
    {
        foreach (self::FULL_WIDTH_SLOT_PREFIXES as $prefix) {
            if (str_starts_with($slotColumn, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The select list and `FROM` of a driven query: the driving page first,
     * under `STRAIGHT_JOIN` so the order is the one written, then `entry_data`.
     * The other pages are joined after it and their `ON` clauses, which name
     * `entry_data`, need no change.
     *
     * The index name is quoted as an identifier, doubling any backtick,
     * because it comes from the data dictionary and an operator could have
     * named it anything.
     *
     * @param array{pageId: int, indexName: string} $driving
     */
    private function compileDrivingFrom(array $driving, string $alias, SnapshotEntry $snapshot): string
    {
        $table = $snapshot->pageTableNames[$driving['pageId']];
        $index = '`' . str_replace('`', '``', $driving['indexName']) . '`';

        return "SELECT STRAIGHT_JOIN entry_data.id FROM {$table} {$alias} FORCE INDEX ({$index})"
            . " INNER JOIN entry_data ON entry_data.id = {$alias}.entry_id"
            . " AND entry_data.tenant_id = {$alias}.tenant_id";
    }

    /**
     * @param list<LeafNode> $leaves
     * @param list<mixed>    $bindings (in/out)
     * @param ?int           $drivingPageId the page {@see self::compileDrivingFrom()} puts in
     *                       `FROM`; it keeps its alias but gets no `INNER JOIN` here
     * @return array{0:string, 1:string, 2:array<int, string>} [joinsSql, predicatesSql, aliasByPage]
     */
    private function compileAsJoins(
        array $leaves,
        SnapshotEntry $snapshot,
        array &$bindings,
        ?int $drivingPageId = null,
    ): array {
        $joins = [];
        /** @var array<int, string> $aliasByPage */
        $aliasByPage = [];
        foreach ($leaves as $leaf) {
            [$pageId] = $this->resolvedSlotFor($leaf);
            if (isset($aliasByPage[$pageId])) {
                continue;
            }
            $alias = 'p' . count($aliasByPage);
            $aliasByPage[$pageId] = $alias;
            if ($pageId === $drivingPageId) {
                continue;
            }
            $table = $snapshot->pageTableNames[$pageId];
            $joins[] = "INNER JOIN {$table} {$alias}"
                . " ON {$alias}.entry_id = entry_data.id"
                . " AND {$alias}.tenant_id = entry_data.tenant_id";
        }

        $predicates = [];
        foreach ($leaves as $leaf) {
            [$pageId, $slotColumn] = $this->resolvedSlotFor($leaf);
            $predicates[] = $this->compileJoinedLeaf(
                $leaf,
                $aliasByPage[$pageId],
                $slotColumn,
                $snapshot->stringKeyFor($pageId, $slotColumn),
                $bindings,
            );
        }

        // The alias map is returned so a field sort can reuse a page the
        // filter already joined instead of joining it twice.
        return [implode(' ', $joins), implode(' AND ', $predicates), $aliasByPage];
    }

    /**
     * One leaf of the JOIN strategy. A string slot that its page indexes through
     * a key gets the key predicate first and the original after it, which stays
     * as the recheck. The key predicate's bindings are pushed first, because
     * that is the order its placeholders come in.
     *
     * @param list<mixed> $bindings (in/out)
     */
    private function compileJoinedLeaf(
        LeafNode $leaf,
        string $alias,
        string $slotColumn,
        ?StringKey $key,
        array &$bindings,
    ): string {
        $column = "{$alias}.{$slotColumn}";
        $keyPredicate = $key === null ? null : $this->keyPredicate($leaf, "{$alias}.{$key->keyColumn}", $key);
        if ($keyPredicate === null) {
            return $this->compileLeafPredicate($leaf, $column, $bindings);
        }

        array_push($bindings, ...$keyPredicate['bindings']);

        return $keyPredicate['sql'] . ' AND ' . $this->compileLeafPredicate($leaf, $column, $bindings);
    }

    /**
     * The predicate on a string slot's key that finds a superset of what the
     * leaf matches, or null for an operator no key can serve.
     *
     * @return ?array{sql: string, bindings: list<string>}
     */
    private function keyPredicate(LeafNode $leaf, string $keyColumn, StringKey $key): ?array
    {
        $head = $key->headBytes;
        $collation = $key->collation;

        switch ($leaf->operator) {
            case Operator::EQ:
                return StringKeyLayout::equals($keyColumn, $this->stringOperand($leaf), $head, $collation);
            case Operator::IN:
                $operands = $this->stringOperands($leaf);

                return $operands === [] ? null : StringKeyLayout::inList($keyColumn, $operands, $head, $collation);
            case Operator::PREFIX:
                // The prefix as written. The escaping is for the LIKE recheck only.
                return StringKeyLayout::prefix($keyColumn, $this->stringOperand($leaf), $head, $collation);
            case Operator::GT:
            case Operator::GTE:
                return StringKeyLayout::atLeast($keyColumn, $this->stringOperand($leaf), $head, $collation);
            case Operator::LT:
            case Operator::LTE:
                return StringKeyLayout::atMost($keyColumn, $this->stringOperand($leaf), $head, $collation);
            case Operator::BETWEEN:
                $range = $this->stringOperands($leaf);

                return count($range) === 2
                    ? StringKeyLayout::between($keyColumn, $range[0], $range[1], $head, $collation)
                    : null;
            default:
                // neq and nin cannot use an index, with or without a key. is_null
                // and is_not_null keep their own SQL: asking the key column too
                // changed no plan on 8.0.46 or MariaDB and made is_not_null slower
                // on 8.0.13.
                return null;
        }
    }

    private function stringOperand(LeafNode $leaf): string
    {
        return (string) $this->scalar($leaf);
    }

    /**
     * @return list<string>
     */
    private function stringOperands(LeafNode $leaf): array
    {
        return array_map('strval', $this->listValue($leaf));
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
        [$pageId, $slotColumn] = $this->resolvedSlotFor($leaf);
        $table = $snapshot->pageTableNames[$pageId];
        $column = "s.{$slotColumn}";
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
        $v = $leaf->value->value;
        return is_array($v) ? $v : $this->toMysqlLiteral($leaf, $v);
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
        $literals = [];
        foreach ($v as $element) {
            $literals[] = $this->toMysqlLiteral($leaf, $element);
        }
        return $literals;
    }

    /**
     * Renders a pre-flight-normalised value as the literal MySQL wants.
     *
     * Only `datetime` moves. `ValueTypeValidator` hands the driver
     * canonical UTC RFC 3339 (`…T03:00:00Z`) because the AST is
     * driver-neutral; a `DATETIME` column wants `Y-m-d H:i:s`. MySQL
     * does accept the RFC 3339 form — it truncates at the zone
     * designator and still plans a range scan — but raises warning 1292
     * `Incorrect datetime value` every time, so converting here keeps
     * the emitted SQL clean as well as correct.
     *
     * Fractional seconds are preserved: measured on 8.0.13, a
     * fractional constant compares exactly against a second-precision
     * column, so truncating would break `lt` / `gt` at the boundary.
     *
     * Every bound reaches the bindings list through {@see scalar()} or
     * {@see listValue()}, so those two call sites cover the scalar,
     * set (`in` / `nin`) and range (`between`) paths alike.
     *
     * **Converting only a value that carries a zone designator is what
     * makes this idempotent**, and that is load-bearing rather than
     * defensive: the output carries none, so a second application is a
     * no-op. Without the guard, re-converting `'2026-01-01 03:00:00'`
     * would parse it in PHP's default timezone and then shift it to
     * UTC — a silent offset, on a machine configured for anything but
     * UTC, introduced by nothing worse than an extra call.
     */
    private function toMysqlLiteral(LeafNode $leaf, mixed $value): mixed
    {
        if (!is_string($value) || $leaf->field->descriptor?->declaredType !== 'datetime') {
            return $value;
        }
        if (preg_match('/(?:Z|[+\-]\d{2}:\d{2})$/', $value) !== 1) {
            return $value;
        }
        try {
            $parsed = new \DateTimeImmutable($value);
        } catch (\Throwable) {
            // Unreachable through pre-flight, which has already parsed
            // this value. Leaving it verbatim keeps a driver called
            // directly behaving exactly as it did before.
            return $value;
        }
        // A no-op on a pre-flight bound, which is already `Z`. It is
        // what makes a direct caller's own offset correct too, rather
        // than silently formatting its wall clock.
        $utc = $parsed->setTimezone(new \DateTimeZone('UTC'));
        $micros = $utc->format('u');
        return $utc->format('Y-m-d H:i:s') . ($micros === '000000' ? '' : '.' . $micros);
    }

    /**
     * Resolves a leaf to its `[pageId, slotColumn]` pair.
     *
     * Returns the two values rather than the descriptor because both are
     * `?int` / `?string` on `FieldDescriptor`, so handing back the object
     * would lose the guarantee this method just established and push a
     * permanently-false null check onto every call site. Pre-flight has
     * already rejected unresolved leaves; reaching the null branch means
     * something bypassed it, which is why it throws rather than degrades.
     *
     * @return array{int, string}
     */
    private function resolvedSlotFor(LeafNode $leaf): array
    {
        $d = $leaf->field->descriptor;
        if ($d === null || $d->pageId === null || $d->slotColumn === null) {
            throw new LogicException(
                "leaf for field '{$leaf->field->fieldName}' reached the compiler unresolved"
            );
        }
        return [$d->pageId, $d->slotColumn];
    }


}
