<?php

declare(strict_types=1);

namespace StarDust\Read;

/**
 * Phase 4 paginated result page returned by
 * {@see \StarDust\StarDust::read()}.
 *
 * `$nextCursor` is `null` exactly when no further page exists per
 * ADR 0006 — the engine determines this by issuing the Paginated
 * Probe (ADR 0005) with `LIMIT $pageSize + 1` and observing whether
 * the trailing row materialised. No separate COUNT query is ever
 * executed. The `$pageSize` echo is included so callers that batch
 * multiple reads can correlate the result back to the requested
 * size (the engine never silently downsizes a page).
 */
final class EntryPage
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
}
