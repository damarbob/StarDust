<?php

declare(strict_types=1);

namespace StarDust\Watcher;

use Closure;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Daemon\AdvisoryLock;
use StarDust\Daemon\Tickable;
use StarDust\Exception\AdvisoryLockTimeoutException;
use StarDust\Page\PageProvisioner;
use StarDust\Support\UuidV4;
use Throwable;

/**
 * Singleton page-provisioning daemon (ADR 0008, ADR 0027).
 *
 * Each `tick()`:
 *   1. Asks {@see CapacityReporter} for slot capacity and
 *      {@see PendingDemandReader} for the fields waiting on a slot,
 *      then {@see ProvisioningPlanner} for the verdict. Emits
 *      `poll_started` carrying both.
 *   2. If the plan says provision, acquires
 *      `GET_LOCK('stardust_page_provision', 10)`, emits
 *      `provision_started`, calls {@see PageProvisioner::provision()}
 *      with the planner's indexed-column set, emits
 *      `provision_complete`, and releases the lock.
 *   3. If the jittered cardinality timer is due, runs
 *      {@see CardinalitySampler::sample()} and schedules the next sample.
 *   4. Emits `poll_complete`.
 *
 * ## Provisioning is demand-driven, not just capacity-driven
 *
 * Two triggers, OR-composed, and the planner owns the arithmetic:
 *   - **`unsatisfiable_demand`** — a slot family someone is waiting on
 *     has no claimable (indexed, free) slot. Fires regardless of the
 *     threshold; this is the starvation-freedom guarantee, and without
 *     it satisfiable demand in one family dilutes the ratio and starves
 *     a waiter in another indefinitely.
 *   - **`low_capacity`** — the global free ratio fell below
 *     `Config::$watcherCapacityThreshold`. Unchanged behaviour.
 *
 * The new page's indexed columns come from that demand: enough of each
 * demanded family to cover its shortfall, floored at one column per
 * demanded family and capped at the family's per-page capacity. With no
 * demand the set is empty and the page is pure headroom.
 *
 * `usable_free_ratio` is reported on every poll but is deliberately NOT
 * a trigger — as a threshold it diverges, provisioning a page per tick.
 * {@see ProvisioningPlanner} carries the proof; do not turn it into one.
 *
 * Because `threshold` is no longer the only trigger, setting it to
 * `0.0` no longer means "never provision": a starved family still
 * provisions.
 *
 * Cardinality scheduling (ADR 0019 "every 24 h, jittered to avoid
 * stampedes"). Two mechanisms, off the injected `$jitterFn` RNG:
 *   - The FIRST sample is phase-randomized across the whole interval
 *     (`now + rand(0, interval)`), so a fleet of daemons started in
 *     lockstep by one orchestrator rollout spreads across the full day
 *     on day one instead of clumping in a narrow band.
 *   - Every subsequent sample fires at `interval ± jitter` (a fresh
 *     draw each cycle), which prevents the fleet from re-synchronizing.
 *
 * Failure mapping:
 *   - {@see AdvisoryLockTimeoutException} → `lock_contention`.
 *   - any other `Throwable` from the provision path → `provision_failed`
 *     (re-thrown so the daemon loop terminates and the process exits).
 *
 * Process-level singleton enforcement is the CLI's job
 * ({@see \StarDust\Daemon\PidFileGuard}); this class assumes it.
 */
final class Watcher implements Tickable
{
    /** UTC epoch second at which the next cardinality sample becomes due. */
    private ?int $nextCardinalitySampleAt = null;

    /** @var Closure(int, int): int RNG returning a value in [min, max]. */
    private readonly Closure $jitterFn;

    /**
     * @param (Closure(int, int): int)|null $jitterFn injectable RNG
     *        (signature mirrors `random_int`); defaults to `random_int`.
     *        Tests pass a scripted closure to drive scheduling
     *        deterministically.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly CapacityReporter $capacityReporter,
        private readonly PendingDemandReader $pendingDemandReader,
        private readonly PageProvisioner $pageProvisioner,
        private readonly CardinalitySampler $cardinalitySampler,
        private readonly float $capacityThreshold,
        private readonly int $cardinalityIntervalSeconds,
        private readonly int $cardinalityJitterSeconds,
        ?Closure $jitterFn = null,
        private readonly int $provisionLockTimeoutSeconds = 10,
    ) {
        $this->jitterFn = $jitterFn ?? static fn (int $min, int $max): int => random_int($min, $max);
    }

    public function tick(): void
    {
        $correlationId = UuidV4::generate();
        $snapshot = $this->capacityReporter->report();
        $demand   = $this->pendingDemandReader->read();
        $plan     = ProvisioningPlanner::plan($snapshot, $demand, $this->capacityThreshold);

        $this->logger->info('watcher poll started', [
            'event'              => 'poll_started',
            'source'             => 'watcher',
            'correlation_id'     => $correlationId,
            'free_ratio'         => round($snapshot->globalFreeRatio(), 4),
            'threshold'          => $this->capacityThreshold,
            'total_slots'        => $snapshot->totalSlots,
            'free_slots'         => $snapshot->totalFree,
            'pages_inspected'    => $snapshot->pagesInspected,
            'usable_free_slots'  => $plan->usableFree,
            'usable_total_slots' => $plan->usableTotal,
            'usable_free_ratio'  => round($plan->usableFreeRatio, 4),
            'pending_demand'     => $demand->forLog(),
            'pending_waiters'    => $demand->totalWaiters(),
            'starved_families'   => $plan->starvedFamilies,
        ]);

        $action = 'no_action';
        if ($plan->shouldProvision) {
            $action = $this->tryProvision($correlationId, $plan, $demand);
        }

        if ($this->shouldSampleCardinality()) {
            $this->cardinalitySampler->sample();
            $this->scheduleNextCardinalitySample($this->clock->now()->getTimestamp());
        }

        $this->logger->info('watcher poll complete', [
            'event'          => 'poll_complete',
            'source'         => 'watcher',
            'correlation_id' => $correlationId,
            'action'         => $action,
            'trigger'        => $plan->trigger,
        ]);
    }

    /**
     * The plan is read before the lock is acquired, so a concurrent
     * reservation during a lock wait can stale it. Considered and
     * accepted rather than re-reading under the lock: the worst case is
     * one redundant page (page growth is already monotonic by design)
     * or a one-tick delay that the next poll corrects, and the
     * singleton guarantee narrows the race to a single other writer.
     * Re-reading would double the round-trips for that.
     */
    private function tryProvision(
        string $correlationId,
        ProvisioningPlan $plan,
        PendingDemand $demand,
    ): string {
        try {
            $lock = AdvisoryLock::acquire($this->pdo, 'stardust_page_provision', $this->provisionLockTimeoutSeconds);
        } catch (AdvisoryLockTimeoutException $e) {
            $this->logger->warning('page provision lock contention', [
                'event'          => 'lock_contention',
                'source'         => 'watcher',
                'correlation_id' => $correlationId,
                'message'        => $e->getMessage(),
            ]);
            return 'lock_contention';
        }

        try {
            // Logged before the DDL so the intent survives a crash
            // inside the provisioning window.
            $this->logger->info('page provision started', [
                'event'           => 'provision_started',
                'source'          => 'watcher',
                'correlation_id'  => $correlationId,
                'trigger'         => $plan->trigger,
                'indexed_columns' => $plan->indexedColumns,
                'pending_demand'  => $demand->forLog(),
            ]);

            $pageId = $this->pageProvisioner->provision($plan->indexedColumns);

            $this->logger->info('page provision complete', [
                'event'           => 'provision_complete',
                'source'          => 'watcher',
                'correlation_id'  => $correlationId,
                'page_id'         => $pageId,
                'trigger'         => $plan->trigger,
                'indexed_columns' => $plan->indexedColumns,
                'pending_demand'  => $demand->forLog(),
            ]);

            return 'provisioned';
        } catch (Throwable $e) {
            $this->logger->error('page provision failed', [
                'event'           => 'provision_failed',
                'source'          => 'watcher',
                'correlation_id'  => $correlationId,
                'message'         => $e->getMessage(),
                // A rejected column name is diagnosed by exactly this.
                'indexed_columns' => $plan->indexedColumns,
            ]);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private function shouldSampleCardinality(): bool
    {
        $now = $this->clock->now()->getTimestamp();

        if ($this->nextCardinalitySampleAt === null) {
            // First fire: phase-randomize across the whole interval so a
            // lockstep-started fleet spreads over the full day (ADR 0019).
            $phase = $this->cardinalityIntervalSeconds > 0
                ? ($this->jitterFn)(0, $this->cardinalityIntervalSeconds)
                : 0;
            $this->nextCardinalitySampleAt = $now + $phase;
            return false;
        }

        return $now >= $this->nextCardinalitySampleAt;
    }

    private function scheduleNextCardinalitySample(int $from): void
    {
        // Steady state: interval ± jitter, a fresh draw each cycle to
        // prevent the fleet from re-synchronizing. Clamp the offset so a
        // misconfigured `jitter > interval` can never schedule in the past.
        $jitter = min($this->cardinalityJitterSeconds, $this->cardinalityIntervalSeconds);
        $offset = $jitter > 0 ? ($this->jitterFn)(-$jitter, $jitter) : 0;
        $this->nextCardinalitySampleAt = $from + $this->cardinalityIntervalSeconds + $offset;
    }
}
