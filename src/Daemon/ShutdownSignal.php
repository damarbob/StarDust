<?php

declare(strict_types=1);

namespace StarDust\Daemon;

/**
 * Stateless shutdown probe. {@see PollLoop} polls this between sleep
 * slices and at the top of each tick; implementations report whether
 * the daemon has been asked to exit cleanly.
 *
 * Two production implementations:
 *   - {@see SignalShutdownSignal} — POSIX SIGTERM/SIGINT via ext-pcntl
 *     (degrades to "never requested" when the extension is absent).
 *   - {@see FlagFileShutdownSignal} — existence of a
 *     `<pidFileDir>/<daemonName>.shutdown` file.
 *
 * {@see CompositeShutdownSignal} OR-composes any number of probes so a
 * deployment can pick either or both.
 */
interface ShutdownSignal
{
    public function isRequested(): bool;
}
