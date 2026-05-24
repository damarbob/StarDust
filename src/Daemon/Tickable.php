<?php

declare(strict_types=1);

namespace StarDust\Daemon;

/**
 * Single-method contract implemented by every long-running daemon.
 *
 * `tick()` performs one bounded unit of work and returns. The
 * surrounding {@see PollLoop} re-invokes it on the configured cadence.
 * Implementations MUST NOT loop internally — that would block the
 * loop from observing {@see ShutdownSignal::isRequested()}.
 */
interface Tickable
{
    public function tick(): void;
}
