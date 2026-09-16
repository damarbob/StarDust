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
 *   3. If the jittered advisory timer is due, runs BOTH
 *      {@see CardinalitySampler::sample()} (ADR 0019) and
 *      {@see SpreadSampler::sampleAll()} (ADR 0031), then schedules the
 *      next sample.
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
 * ## One advisory timer, two samplers
 *
 * The cardinality advisory (ADR 0019) and the spread advisory (ADR 0031)
 * share a single schedule and a single due-check. ADR 0031 §Sampling
 * Triggers 1 requires this explicitly: spread drifts only on registry
 * mutation, so a daily cadence is generous, and a second timer would be
 * a second stampede surface for no benefit. If you add a third advisory,
 * hang it off this same gate rather than giving it its own.
 *
 * The `Config::$cardinality*` field names are unchanged even though they
 * now pace both samplers — they are public surface, and renaming them
 * would break every consumer's constructor call for a comment's worth of
 * clarity.
 *
 * Advisory scheduling (ADR 0019 "every 24 h, jittered to avoid
 * stampedes"). Two mechanisms, off the injected `$jitterFn` RNG:
 *   - The FIRST sample is phase-randomized across the whole interval
 *     (`now + rand(0, interval)`), so a fleet of daemons started in
 *     lockstep by one orchestrator rollout spreads across the full day
 *     on day one instead of clumping in a narrow band.
 *   - Every subsequent sample fires at `interval ± jitter` (a fresh
 *     draw each cycle), which prevents the fleet from re-synchronizing.
 *
 * ## The schedule is persisted and fleet-wide (ADR 0052)
 *
 * It lives in `stardust_advisory_schedule`, not in a property, because
 * a process-local field cannot survive a process that exits after every
 * run — which is exactly what ADR 0048's `CombinedTick` is under the
 * cron deployment model, and it meant the advisories never fired there
 * without the `--advisories` flag. One consequence worth knowing: the
 * schedule is now shared, so ONE sample fires per interval across the
 * whole deployment rather than one per daemon.
 * {@see AdvisoryScheduleRepository} carries the claim and its traps.
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
    /** @var Closure(int, int): int RNG returning a value in [min, max]. */
    private readonly Closure $jitterFn;

    /** ADR 0042 index headroom, applied to every page this daemon provisions. */
    private readonly IndexHeadroomPolicy $headroomPolicy;

    /** ADR 0052 persisted advisory schedule — see shouldSampleAdvisories(). */
    private readonly AdvisoryScheduleRepository $advisorySchedule;

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
        private readonly SpreadSampler $spreadSampler,
        private readonly float $capacityThreshold,
        private readonly int $cardinalityIntervalSeconds,
        private readonly int $cardinalityJitterSeconds,
        ?Closure $jitterFn = null,
        private readonly int $provisionLockTimeoutSeconds = 10,
        ?IndexHeadroomPolicy $headroomPolicy = null,
        ?AdvisoryScheduleRepository $advisorySchedule = null,
    ) {
        $this->jitterFn = $jitterFn ?? static fn (int $min, int $max): int => random_int($min, $max);
        // Defaulted for the same reason $provisionLockTimeoutSeconds is:
        // a direct constructor call (fixtures, one-off scripts) should not
        // have to know the policy. Config owns the value operators tune.
        // Must track Config::$pageIndexHeadroom's default: Phase5TestCase's
        // makeWatcher() passes no policy, so this value is what the whole
        // Watcher smoke suite runs on. See src/Watcher/CLAUDE.md.
        $this->headroomPolicy = $headroomPolicy ?? new FlatIndexHeadroom(4);
        // Same nullable-collaborator shape as $jitterFn above, and for the
        // same reason: a direct constructor call (fixtures, one-off
        // scripts) should not have to assemble it. Unlike
        // $headroomPolicy this fallback duplicates no *value*, so it
        // carries none of that one's hand-tracking hazard — it is pure
        // construction from deps this class already holds.
        $this->advisorySchedule = $advisorySchedule ?? new AdvisoryScheduleRepository($pdo, $clock);
    }

    public function tick(): void
    {
        $correlationId = UuidV4::generate();
        $snapshot = $this->capacityReporter->report();
        $demand   = $this->pendingDemandReader->read();
        $plan     = ProvisioningPlanner::plan($snapshot, $demand, $this->capacityThreshold, $this->headroomPolicy);

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

        // The claim inside shouldSampleAdvisories() already advanced the
        // schedule, so this does NOT call sampleAdvisories() — that one
        // forces a sample and resets the timer a second time.
        $sampledAdvisories = $this->shouldSampleAdvisories();
        if ($sampledAdvisories) {
            // Both advisories run inside this tick, so both carry its
            // cycle id — a daily sweep emits hundreds of samples and an
            // operator needs to see them as one sweep, not as hundreds
            // of unrelated observations.
            $this->cardinalitySampler->sample($correlationId);
            $this->spreadSampler->sampleAll($correlationId);
        }

        $this->logger->info('watcher poll complete', [
            'event'          => 'poll_complete',
            'source'         => 'watcher',
            'correlation_id' => $correlationId,
            'action'         => $action,
            'trigger'        => $plan->trigger,
            // A field on an existing event, not a new event name: a lost
            // claim stays silent, matching the Liberator's contended-tick
            // silence, and this is the only way to tell "not due" from
            // "due but another host got it" on the wire.
            'advisories_sampled' => $sampledAdvisories,
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

            // The cycle id goes down with it so `page_provisioned` joins
            // the provision_started/provision_complete pair around it,
            // per ADR 0020's carried-through-sub-events clause.
            $pageId = $this->pageProvisioner->provision($plan->indexedColumns, $correlationId);

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

    /**
     * Runs both advisory samplers unconditionally, bypassing the
     * due-check {@see shouldSampleAdvisories()} normally gates them
     * behind, and resets the schedule as if the sample had been due.
     *
     * This is the `bin/stardust tick --advisories` force path. Since
     * ADR 0052 it is no longer the *only* way the advisories fire under
     * the cron deployment model — the schedule is persisted, so an
     * unadorned `bin/stardust tick` reaches them on its own. The flag
     * survives as "sample now regardless", which is a different and
     * still-useful thing.
     *
     * It resets the timer rather than leaving it alone so a forced
     * sample is not followed minutes later by a scheduled one.
     */
    public function sampleAdvisories(string $correlationId): void
    {
        $this->cardinalitySampler->sample($correlationId);
        $this->spreadSampler->sampleAll($correlationId);

        $now = $this->clock->now()->getTimestamp();
        $this->advisorySchedule->force($now, $this->computeNextDue($now));
    }

    /**
     * True when this process won the claim on a due advisory sample.
     *
     * The state lives in `stardust_advisory_schedule` rather than in a
     * property (ADR 0052), so the schedule survives a process that
     * exits after every run. The read is non-locking and may be stale;
     * the claim re-evaluates under the row lock, so exactness comes
     * from {@see AdvisoryScheduleRepository::claimDue()} and the read
     * only decides whether a claim is worth attempting — which keeps a
     * not-due tick to one SELECT.
     *
     * **The next due time is now computed before the samplers run**,
     * not after, because the claim and the reschedule are necessarily
     * the same statement. The cadence therefore stops drifting forward
     * by each sweep's duration, which the in-memory version did by
     * rescheduling from `now()` once the sweep had finished.
     */
    private function shouldSampleAdvisories(): bool
    {
        $now = $this->clock->now()->getTimestamp();
        $due = $this->advisorySchedule->read();

        if ($due === null) {
            // First fire: phase-randomize across the whole interval so a
            // lockstep-started fleet spreads over the full day (ADR 0019).
            $phase = $this->cardinalityIntervalSeconds > 0
                ? ($this->jitterFn)(0, $this->cardinalityIntervalSeconds)
                : 0;
            $this->advisorySchedule->scheduleFirst($now + $phase);
            return false;
        }

        if ($now < $due) {
            return false;
        }

        return $this->advisorySchedule->claimDue($now, $this->computeNextDue($now));
    }

    /**
     * Steady state: interval ± jitter, a fresh draw each cycle to
     * prevent the fleet from re-synchronizing. The offset is clamped so
     * a misconfigured `jitter > interval` can never schedule in the
     * past.
     *
     * The `$from + 1` floor is load-bearing and not defensive rounding:
     * MySQL reports *changed* rows rather than matched rows, and the
     * engine cannot set `CLIENT_FOUND_ROWS` on an injected PDO, so a
     * claim that wrote back the value already stored would report zero
     * affected rows and skip the sample in silence. Measured on 8.0.13.
     * At the 86 400 s default the floor is unreachable; it only bites a
     * degenerate `interval = 0, jitter = 0` configuration.
     */
    private function computeNextDue(int $from): int
    {
        $jitter = min($this->cardinalityJitterSeconds, $this->cardinalityIntervalSeconds);
        $offset = $jitter > 0 ? ($this->jitterFn)(-$jitter, $jitter) : 0;

        return max($from + $this->cardinalityIntervalSeconds + $offset, $from + 1);
    }
}
