<?php

declare(strict_types=1);

namespace StarDust\Chronicler;

use Psr\Log\LoggerInterface;
use StarDust\Daemon\Tickable;
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
 */
final class Chronicler implements Tickable
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ExportJobClaimer $claimer,
        private readonly ExportJobProcessor $processor,
        private readonly DiskPressureGate $diskGate,
        private readonly GcSweeper $gcSweeper,
    ) {
    }

    public function tick(): void
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
            return;
        }

        $claim = $this->claimer->claimPendingOrAbandoned();
        if ($claim === null) {
            // Idle cycle: run GC, then let the outer PollLoop sleep.
            $this->gcSweeper->sweep(UuidV4::generate());
            return;
        }

        $correlationId = UuidV4::generate();
        $this->logger->info('chronicler job claimed', [
            'event'           => 'job_claimed',
            'source'          => 'chronicler',
            'correlation_id'  => $correlationId,
            'tenant_id'       => $claim->tenantId,
            'job_id'          => $claim->id,
            'worker_identity' => $claim->workerIdentity,
            'claim_kind'      => $claim->claimKind->value,
        ]);

        $this->processor->process($claim, $correlationId);
    }
}
