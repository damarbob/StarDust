<?php

declare(strict_types=1);

namespace StarDust\Reconciler;

/**
 * Per-call result of a {@see ReconcilerWorkSource::tickOne()}.
 *
 * - `WORK_DONE`: at least one queued/pending unit advanced; the
 *   Reconciler loops immediately so a saturated queue drains without
 *   inter-tick sleeps.
 * - `IDLE`: nothing claimable; the Reconciler proceeds to the next
 *   work source or, if every source is idle, sleeps via `PollLoop`.
 * - `CAPACITY_WAIT`: rows were claimed but capacity was insufficient.
 *   The chunk transaction is rolled back so the rows remain claimable;
 *   the Reconciler sleeps for `Config::$reconcilerCapacityWaitMillis`
 *   to give the Watcher a chance to provision before retrying.
 */
enum TickOutcome
{
    case WORK_DONE;
    case IDLE;
    case CAPACITY_WAIT;
}
