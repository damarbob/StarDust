<?php

declare(strict_types=1);

namespace StarDust\Daemon;

/**
 * Persistent poll loop (ADR 0027).
 *
 * Invokes `$tickable->tick()`, then sleeps in 1-second slices while
 * polling `$shutdown->isRequested()` so SIGTERM / flag-file shutdown
 * surfaces within ~1 second regardless of the configured interval.
 * The loop does not catch exceptions from `tick()` — the daemon owns
 * its error policy; an unhandled throw exits the loop and falls
 * through to the CLI's fatal handler.
 *
 * `intervalSeconds = 0` means "tick continuously" — appropriate for
 * the Reconciler under load, where `tick()` itself sleeps via
 * `Config::$reconcilerCapacityWaitMillis` only when both work sources
 * report IDLE.
 */
final class PollLoop
{
    public function __construct(private readonly SleepFunction $sleeper = new RealSleepFunction())
    {
    }

    public function run(Tickable $tickable, ShutdownSignal $shutdown, int $intervalSeconds): void
    {
        while (!$shutdown->isRequested()) {
            $tickable->tick();

            for ($remaining = $intervalSeconds; $remaining > 0; $remaining--) {
                // Re-poll mid-sleep: the signal can flip out-of-band (async
                // pcntl handler / flag file) after the while-condition saw
                // false, so this is reachable despite PHPStan's purity model.
                // @phpstan-ignore if.alwaysFalse
                if ($shutdown->isRequested()) {
                    return;
                }
                $this->sleeper->sleepSeconds(1);
            }
        }
    }
}
