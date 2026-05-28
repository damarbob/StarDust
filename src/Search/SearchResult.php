<?php

declare(strict_types=1);

namespace StarDust\Search;

use StarDust\Read\Cursor;
use StarDust\Read\Entry;
use StarDust\Read\EntryPage;

/**
 * Phase 8 driver-facing read result.
 *
 * Same field set as Phase 4's {@see EntryPage}; kept as a distinct DTO
 * so the `src/Search/` namespace does not reach into `src/Read/` for
 * downstream return types. Convert via {@see toEntryPage()} when the
 * legacy entry surface needs to be served.
 */
final class SearchResult
{
    /**
     * @param list<Entry> $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly ?Cursor $nextCursor,
        public readonly int $pageSize,
    ) {
    }

    public function toEntryPage(): EntryPage
    {
        return new EntryPage(
            rows:       $this->rows,
            nextCursor: $this->nextCursor,
            pageSize:   $this->pageSize,
        );
    }

    public static function fromEntryPage(EntryPage $page): self
    {
        return new self(
            rows:       $page->rows,
            nextCursor: $page->nextCursor,
            pageSize:   $page->pageSize,
        );
    }
}
