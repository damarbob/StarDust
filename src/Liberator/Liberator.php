<?php

declare(strict_types=1);

namespace StarDust\Liberator;

use Psr\Log\LoggerInterface;
use StarDust\Daemon\Tickable;
use StarDust\Support\UuidV4;

/**
 * Multi-worker slot-reclamation daemon (ADR 0008, ADR 0009, ADR 0027,
 * ADR 0049).
 *
 * Each `tick()`:
 *   1. Generates one `correlation_id` (UUID v4) shared by every event
 *      emitted this cycle.
 *   2. Asks {@see TombstonedSlotRepository} for the next batch of
 *      tombstoned slots (ordered `tombstoned_at ASC, page_id,
 *      slot_column`). Two workers may load the same batch — the batch
 *      read takes no claim; the per-slot page lock below is what
 *      serializes.
 *   3. If empty: returns silently — the idle path emits no events
 *      (blueprint AC#13, to avoid log spam).
 *   4. For each slot IN TURN, tries {@see SweepPageLock::tryAcquire()}
 *      on the slot's page; on contention it is counted and skipped for
 *      this cycle; on success the slot is swept immediately via
 *      {@see SlotSweeper::sweep()} and its page's lock released before
 *      the loop moves to the next slot. **Deliberately one slot at a
 *      time, not "acquire every claimable slot's lock, then sweep
 *      them all"** — a batch spanning several pages must not let one
 *      worker monopolize every page in it for the duration of the
 *      whole batch; a page this worker has not yet reached (or has
 *      already finished) stays available to another worker's tick the
 *      entire time, which is the concurrency ADR 0049 exists to unlock.
 *   5. Emits `sweep_started` once at the end of the cycle, but only
 *      when at least one slot was actually claimed — a fully-contended
 *      batch stays silent, per blueprint AC#13's no-spam-when-idle rule
 *      extended to "nothing to do because every candidate is already
 *      being swept". It carries the cycle's final tallies
 *      (`slots_claimed` / `slots_contended`) rather than firing before
 *      any sweeping starts, since those tallies are not known until the
 *      whole batch has been walked — the per-slot `sweep_chunk` /
 *      `sweep_complete` events (each carrying their own
 *      `correlation_id`) are what a log reader traces chronologically;
 *      this one is the cycle's summary.
 *
 * ADR 0049 replaces the process-level singleton (a strict PID-file
 * lock enforced by the CLI) with page-table-granularity exclusion via
 * `GET_LOCK` — see {@see SweepPageLock}. `bin/stardust liberator` is
 * now multi-worker safe, like `reconciler` and `chronicler`; horizontal
 * scaling adds throughput because distinct workers sweep distinct
 * `entry_slots_page_N` tables, never the same one concurrently.
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
        private readonly SweepPageLock $pageLock,
        private readonly string $workerIdentity,
    ) {
    }

    public function tick(): void
    {
        $this->sweepBatch();
    }

    /**
     * The body of `tick()`, returning the number of slots actually
     * swept rather than `void`.
     *
     * `tick()` (the {@see Tickable} contract used by the standalone
     * `bin/stardust liberator` poll loop) delegates here and discards
     * the result; {@see \StarDust\Daemon\CombinedTick} calls this
     * directly so a fully idle round can be detected without a second
     * query against `stardust_slot_assignments`. **Must return the
     * count actually claimed and swept, not the batch size** — a batch
     * that is entirely contended by other workers made zero progress,
     * and `CombinedTick` relies on that to detect an idle round; a
     * batch-size return would make a fully-contended tick look like
     * progress and defeat ADR 0048's idle-exit.
     */
    public function sweepBatch(): int
    {
        $slots = $this->repository->loadBatch();
        if ($slots === []) {
            return 0;
        }

        $correlationId = UuidV4::generate();
        $claimedCount = 0;
        $contended = 0;

        foreach ($slots as $slot) {
            $lock = $this->pageLock->tryAcquire($slot->pageId);
            if ($lock === null) {
                $contended++;
                continue;
            }
            $claimedCount++;
            try {
                $this->sweeper->sweep($slot, $correlationId, $this->workerIdentity);
            } finally {
                $lock->release();
            }
        }

        if ($claimedCount > 0) {
            $this->logger->info('liberator sweep started', [
                'event'           => 'sweep_started',
                'source'          => 'liberator',
                'correlation_id'  => $correlationId,
                'worker_identity' => $this->workerIdentity,
                'batch_size'      => count($slots),
                'slots_claimed'   => $claimedCount,
                'slots_contended' => $contended,
            ]);
        }

        return $claimedCount;
    }
}
