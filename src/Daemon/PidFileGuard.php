<?php

declare(strict_types=1);

namespace StarDust\Daemon;

use RuntimeException;
use StarDust\Exception\WatcherSingletonViolationException;

/**
 * Process-level singleton enforcement for daemons that must run
 * exactly once per deployment (ADR 0008 Watcher, ADR 0009 Liberator,
 * ADR 0027 persistent-process model).
 *
 * `acquire()` opens `<pidFileDir>/<daemonName>.pid`, takes a
 * non-blocking exclusive `flock`, and writes the current PID. On
 * contention it throws the caller-provided exception class (defaults
 * to {@see WatcherSingletonViolationException} to preserve Phase 5
 * behaviour); a daemon with its own strict-singleton contract can
 * inject its own typed exception instead. The exception class MUST
 * extend {@see RuntimeException}. (The Liberator used to be such a
 * daemon — `LiberatorSingletonViolationException::class` — until
 * ADR 0049 replaced its process-level singleton with page-table
 * `GET_LOCK` exclusion; the Watcher remains the only caller today.)
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

    /**
     * @param class-string<RuntimeException>|null $exceptionClass
     *        Thrown on every failure path; defaults to
     *        {@see WatcherSingletonViolationException}.
     */
    public static function acquire(string $pidFileDir, string $daemonName, ?string $exceptionClass = null): self
    {
        $exceptionClass ??= WatcherSingletonViolationException::class;
        [$handle, $path] = self::openHandle($pidFileDir, $daemonName, $exceptionClass);

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            $existingPid = is_readable($path) ? trim((string) @file_get_contents($path)) : '';
            $message = "Another {$daemonName} process holds '{$path}'";
            if ($existingPid !== '') {
                $message .= " (PID {$existingPid})";
            }
            throw new $exceptionClass($message . '.');
        }

        return self::finish($handle, $path);
    }

    /**
     * Same as {@see acquire()}, except flock contention returns `null`
     * instead of throwing `WatcherSingletonViolationException`. An
     * unwritable directory or an unopenable pid file still throws —
     * those are configuration errors, not the "another instance is
     * already running" condition a caller like
     * {@see \StarDust\Daemon\CombinedTick} treats as routine (an
     * overlapping cron firing is expected, not an error).
     *
     * Shares {@see openHandle()} / {@see finish()} with `acquire()`
     * rather than duplicating the open/lock/write sequence — there is
     * exactly one place that opens the file and one that writes the PID
     * into it.
     */
    public static function tryAcquire(string $pidFileDir, string $daemonName): ?self
    {
        [$handle, $path] = self::openHandle($pidFileDir, $daemonName, WatcherSingletonViolationException::class);

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }

        return self::finish($handle, $path);
    }

    /**
     * @param class-string<RuntimeException> $exceptionClass
     * @return array{0: resource, 1: string}
     */
    private static function openHandle(string $pidFileDir, string $daemonName, string $exceptionClass): array
    {
        if (!is_dir($pidFileDir) && !@mkdir($pidFileDir, 0777, true) && !is_dir($pidFileDir)) {
            throw new $exceptionClass(
                "Cannot create pid-file directory '{$pidFileDir}'."
            );
        }

        $path = $pidFileDir . DIRECTORY_SEPARATOR . $daemonName . '.pid';
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            throw new $exceptionClass(
                "Cannot open pid file '{$path}' for writing."
            );
        }

        return [$handle, $path];
    }

    /** @param resource $handle */
    private static function finish(mixed $handle, string $path): self
    {
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
