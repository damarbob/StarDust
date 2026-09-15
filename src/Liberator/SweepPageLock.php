<?php

declare(strict_types=1);

namespace StarDust\Liberator;

use PDO;
use StarDust\Daemon\AdvisoryLock;

/**
 * Per-page exclusion for the multi-worker Liberator (ADR 0049).
 *
 * Wraps {@see AdvisoryLock::tryAcquire()} with the fixed name
 * `stardust_sweep_page_{pageId}` and a hard-coded `0`-second timeout —
 * a worker that cannot take a page's lock has other pages it could be
 * sweeping instead, so waiting is never the right move. This is a
 * page-table-granularity lock, not a slot- or row-level one: two
 * workers never touch the same `entry_slots_page_N` table at once, so
 * no *Liberator-vs-Liberator* row-lock contention is possible on the
 * chunked `UPDATE ... SET <slotColumn> = NULL` `SlotSweeper` issues.
 *
 * `SlotSweeper`, `TombstonedSlot` and `TombstonedSlotRepository` are
 * unaware of this class — the lock serializes access to a page's
 * sweep, it is not part of the sweep itself.
 */
final class SweepPageLock
{
    private const LOCK_NAME_PREFIX = 'stardust_sweep_page_';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function tryAcquire(int $pageId): ?AdvisoryLock
    {
        return AdvisoryLock::tryAcquire($this->pdo, self::LOCK_NAME_PREFIX . $pageId, 0);
    }
}
