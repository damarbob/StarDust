<?php

declare(strict_types=1);

namespace StarDust\Daemon;

use StarDust\Exception\WatcherSingletonViolationException;

/**
 * Process-level singleton enforcement for the Watcher daemon
 * (ADR 0008, ADR 0027).
 *
 * `acquire()` opens `<pidFileDir>/<daemonName>.pid`, takes a
 * non-blocking exclusive `flock`, and writes the current PID. On
 * contention it throws {@see WatcherSingletonViolationException}.
 *
 * The file handle is held for the lifetime of the guard object; the OS
 * releases the lock automatically when the process exits, so even a
 * PHP fatal cannot leave the lock orphaned. `release()` is provided
 * for orderly shutdown and is idempotent.
 */
final class PidFileGuard
{
    /** @var resource */
    private mixed $handle;
    private readonly string $path;
    private bool $released = false;

    private function __construct(mixed $handle, string $path)
    {
        $this->handle = $handle;
        $this->path = $path;
    }

    public static function acquire(string $pidFileDir, string $daemonName): self
    {
        if (!is_dir($pidFileDir) && !@mkdir($pidFileDir, 0777, true) && !is_dir($pidFileDir)) {
            throw new WatcherSingletonViolationException(
                "Cannot create pid-file directory '{$pidFileDir}'."
            );
        }

        $path = $pidFileDir . DIRECTORY_SEPARATOR . $daemonName . '.pid';
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            throw new WatcherSingletonViolationException(
                "Cannot open pid file '{$path}' for writing."
            );
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            $existingPid = is_readable($path) ? trim((string) @file_get_contents($path)) : '';
            $message = "Another {$daemonName} process holds '{$path}'";
            if ($existingPid !== '') {
                $message .= " (PID {$existingPid})";
            }
            throw new WatcherSingletonViolationException($message . '.');
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) getmypid());
        fflush($handle);

        return new self($handle, $path);
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;
        @flock($this->handle, LOCK_UN);
        @fclose($this->handle);
        // Leave the file in place with the last PID for operator
        // forensics — ADR 0027 recommends preserving this signal.
    }

    public function __destruct()
    {
        $this->release();
    }

    public function path(): string
    {
        return $this->path;
    }
}
