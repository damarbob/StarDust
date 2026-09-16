<?php

declare(strict_types=1);

namespace StarDust\Daemon;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Chronicler\Chronicler;
use StarDust\Chronicler\ChroniclerOutcome;
use StarDust\Liberator\Liberator;
use StarDust\Reconciler\Reconciler;
use StarDust\Reconciler\TickOutcome;
use StarDust\Support\UuidV4;
use StarDust\Watcher\Watcher;

/**
 * Bounded, single-process run of all three (four, with `$exports`)
 * registry daemons over one connection, for hosts with no
 * persistent-process capability (ADR 0048).
 *
 * **The Chronicler is opt-in, off by default, and always composed
 * last in a round** (ADR 0050). It is off by default because the
 * disk-pressure gate reports partition-level free space rather than
 * a per-account quota, and shared hosting commonly enforces the
 * latter above the filesystem layer the gate actually checks — a
 * cron-only host is exactly where that deployment shape is most
 * likely to be found. This is unverified, not merely theoretical
 * (internal build-sequencing notes track it as open); `$exports =
 * true` is the operator's explicit acknowledgement. It runs last so
 * registry maintenance
 * (page provisioning, slot reclamation, the sync-queue / import-job /
 * backfill drain) is never starved by a large export. When enabled,
 * `runLocked()` builds ONE per-run {@see YieldSignal} — a
 * {@see CompositeYield} of a {@see DeadlineYield} pinned to this run's
 * own budget deadline and a {@see ShutdownYield} wrapping `$shutdown`
 * — and passes it to every {@see Chronicler::tickRound()} call. This
 * is load-bearing, not an optimisation: unlike the Liberator's
 * batch-bounded sweep and the Reconciler's round-bounded pass, the
 * Chronicler's `process()` loop would otherwise run one claimed job to
 * completion regardless of size, and the per-round budget check below
 * (checked only BETWEEN rounds, per Commitment 2) cannot bound work
 * that happens inside a single round. The `DeadlineYield` is what
 * keeps that promise instead — see `ExportJobProcessor::process()`'s
 * own chunk-boundary check.
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
 *   5. Loops: sweep one Liberator batch, run one Reconciler round
 *      ({@see Reconciler::tickRound()}), then — when `$exports` is
 *      true — one Chronicler round ({@see Chronicler::tickRound()}),
 *      re-running the Watcher if the Reconciler round reported
 *      `CAPACITY_WAIT`. A round that swept nothing, found the
 *      Reconciler fully idle, AND found the Chronicler idle (or is not
 *      running it) stops the loop — the load-bearing courtesy to the
 *      host, since without it a quiet minute is ~50 s of continuous
 *      idle polling. `YIELDED` deliberately does NOT count as idle —
 *      a job is converging toward completion. The budget and
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
        private readonly Chronicler $chronicler,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        private readonly ShutdownSignal $shutdown,
        private readonly string $pidFileDir,
    ) {
    }

    public function run(TickBudget $budget, bool $advisories = false, bool $exports = false): TickReport
    {
        $correlationId = UuidV4::generate();
        $this->logger->info('tick started', [
            'event'          => 'tick_started',
            'source'         => 'tick',
            'correlation_id' => $correlationId,
            'budget_seconds' => $budget->seconds,
            'clamped'        => $budget->clamped,
            'advisories'     => $advisories,
            'exports'        => $exports,
        ]);

        $watcherGuard = PidFileGuard::tryAcquire($this->pidFileDir, 'watcher');
        if ($watcherGuard === null) {
            return $this->skipped($correlationId, $budget, 'watcher');
        }

        try {
            return $this->runLocked($correlationId, $budget, $advisories, $exports);
        } finally {
            $watcherGuard->release();
        }
    }

    private function runLocked(string $correlationId, TickBudget $budget, bool $advisories, bool $exports): TickReport
    {
        $startedAt = $this->clock->now()->getTimestamp();

        if ($advisories) {
            $this->watcher->sampleAdvisories($correlationId);
        }

        // ADR 0050: one signal for the whole run, built once. The
        // DeadlineYield is what bounds the Chronicler's internal
        // chunk loop to this run's own budget — see the class docblock.
        $exportYield = $exports
            ? new CompositeYield(
                new DeadlineYield($this->clock, $startedAt + $budget->seconds),
                new ShutdownYield($this->shutdown),
            )
            : null;

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

            // Composed last in the round (see class docblock) so
            // registry maintenance never waits on it. IDLE by
            // definition when exports are off, so the fold below is a
            // no-op in that case.
            $exportOutcome = $exports
                ? $this->chronicler->tickRound($exportYield)
                : ChroniclerOutcome::IDLE;

            if ($swept === 0 && $outcome === TickOutcome::IDLE && $exportOutcome === ChroniclerOutcome::IDLE) {
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
