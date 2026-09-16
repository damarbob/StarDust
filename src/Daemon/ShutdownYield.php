<?php

declare(strict_types=1);

namespace StarDust\Daemon;

/**
 * Adapts a {@see ShutdownSignal} into a {@see YieldSignal} with cause
 * `'shutdown'` (ADR 0050).
 *
 * Composed into the persistent `bin/stardust chronicler` daemon's own
 * {@see \StarDust\Chronicler\Chronicler} so a `SIGTERM` mid-export
 * lands the job back on `pending` with its resume anchor intact at
 * the next chunk boundary, instead of blocking until the job finishes
 * or being killed outright and waiting out the lease timeout.
 */
final class ShutdownYield implements YieldSignal
{
    public function __construct(private readonly ShutdownSignal $shutdown)
    {
    }

    public function yieldCause(): ?string
    {
        return $this->shutdown->isRequested() ? 'shutdown' : null;
    }
}
