<?php

declare(strict_types=1);

namespace StarDust\Daemon;

/**
 * SIGTERM / SIGINT handler.
 *
 * Registers async signal handlers via `pcntl_signal()` when ext-pcntl
 * is loaded; otherwise stays silently inert (`isRequested()` always
 * returns `false`). The plan accepts this degradation because Phase 5
 * does not require pcntl in `composer.json` — operators on hosts
 * without it use {@see FlagFileShutdownSignal} instead.
 */
final class SignalShutdownSignal implements ShutdownSignal
{
    private bool $requested = false;

    public function __construct()
    {
        if (!extension_loaded('pcntl')) {
            return;
        }

        pcntl_async_signals(true);
        $handler = function (): void {
            $this->requested = true;
        };
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }

    public function isRequested(): bool
    {
        return $this->requested;
    }
}
