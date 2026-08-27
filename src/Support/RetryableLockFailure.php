<?php

declare(strict_types=1);

namespace StarDust\Support;

use PDOException;

/**
 * The one definition of "InnoDB refused this statement over a lock, and
 * retrying the same work is safe".
 *
 * Matches a deadlock (errno 1213 / SQLSTATE 40001) and a **lock wait
 * timeout (errno 1205)**. The 1205 half is the one that actually fires
 * in this engine: measured on MySQL 8.0.13, a model purge deleting
 * `entry_data` cascades into the same `entry_slots_page_X` rows the
 * Liberator is nullifying, and in **both directions** the loser gets
 * 1205, never 1213. A budget that tested only for deadlocks would never
 * fire at all.
 *
 * Both are safe to retry for the same reason: the transaction rolls back
 * whole, so any chunk whose cursor lives on a row that transaction owns
 * is byte-for-byte re-executable from the same cursor.
 *
 * **Shared rather than re-inlined**, on the `Slot\LiveSlotTombstoner`
 * precedent. This predicate is what stands between a transient lock and
 * a dead daemon — `PollLoop` deliberately does not catch tick
 * exceptions — and it has already had to be widened once, when ADR 0038
 * found the 1205 case that the original deadlock-only test missed. A
 * copy that misses the next widening fails silently, as a daemon that
 * exits under contention.
 *
 * What callers do *after* a match is deliberately not standardised here;
 * it differs by work source. The Liberator has a gap path,
 * `ModelPurgeWorkSource` rethrows on exhaustion because skipping would
 * strand rows with a dangling `model_id`, and the five other Reconciler
 * work sources return `TickOutcome::LOCK_WAIT` so the next tick retries
 * the identical chunk.
 */
final class RetryableLockFailure
{
    public static function matches(PDOException $e): bool
    {
        $info = $e->errorInfo;
        if (! is_array($info)) {
            return false;
        }
        if (isset($info[0]) && $info[0] === '40001') {
            return true;
        }
        $errno = isset($info[1]) ? (int) $info[1] : 0;

        return $errno === 1213 || $errno === 1205;
    }

    /** Driver errno, for the `errno` field on a `deadlock_retry` event. */
    public static function errnoOf(PDOException $e): ?int
    {
        $info = $e->errorInfo;

        return is_array($info) && isset($info[1]) ? (int) $info[1] : null;
    }
}
