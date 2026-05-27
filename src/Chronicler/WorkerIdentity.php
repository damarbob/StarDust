<?php

declare(strict_types=1);

namespace StarDust\Chronicler;

use StarDust\Support\UuidV4;

/**
 * Mints `host:pid:uuid` worker identifiers for the Chronicler. Mirrors
 * the inline pattern used by
 * {@see \StarDust\Reconciler\ImportJobWorkSource}; extracted so the
 * Chronicler's per-tick claim flow can refer to the same identity from
 * multiple call sites without duplicating the gethostname/getmypid
 * boilerplate.
 *
 * The uuid suffix guarantees uniqueness even when two processes share
 * `pid` (e.g., across container restarts where the kernel reuses pids).
 */
final class WorkerIdentity
{
    public static function mint(): string
    {
        $host = gethostname();
        if ($host === false || $host === '') {
            $host = 'unknown';
        }
        return $host . ':' . getmypid() . ':' . UuidV4::generate();
    }
}
