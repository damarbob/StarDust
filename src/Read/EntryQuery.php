<?php

declare(strict_types=1);

namespace StarDust\Read;

/**
 * Phase 4 read request DTO accepted by {@see \StarDust\StarDust::read()}.
 *
 * Carries everything {@see EntryReader} needs to plan and execute the
 * two-query bounded read of ADR 0005:
 *   - `$tenantId` / `$modelId`           — boundary contract per Architecture Blueprint §1.2
 *   - `$filters`                         — AND-only list of leaf clauses (Phase 4 MVP)
 *   - `$selectFields`                    — restricted set of field names to populate on
 *                                          returned `Entry.fields`; `null` means "all
 *                                          registered fields for the model"
 *   - `$pageSize`                        — bounded; see DEFAULT_PAGE_SIZE and MAX_PAGE_SIZE
 *   - `$cursor`                          — opaque next-page token from a prior call
 *
 * AND-only at MVP — `or`/`not` composition is reserved for Phase 8.
 *
 * **Ordering** is fixed at `entry_data.id ASC` in Phase 4. ADR 0006
 * scopes the cursor to a single integer (`entry_id`), which only
 * supports stable cursor pagination when that same key drives the
 * sort. User-supplied `ORDER BY` requires a compound-cursor protocol
 * (last sort value + last entry_id) not pinned by any current ADR;
 * deferred to Phase 8 alongside the wire-format parser.
 */
final class EntryQuery
{
    public const DEFAULT_PAGE_SIZE = 100;
    public const MAX_PAGE_SIZE = 1000;
    public const MIN_PAGE_SIZE = 1;

    /**
     * @param list<QueryFilter> $filters
     * @param list<string>|null $selectFields field names to populate on result rows;
     *                                        `null` → engine returns every registered
     *                                        field for the model
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $modelId,
        public readonly array $filters = [],
        public readonly ?array $selectFields = null,
        public readonly int $pageSize = self::DEFAULT_PAGE_SIZE,
        public readonly ?Cursor $cursor = null,
    ) {
    }
}
