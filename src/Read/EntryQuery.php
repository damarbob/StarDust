<?php

declare(strict_types=1);

namespace StarDust\Read;

use StarDust\Filter\Ast\AndNode;
use StarDust\Filter\Ast\FilterNode;
use StarDust\Filter\Ast\LeafNode;

/**
 * Phase 4 read request DTO accepted by {@see \StarDust\StarDust::read()}.
 *
 * Carries everything the read path needs to plan and execute the
 * two-query bounded read of ADR 0005:
 *   - `$tenantId` / `$modelId`           — boundary contract per Architecture Blueprint §1.2
 *   - `$filter`                          — AST root (see {@see FilterNode}); `null` is the
 *                                          normative match-all signal
 *   - `$selectFields`                    — restricted set of field names to populate on
 *                                          returned `Entry.fields`; `null` means "all
 *                                          registered fields for the model"
 *   - `$pageSize`                        — bounded; see DEFAULT_PAGE_SIZE and MAX_PAGE_SIZE
 *   - `$cursor`                          — opaque next-page token from a prior call
 *
 * Phase 8 reshaped `$filters: list<QueryFilter>` (AND-only flat list) into
 * `$filter: ?FilterNode` (a tree supporting full AND/OR/NOT composition
 * per ADR 0021). Existing call sites that built a flat list can use
 * {@see fromFlatFilters()} to migrate.
 *
 * **Ordering** defaults to `entry_data.id ASC` — the fixed order every
 * read had before `$sort` existed, which is why `null` is the default
 * and why an unsorted caller sees no change, cursors included.
 * See {@see SortSpec} for what a sort costs: the two intrinsic targets
 * stay index-ordered, while a field sort filesorts over the filtered
 * set.
 */
final class EntryQuery
{
    public const DEFAULT_PAGE_SIZE = 100;
    public const MAX_PAGE_SIZE = 1000;
    public const MIN_PAGE_SIZE = 1;

    /**
     * @param array<string>|null $selectFields field names to populate on result rows;
     *                                         `null` → engine returns every registered
     *                                         field for the model. Typed `array` rather
     *                                         than `list` because this is a public DTO a
     *                                         consumer constructs directly and PHP accepts
     *                                         a keyed array here; `BoundedFetch` normalises.
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $modelId,
        public readonly ?FilterNode $filter = null,
        public readonly ?array $selectFields = null,
        public readonly int $pageSize = self::DEFAULT_PAGE_SIZE,
        public readonly ?Cursor $cursor = null,
        public readonly ?SortSpec $sort = null,
    ) {
    }

    /**
     * Builds an `EntryQuery` from a flat list of leaf predicates,
     * implicitly AND-composed — the migration path for Phase 4 callers
     * (and tests) that previously passed `filters: list<QueryFilter>`.
     *
     * A zero-length list produces a match-all query (`filter = null`);
     * a single leaf is unwrapped; two or more are wrapped in an
     * {@see AndNode}.
     *
     * @param list<LeafNode>    $leaves
     * @param list<string>|null $selectFields
     */
    public static function fromFlatFilters(
        int $tenantId,
        int $modelId,
        array $leaves = [],
        ?array $selectFields = null,
        int $pageSize = self::DEFAULT_PAGE_SIZE,
        ?Cursor $cursor = null,
        ?SortSpec $sort = null,
    ): self {
        $filter = match (count($leaves)) {
            0       => null,
            1       => $leaves[0],
            default => new AndNode($leaves),
        };
        return new self(
            tenantId:     $tenantId,
            modelId:      $modelId,
            filter:       $filter,
            selectFields: $selectFields,
            pageSize:     $pageSize,
            cursor:       $cursor,
            sort:         $sort,
        );
    }
}
