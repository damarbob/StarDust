<?php

declare(strict_types=1);

namespace StarDust\Chronicler;

use Psr\Log\LoggerInterface;
use StarDust\Daemon\Tickable;
use StarDust\Daemon\YieldSignal;
use StarDust\Support\UuidV4;

/**
 * Multi-worker async export daemon (ADR 0010, ADR 0025, ADR 0027).
 *
 * Each `tick()`:
 *   1. Asks {@see DiskPressureGate} whether to skip claiming this
 *      cycle. Below the configured free-disk threshold: emit `low_disk`
 *      (cycle-scoped `correlation_id`, `tenant_id: null`), then fall
 *      through to the GC sweep (in-flight jobs continue regardless;
 *      only new claims are gated).
 *   2. Otherwise: ask {@see ExportJobClaimer} for a pending or
 *      abandoned claim. On success: mint a per-job `correlation_id`,
 *      emit `job_claimed`, hand the claim to
 *      {@see ExportJobProcessor::process()} which runs the job to
 *      terminal state (completed / failed / lease lost).
 *   3. On idle (no claim available): run {@see GcSweeper::sweep()} —
 *      the GC step is idle-only so a busy worker never delays
 *      throughput on cleanup. `gc_swept` is emitted only when something
 *      was actually deleted.
 *
 * Multi-worker semantics: there is NO singleton enforcement. Two or
 * more Chronicler processes can run concurrently; row-level mutual
 * exclusion via `SELECT … FOR UPDATE SKIP LOCKED` inside the claimer
 * guarantees no two workers claim the same job. Horizontal scaling
 * means spawning more `bin/stardust chronicler` processes (no special
 * coordination required).
 *
 * Unhandled exceptions propagate out of `tick()`; the surrounding
 * {@see \StarDust\Daemon\PollLoop} lets them terminate the process,
 * matching the Watcher/Liberator "fail loudly on unexpected error"
 * policy.
 *
 * `$yieldSignal` (ADR 0050) is this instance's default cooperative-
 * yield probe, checked by {@see ExportJobProcessor::process()} once
 * per committed chunk. `null` (the default) means "never yield" —
 * matches every Phase 7 caller's behaviour unchanged. The persistent
 * `bin/stardust chronicler` daemon is built with
 * {@see \StarDust\Daemon\ShutdownYield} wrapping its own
 * {@see \StarDust\Daemon\ShutdownSignal} (see `StarDust::chronicler()`),
 * so a `SIGTERM` mid-export yields at the next chunk boundary instead
 * of blocking until the job finishes. {@see self::tickRound()} accepts
 * a PER-CALL override instead of a second constructor field, because
 * {@see \StarDust\Daemon\CombinedTick}'s budget deadline is scoped to
 * one run, not to this daemon's lifetime.
 */
final class Chronicler implements Tickable
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ExportJobClaimer $claimer,
        private readonly ExportJobProcessor $processor,
        private readonly DiskPressureGate $diskGate,
        private readonly GcSweeper $gcSweeper,
        private readonly ?YieldSignal $yieldSignal = null,
    ) {
    }

    public function tick(): void
    {
        $this->tickRound();
    }

    /**
     * The body of `tick()`, returning {@see ChroniclerOutcome} rather
     * than `void`.
     *
     * `tick()` (the {@see Tickable} contract used by the standalone
     * `bin/stardust chronicler` poll loop) delegates here and discards
     * the result; {@see \StarDust\Daemon\CombinedTick} calls this
     * directly, passing its own per-run {@see YieldSignal}, so it can
     * tell an idle round from one where a job is converging without a
     * second query — mirroring {@see \StarDust\Liberator\Liberator::sweepBatch()}
     * and {@see \StarDust\Reconciler\Reconciler::tickRound()}, the two
     * existing precedents for this shape.
     *
     * `$yield`, when given, overrides `$this->yieldSignal` for this
     * call only — it does not replace it.
     */
    public function tickRound(?YieldSignal $yield = null): ChroniclerOutcome
    {
        if ($this->diskGate->shouldSkipClaim()) {
            $this->logger->warning('chronicler low disk', [
                'event'           => 'low_disk',
                'source'          => 'chronicler',
                'correlation_id'  => UuidV4::generate(),
                'tenant_id'       => null,
                'partition'       => $this->diskGate->partition(),
                'free_pct'        => $this->diskGate->freePct(),
                'threshold_pct'   => $this->diskGate->thresholdPct(),
            ]);
            // Disk-pressure does NOT short-circuit GC — reclaiming
            // artifact files is the right thing to do under pressure.
            $this->gcSweeper->sweep(UuidV4::generate());
            return ChroniclerOutcome::IDLE;
        }

        $claim = $this->claimer->claimPendingOrAbandoned();
        if ($claim === null) {
            // Idle cycle: run GC, then let the outer PollLoop sleep.
            $this->gcSweeper->sweep(UuidV4::generate());
            return ChroniclerOutcome::IDLE;
        }

        // The submission's id when the job carries one, so
        // `export_accepted` and everything this worker emits about the
        // job join across the two processes. Falls back to a fresh id
        // for a job submitted before `correlation_id` existed.
        //
        // This deliberately covers `chunk_written` too. Unlike the
        // Reconciler — where one tick claims rows from many unrelated
        // operations — the Chronicler processes one job across all its
        // chunks in a single continuous `process()` call, so the job IS
        // the operation and every event of it shares the id. An
        // abandoned re-claim (or a resumed, previously-yielded claim,
        // ADR 0050) reuses the same id for the same reason: it is the
        // same job, and `worker_identity` is what separates the
        // attempts.
        $correlationId = $claim->correlationId ?? UuidV4::generate();
        $this->logger->info('chronicler job claimed', [
            'event'           => 'job_claimed',
            'source'          => 'chronicler',
            'correlation_id'  => $correlationId,
            'tenant_id'       => $claim->tenantId,
            'job_id'          => $claim->id,
            'worker_identity' => $claim->workerIdentity,
            'claim_kind'      => $claim->claimKind->value,
        ]);

        $outcome = $this->processor->process($claim, $correlationId, $yield ?? $this->yieldSignal);

        return $outcome === JobOutcome::Yielded ? ChroniclerOutcome::YIELDED : ChroniclerOutcome::WORKED;
    }
}
