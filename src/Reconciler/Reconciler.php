<?php

declare(strict_types=1);

namespace StarDust\Reconciler;

use StarDust\Daemon\Tickable;
use StarDust\Support\UuidV4;

/**
 * Multi-worker reconciliation daemon (ADR 0008).
 *
 * Each `tick()` generates one `chunk_correlation_id` and gives every
 * configured {@see ReconcilerWorkSource} a chance to do one chunk of
 * work (round-robin, max one chunk per source per tick — prevents one
 * busy queue from starving another).
 *
 * - `WORK_DONE` from any source ⇒ sleep `interChunkDelayMicros` before
 *   moving to the next source, then continue the tick. Paces drain
 *   throughput per Phase 5 deliverable "configurable inter-chunk
 *   delay". The default is 0 (no pacing); operators tune via
 *   `Config::$reconcilerInterChunkDelayMicros`.
 * - `CAPACITY_WAIT` from any source ⇒ sleep
 *   `Config::$reconcilerCapacityWaitMillis` to give the Watcher time
 *   to provision; do not try other sources this tick (capacity for
 *   one source likely means capacity for others).
 * - Both sources IDLE ⇒ tick returns and the poll loop handles the
 *   sleep.
 *
 * Multiple Reconciler processes run safely: every queue claim uses
 * `SELECT … FOR UPDATE SKIP LOCKED` or an atomic claim UPDATE, and
 * every chunk commits or rolls back as one unit.
 */
final class Reconciler implements Tickable
{
    /** @var list<ReconcilerWorkSource> */
    private readonly array $workSources;

    /**
     * @param list<ReconcilerWorkSource> $workSources         Iterated in order each tick.
     * @param int                        $capacityWaitMillis  Sleep on CAPACITY_WAIT.
     * @param int                        $interChunkDelayMicros Sleep after each WORK_DONE outcome.
     * @param callable(int):void|null    $sleepFn             Injected for tests; defaults to `usleep`.
     */
    public function __construct(
        array $workSources,
        private readonly int $capacityWaitMillis,
        private readonly int $interChunkDelayMicros = 0,
        private $sleepFn = null,
    ) {
        $this->workSources = array_values($workSources);
        $this->sleepFn = $sleepFn ?? static fn (int $micros) => usleep($micros);
    }

    public function tick(): void
    {
        $chunkCorrelationId = UuidV4::generate();

        foreach ($this->workSources as $source) {
            $outcome = $source->tickOne($chunkCorrelationId);
            if ($outcome === TickOutcome::CAPACITY_WAIT) {
                ($this->sleepFn)($this->capacityWaitMillis * 1000);
                return;
            }
            if ($outcome === TickOutcome::WORK_DONE && $this->interChunkDelayMicros > 0) {
                ($this->sleepFn)($this->interChunkDelayMicros);
            }
        }
    }
}
