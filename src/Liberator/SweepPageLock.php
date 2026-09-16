<?php

declare(strict_types=1);

namespace StarDust\Liberator;

use PDO;
use StarDust\Daemon\AdvisoryLock;
use StarDust\Daemon\LockNamespace;

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
 *
 * The name is qualified per installation by {@see LockNamespace}
 * (ADR 0053). Page ids restart at 1 in every installation, so
 * `stardust_sweep_page_1` was the single most collision-prone name in
 * the engine on a shared mysqld: two unrelated accounts would each
 * skip the other's page-1 sweep. Passing `null` keeps the unqualified
 * name and is for tests that assert on the literal.
 */
final class SweepPageLock
{
    private const LOCK_NAME_PREFIX = 'stardust_sweep_page_';

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?LockNamespace $lockNamespace = null,
    ) {
    }

    public function tryAcquire(int $pageId): ?AdvisoryLock
    {
        $name = self::LOCK_NAME_PREFIX . $pageId;

        return AdvisoryLock::tryAcquire($this->pdo, $this->lockNamespace?->qualify($name) ?? $name, 0);
    }
}
