<?php

declare(strict_types=1);

namespace StarDust\Daemon;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Liberator\Liberator;
use StarDust\Reconciler\Reconciler;
use StarDust\Reconciler\TickOutcome;
use StarDust\Support\UuidV4;
use StarDust\Watcher\Watcher;

/**
 * Bounded, single-process run of all three registry daemons over one
 * connection, for hosts with no persistent-process capability (ADR
 * 0048). The Chronicler is deliberately **not** composed here —
 * {@see \StarDust\Chronicler\ExportJobProcessor::process()} runs a
 * claimed job to completion with no yield point, so including it would
 * turn the time budget into a suggestion. Exports stay a
 * persistent-process feature until a future cooperative-yield change
 * lands; `docs/deployment.md` says so in those words.
 *
 * `run()`:
 *
 *   1. Emits `tick_started`.
 *   2. Takes the Watcher's pid-file lock via
 *      {@see PidFileGuard::tryAcquire()} — the Watcher is a strict
 *      singleton ({@see Watcher}), and this run composes it, so it
 *      takes *its* lock rather than minting a third. Held by another
 *      process (an overlapping cron firing, or a persistent
 *      `bin/stardust watcher`) is routine, not an error: `run()` emits
 *      `tick_skipped`, touches the database not at all, and reports
 *      {@see TickStopReason::LOCK_CONTENDED}. **The Liberator is no
 *      longer part of this** — ADR 0049 replaced its process-level
 *      singleton with page-table-granularity `GET_LOCK` exclusion
 *      ({@see \StarDust\Liberator\SweepPageLock}), taken per slot
 *      inside {@see \StarDust\Liberator\Liberator::sweepBatch()}
 *      itself, so a `tick` run may now proceed alongside a standalone
 *      `bin/stardust liberator` process — the two simply divide the
 *      tombstoned-slot batch by whichever pages each claims first.
 *   3. Optionally forces both Watcher advisory samplers once
 *      ({@see Watcher::sampleAdvisories()}) — see that method's
 *      docblock for why this process model cannot rely on the
 *      in-memory schedule `Watcher::tick()` normally uses.
 *   4. Runs `Watcher::tick()` once, unconditionally, before the round
 *      loop — a round's `CAPACITY_WAIT` can only be cleared by the
 *      Watcher, so provisioning has to run before the Reconciler can
 *      possibly need it.
 *   5. Loops: sweep one Liberator batch, then run one Reconciler round
 *      ({@see Reconciler::tickRound()}), re-running the Watcher if the
 *      round reported `CAPACITY_WAIT`. A round that swept nothing and
 *      found the Reconciler fully idle stops the loop — the load-
 *      bearing courtesy to the host, since without it a quiet minute is
 *      ~50 s of continuous idle polling. The budget and
 *      {@see ShutdownSignal} are checked between rounds, never mid-
 *      round, so a budget at or under zero still completes one round
 *      rather than erroring.
 *   6. Emits `tick_complete` and releases the Watcher's lock.
 *
 * **Fixed order, and it is a design decision, not an implementation
 * detail** — observable in the event stream the same way the
 * Reconciler's own work-source order is (`src/Reconciler/CLAUDE.md`).
 * Watcher, then Liberator-before-Reconciler-per-round: a slot the
 * Liberator reclaims `tombstoned → free` this round is then visible to
 * whatever the Reconciler reserves later in the same round.
 *
 * **One run is one failure domain.** An exception from any composed
 * daemon propagates out of `run()` uncaught, ending the whole run —
 * unlike three independently-supervised processes, where one crashing
 * daemon does not touch the other two. Deliberate: it matches the
 * existing "fail loudly on unexpected error" policy every daemon here
 * already has, keeps the event vocabulary small, and cron's own mail-
 * on-stderr is the intended operator signal.
 *
 * Not a {@see Tickable} — a bounded, budget-driven run is a different
 * shape of thing than a single tick, and `Tickable` stays single-method
 * per the ISP rule in this package's CLAUDE.md.
 */
final class CombinedTick
{
    public function __construct(
        private readonly Watcher $watcher,
        private readonly Liberator $liberator,
        private readonly Reconciler $reconciler,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        private readonly ShutdownSignal $shutdown,
        private readonly string $pidFileDir,
    ) {
    }

    public function run(TickBudget $budget, bool $advisories = false): TickReport
    {
        $correlationId = UuidV4::generate();
        $this->logger->info('tick started', [
            'event'          => 'tick_started',
            'source'         => 'tick',
            'correlation_id' => $correlationId,
            'budget_seconds' => $budget->seconds,
            'clamped'        => $budget->clamped,
            'advisories'     => $advisories,
        ]);

        $watcherGuard = PidFileGuard::tryAcquire($this->pidFileDir, 'watcher');
        if ($watcherGuard === null) {
            return $this->skipped($correlationId, $budget, 'watcher');
        }

        try {
            return $this->runLocked($correlationId, $budget, $advisories);
        } finally {
            $watcherGuard->release();
        }
    }

    private function runLocked(string $correlationId, TickBudget $budget, bool $advisories): TickReport
    {
        $startedAt = $this->clock->now()->getTimestamp();

        if ($advisories) {
            $this->watcher->sampleAdvisories($correlationId);
        }

        // Runs before the round loop, unconditionally: a round's
        // CAPACITY_WAIT can only be cleared by the Watcher, so
        // provisioning has to have a chance to run before the
        // Reconciler can possibly need it.
        $this->watcher->tick();

        $rounds = 0;
        $stopReason = TickStopReason::BUDGET_SPENT;

        while (true) {
            if ($this->shutdown->isRequested()) {
                $stopReason = TickStopReason::SHUTDOWN;
                break;
            }

            $swept = $this->liberator->sweepBatch();
            $outcome = $this->reconciler->tickRound();
            $rounds++;

            if ($outcome === TickOutcome::CAPACITY_WAIT) {
                $this->watcher->tick();
            }

            if ($swept === 0 && $outcome === TickOutcome::IDLE) {
                $stopReason = TickStopReason::IDLE;
                break;
            }

            $elapsed = $this->clock->now()->getTimestamp() - $startedAt;
            if ($elapsed >= $budget->seconds) {
                $stopReason = TickStopReason::BUDGET_SPENT;
                break;
            }
        }

        $elapsedSeconds = (float) ($this->clock->now()->getTimestamp() - $startedAt);

        $this->logger->info('tick complete', [
            'event'           => 'tick_complete',
            'source'          => 'tick',
            'correlation_id'  => $correlationId,
            'rounds'          => $rounds,
            'elapsed_seconds' => $elapsedSeconds,
            'budget_seconds'  => $budget->seconds,
            'stop_reason'     => $stopReason->value,
        ]);

        return new TickReport($rounds, $elapsedSeconds, $budget->seconds, $stopReason);
    }

    private function skipped(string $correlationId, TickBudget $budget, string $contendedPidFile): TickReport
    {
        $this->logger->warning('tick skipped', [
            'event'               => 'tick_skipped',
            'source'              => 'tick',
            'correlation_id'      => $correlationId,
            'contended_pid_file'  => $contendedPidFile,
        ]);

        return new TickReport(
            rounds: 0,
            elapsedSeconds: 0.0,
            budgetSeconds: $budget->seconds,
            stopReason: TickStopReason::LOCK_CONTENDED,
        );
    }
}
