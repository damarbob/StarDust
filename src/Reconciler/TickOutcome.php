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
 * - `LOCK_WAIT`: rows were claimed but InnoDB refused the chunk over a
 *   lock (errno 1205 / 1213) `Config::$reconcilerLockRetryBudget` times
 *   running. The chunk transaction is rolled back, so its cursor — which
 *   lives on a row that transaction owns — is untouched and the next
 *   tick retries the identical chunk. **Nothing is skipped and nothing
 *   is failed.**
 *
 * `LOCK_WAIT` exists because the alternative is a dead daemon:
 * `PollLoop` deliberately does not catch tick exceptions, so an
 * unretried lock failure exits the process, which then crash-loops for
 * as long as the contending sweep runs. `ModelPurgeWorkSource` is the
 * one source that still rethrows instead, for a reason recorded on its
 * own `isRetryableLockFailure()`.
 */
enum TickOutcome
{
    case WORK_DONE;
    case IDLE;
    case CAPACITY_WAIT;
    case LOCK_WAIT;
}
