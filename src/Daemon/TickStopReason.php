<?php

declare(strict_types=1);

namespace StarDust\Daemon;

/**
 * Why {@see CombinedTick::run()} returned. Carried on {@see TickReport}
 * and on the `tick_complete` event.
 */
enum TickStopReason: string
{
    /**
     * A round did nothing: the Liberator swept zero slots and the
     * Reconciler's round-robin pass found every work source idle. The
     * load-bearing exit — without it a quiet minute is ~50 s of
     * continuous idle polling, which is exactly the behaviour this
     * mode exists to avoid on a shared host.
     */
    case IDLE = 'idle';

    /** The configured/clamped time budget was spent before an idle round. */
    case BUDGET_SPENT = 'budget_spent';

    /** {@see ShutdownSignal::isRequested()} returned true between rounds. */
    case SHUTDOWN = 'shutdown';

    /**
     * The Watcher's or Liberator's pid file was already held by another
     * process — an overlapping cron firing, treated as routine rather
     * than an error. No round ran; the database was never touched.
     */
    case LOCK_CONTENDED = 'lock_contended';
}
