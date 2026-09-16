<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Support;

/**
 * PHP stream wrapper registered as `failwrite://` that accepts
 * open/mkdir but fails at a configurable stage — mimicking an ENOSPC
 * or EDQUOT partition without needing a real one.
 *
 * Two consumers:
 *
 *  - {@see \StarDust\Tests\Smoke\Chronicler\ChroniclerDiskFullTest} —
 *    the default `$failAt = 'write'` makes `stream_write()` return 0,
 *    which is exactly the short-write condition
 *    `CsvArtifactStream`/`JsonArtifactStream` treat as disk-full.
 *  - {@see \StarDust\Tests\Smoke\Chronicler\ChroniclerDiskPressureGateTest} —
 *    ADR 0051's write probe, whose other stages (`mkdir`, `open`,
 *    `flush`, `close`) are only reachable by varying `$failAt`.
 *
 * **It isolates the write probe from the ratio check for free.**
 * `disk_free_space()` / `disk_total_space()` are NOT stream-wrapper
 * aware — they `statvfs()` the literal string — so both return `false`
 * for a `failwrite://` path, the ratio probe reads `null` (no
 * pressure), and whatever the gate decides comes from the write probe
 * alone. Verified empirically, not assumed.
 *
 * **Trap: this class carries mutable static state.** `$failAt` is
 * static because a stream wrapper is instantiated by PHP, not by the
 * test, so there is nowhere else to put it. Every consumer MUST reset
 * it in `tearDown()`, or a later test inherits the previous one's
 * failure stage.
 */
final class FailWriteStreamWrapper
{
    /**
     * Which stage to fail at.
     *
     * @var 'none'|'mkdir'|'open'|'write'|'flush'|'close'
     */
    public static string $failAt = 'write';

    /**
     * When true, `url_stat()` reports every path as missing, so
     * `is_dir()` is false and a caller is driven down its mkdir path.
     */
    public static bool $statMissing = false;

    /** @var resource|null */
    public $context;

    public static function register(): void
    {
        if (in_array('failwrite', stream_get_wrappers(), true)) {
            stream_wrapper_unregister('failwrite');
        }
        stream_wrapper_register('failwrite', self::class);
    }

    public static function unregister(): void
    {
        if (in_array('failwrite', stream_get_wrappers(), true)) {
            stream_wrapper_unregister('failwrite');
        }
    }

    /** Restores the defaults every consumer's tearDown() must call. */
    public static function reset(): void
    {
        self::$failAt      = 'write';
        self::$statMissing = false;
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        return self::$failAt !== 'open';
    }

    public function stream_write(string $data): int
    {
        // Short write: simulates ENOSPC/EDQUOT. Both artifact streams
        // and the ADR 0051 probe treat written < expected as failure.
        return self::$failAt === 'write' ? 0 : strlen($data);
    }

    public function stream_close(): void
    {
        // no-op — fclose()'s bool comes from stream_flush() in practice.
    }

    public function stream_flush(): bool
    {
        return self::$failAt !== 'flush' && self::$failAt !== 'close';
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        // Allow touch() / chmod() / etc. against virtual paths.
        return true;
    }

    /**
     * Required for `stream_set_write_buffer()` — without it PHP emits
     * "stream_set_option is not implemented", which `failOnWarning`
     * would surface as a suite issue.
     */
    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        return true;
    }

    public function mkdir(string $path, int $mode, int $options): bool
    {
        return self::$failAt !== 'mkdir';
    }

    /**
     * @return array<string|int,int>|false
     */
    public function url_stat(string $path, int $flags): array|false
    {
        if (self::$statMissing) {
            return false;
        }
        // Report any failwrite:// path as a writable directory so
        // is_dir() / is_writable() checks pass.
        $mode = 0o040777; // S_IFDIR | 0777
        return [
            0 => 0,
            1 => 0,
            2 => $mode,
            3 => 0,
            4 => 0,
            5 => 0,
            6 => 0,
            7 => 0,
            8 => 0,
            9 => 0,
            10 => 0,
            11 => 0,
            12 => 0,
            'dev'     => 0,
            'ino'     => 0,
            'mode'    => $mode,
            'nlink'   => 0,
            'uid'     => 0,
            'gid'     => 0,
            'rdev'    => 0,
            'size'    => 0,
            'atime'   => 0,
            'mtime'   => 0,
            'ctime'   => 0,
            'blksize' => 0,
            'blocks'  => 0,
        ];
    }

    public function unlink(string $path): bool
    {
        return true;
    }
}
