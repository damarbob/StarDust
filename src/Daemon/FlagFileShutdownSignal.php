<?php

declare(strict_types=1);

namespace StarDust\Daemon;

/**
 * File-based shutdown sentinel: existence of
 * `<pidFileDir>/<daemonName>.shutdown` is the signal.
 *
 * Useful on hosts without ext-pcntl, or as a kill-switch operators can
 * `touch` from another shell. The Watcher's CLI removes this file on
 * normal startup so a stale flag from a previous run does not abort
 * the next process.
 */
final class FlagFileShutdownSignal implements ShutdownSignal
{
    private readonly string $flagPath;

    public function __construct(string $pidFileDir, string $daemonName)
    {
        $this->flagPath = $pidFileDir . DIRECTORY_SEPARATOR . $daemonName . '.shutdown';
    }

    public function isRequested(): bool
    {
        // Bypass the realpath cache — operators expect a `touch` to
        // take effect on the next poll, not after PHP's stat cache TTL.
        clearstatcache(true, $this->flagPath);
        return file_exists($this->flagPath);
    }

    public function path(): string
    {
        return $this->flagPath;
    }
}
