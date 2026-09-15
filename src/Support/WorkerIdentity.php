<?php

declare(strict_types=1);

namespace StarDust\Support;

/**
 * Mints `host:pid:uuid` worker identifiers for a multi-worker daemon.
 * Originated in the Chronicler and was duplicated inline by
 * `ImportJobWorkSource`; both now share this one definition, on the
 * same precedent as {@see RetryableLockFailure} and {@see UuidV4} — a
 * third copy (minted for the ADR 0049 multi-worker Liberator) would
 * have been the re-inlining mistake `CLAUDE.md` warns about.
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
