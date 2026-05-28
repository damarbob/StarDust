<?php

declare(strict_types=1);

namespace StarDust\Read;

use PDO;
use StarDust\Search\Mysql\SqlFilterCompiler;

/**
 * Phase 4 Query 1 of the two-query bounded read of ADR 0005.
 *
 * Selects only `entry_data.id` for at most `pageSize + 1` rows that
 * match the tenant/model/cursor predicates plus the filter AST.
 * The `+1` row is the sole next-page signal; no separate COUNT query
 * is issued anywhere (ADR 0006).
 *
 * Phase 8 moved the SQL building into {@see SqlFilterCompiler}; this
 * class is the thin call site that prepares the compiled fragment and
 * marshals the result rows. The compiler chooses between the JOIN
 * strategy (pure-AND, preserves Phase 4's composite-index range scan)
 * and the EXISTS strategy (any subtree contains `OrNode` / `NotNode`).
 *
 * Caller contract: {@see EntryQuery::$filter} must be either `null` or
 * pre-flight-validated with every {@see \StarDust\Filter\Ast\LeafNode}
 * carrying a resolved {@see \StarDust\Filter\Ast\FieldRef} (descriptor
 * populated). The probe never touches `entry_data.fields` directly —
 * JSON_EXTRACT is exclusively an assembly concern.
 */
final class PaginatedProbe
{
    private readonly SqlFilterCompiler $compiler;

    public function __construct(
        private readonly PDO $pdo,
        ?SqlFilterCompiler $compiler = null,
    ) {
        $this->compiler = $compiler ?? new SqlFilterCompiler();
    }

    /**
     * Returns up to `pageSize + 1` entry IDs in ascending order.
     *
     * @return list<int>
     */
    public function probe(EntryQuery $query, SnapshotEntry $snapshot): array
    {
        $fragment = $this->compiler->compile($query->filter, $query, $snapshot);
        $stmt = $this->pdo->prepare($fragment->sql);
        $stmt->execute($fragment->bindings);
        return array_map(static fn ($v): int => (int) $v, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
