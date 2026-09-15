<?php

declare(strict_types=1);

namespace StarDust\Daemon;

/**
 * Result of one {@see CombinedTick::run()} invocation.
 *
 * `rounds` counts completed Liberator-sweep-plus-Reconciler-round
 * passes; it is `0` for {@see TickStopReason::LOCK_CONTENDED} (no
 * round ran) and is typically `1` for {@see TickStopReason::IDLE}
 * (the first round found nothing).
 */
final class TickReport
{
    public function __construct(
        public readonly int $rounds,
        public readonly float $elapsedSeconds,
        public readonly int $budgetSeconds,
        public readonly TickStopReason $stopReason,
    ) {
    }
}
