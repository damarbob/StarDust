<?php

declare(strict_types=1);

namespace StarDust\Liberator;

use Psr\Log\LoggerInterface;
use StarDust\Daemon\Tickable;
use StarDust\Support\UuidV4;

/**
 * Singleton slot-reclamation daemon (ADR 0008, ADR 0009, ADR 0027).
 *
 * Each `tick()`:
 *   1. Generates one `correlation_id` (UUID v4) shared by every event
 *      emitted this cycle.
 *   2. Asks {@see TombstonedSlotRepository} for the next batch of
 *      tombstoned slots (ordered `tombstoned_at ASC, page_id,
 *      slot_column`).
 *   3. If empty: returns silently — the idle path emits no events
 *      (blueprint AC#13, to avoid log spam).
 *   4. Emits `sweep_started` once with the batch size.
 *   5. Sweeps each slot via {@see SlotSweeper::sweep()}; per-chunk and
 *      per-slot events (`sweep_chunk`, `sweep_complete`,
 *      `deadlock_retry`, `sweep_gap_flagged`) are emitted by the
 *      sweeper.
 *
 * Process-level singleton enforcement is the CLI's job
 * ({@see \StarDust\Daemon\PidFileGuard} with
 * `LiberatorSingletonViolationException::class`); this class assumes
 * it. ADR 0009 fixes the singleton: a multi-worker Liberator multiplies
 * row-lock contention without improving throughput on an IO-bound
 * workload.
 *
 * Unhandled exceptions propagate out of `tick()`; the surrounding
 * {@see \StarDust\Daemon\PollLoop} lets them terminate the process,
 * mirroring the Watcher's "fail loudly on unexpected error" policy.
 */
final class Liberator implements Tickable
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly TombstonedSlotRepository $repository,
        private readonly SlotSweeper $sweeper,
    ) {
    }

    public function tick(): void
    {
        $slots = $this->repository->loadBatch();
        if ($slots === []) {
            return;
        }

        $correlationId = UuidV4::generate();
        $this->logger->info('liberator sweep started', [
            'event'          => 'sweep_started',
            'source'         => 'liberator',
            'correlation_id' => $correlationId,
            'batch_size'     => count($slots),
        ]);

        foreach ($slots as $slot) {
            $this->sweeper->sweep($slot, $correlationId);
        }
    }
}
