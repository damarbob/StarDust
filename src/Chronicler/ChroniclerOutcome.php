<?php

declare(strict_types=1);

namespace StarDust\Chronicler;

/**
 * Per-call result of {@see Chronicler::tickRound()} (ADR 0050),
 * mirroring {@see \StarDust\Reconciler\TickOutcome} and
 * {@see \StarDust\Liberator\Liberator::sweepBatch()}'s int-return
 * shape — the two existing precedents for "report what a round did
 * without a second query" that this daemon lacked until now.
 *
 * - `IDLE`: the disk-pressure gate skipped claiming, or no pending or
 *   abandoned job was available. GC still ran either way.
 * - `WORKED`: a job was claimed and `process()` reached a terminal
 *   state (completed, one of the failure flavours, or lease lost).
 * - `YIELDED`: a job was claimed and yielded back to `pending` at a
 *   chunk boundary rather than reaching a terminal state.
 *
 * {@see \StarDust\Daemon\CombinedTick} folds `IDLE` into its round
 * idle-exit test alongside the Liberator's and Reconciler's own idle
 * signals; `YIELDED` is deliberately NOT idle — there is a job
 * converging toward completion, so the round must not look quiet.
 */
enum ChroniclerOutcome
{
    case IDLE;
    case WORKED;
    case YIELDED;
}
