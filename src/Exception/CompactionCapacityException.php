<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * The compaction plan is inadmissible: the chosen target pages do not
 * hold enough free slots of every required family to house the
 * relocations (ADR 0033).
 *
 * Thrown by the planner **before any mutation** — no tombstones, no
 * reservations, no checkpoints, no schema-version bump. That is the
 * whole point of the up-front check: compaction either runs in full or
 * leaves the registry untouched, so a failed attempt is never a
 * half-migration an operator has to unpick.
 *
 * The usual cause is double-occupancy, and it bites exactly when
 * compaction is most wanted. A relocated field holds two slots — the
 * vacated one (`tombstoned`, awaiting the Liberator's sweep per ADR
 * 0009) and the new one — so compaction consumes free capacity on its
 * target pages before returning any, precisely when the model's pages
 * are already fragmented.
 *
 * Operator remedies, per the runbook: let the Liberator drain the
 * tombstone backlog, let the Watcher provision capacity, and re-run.
 * Re-running is always safe (ADR 0033 resume-is-re-run).
 */
final class CompactionCapacityException extends RuntimeException
{
}
