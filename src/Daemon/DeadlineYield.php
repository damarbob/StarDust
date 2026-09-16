<?php

declare(strict_types=1);

namespace StarDust\Daemon;

use Psr\Clock\ClockInterface;

/**
 * Requests a yield with cause `'budget'` once the clock reaches a
 * fixed deadline timestamp (ADR 0050). {@see CombinedTick} builds one
 * per run from the resolved {@see TickBudget}'s deadline, so the
 * Chronicler's internal chunk loop cannot outrun the tick's own
 * budget the way {@see \StarDust\Reconciler\Reconciler} and
 * {@see \StarDust\Liberator\Liberator} already can't — both are
 * naturally bounded to one round / one batch, while the Chronicler's
 * `process()` loop would otherwise run one claimed job to completion
 * regardless of size.
 */
final class DeadlineYield implements YieldSignal
{
    public function __construct(
        private readonly ClockInterface $clock,
        private readonly int $deadlineTimestamp,
    ) {
    }

    public function yieldCause(): ?string
    {
        return $this->clock->now()->getTimestamp() >= $this->deadlineTimestamp ? 'budget' : null;
    }
}
