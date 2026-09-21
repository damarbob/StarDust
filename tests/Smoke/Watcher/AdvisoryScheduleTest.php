<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Watcher;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Psr\Clock\ClockInterface;
use StarDust\Clock\SystemClock;
use StarDust\Logging\StdoutNdjsonLogger;
use StarDust\Page\PageProvisioner;
use StarDust\Tests\Smoke\Phase5TestCase;
use StarDust\Watcher\AdvisoryScheduleRepository;
use StarDust\Watcher\CapacityReporter;
use StarDust\Watcher\CardinalitySampler;
use StarDust\Watcher\PendingDemandReader;
use StarDust\Watcher\Watcher;

/**
 * ADR 0052 — the advisory schedule is persisted, so it survives a
 * process that exits after every run.
 *
 * `WatcherCardinalityJitterTest` is the companion file and must keep
 * passing unmodified: it pins ADR 0019's phase randomization and the
 * fresh per-cycle symmetric draw, which this change moves to the
 * database without altering. This file pins what the move *adds*.
 *
 * The scenario that matters is the cron deployment model: every
 * `bin/stardust tick` builds a fresh object graph, so the schedule has
 * to be readable by a `Watcher` that did not write it.
 */
final class AdvisoryScheduleTest extends Phase5TestCase
{
    /**
     * THE defect this change closes.
     *
     * Two independently constructed `Watcher` instances, exactly as two
     * successive `bin/stardust tick` invocations produce. The first
     * schedules and exits; the second must find that schedule and
     * sample. Against the old process-local `$nextAdvisorySampleAt` the
     * second instance got its own always-false first due-check and the
     * advisories never fired at all — verified red before the fix.
     */
    public function testAFreshWatcherInheritsTheScheduleAPreviousOneWrote(): void
    {
        $stream = $this->seedOneSampleableSlot();
        $logger = new StdoutNdjsonLogger(new SystemClock(), $stream);

        $clockA = $this->mutableClock();
        $first = $this->makeWatcher(
            $logger,
            threshold: 0.0,
            clock: $clockA,
            jitterFn: $this->scriptedJitter([250]),
            cardinalityIntervalSeconds: 1000,
            cardinalityJitterSeconds: 100,
        );

        $clockA->ts = 0;
        $first->tick();
        self::assertSame(0, $this->countEvent($stream, 'cardinality_sampled'), 'the first process only schedules');
        self::assertSame(250, $this->storedNextSampleAt(), 'the due time must be persisted, not held in the object');

        // A second, wholly separate Watcher — the next cron invocation.
        unset($first);
        $clockB = $this->mutableClock();
        $second = $this->makeWatcher(
            $logger,
            threshold: 0.0,
            clock: $clockB,
            jitterFn: $this->scriptedJitter([0]),
            cardinalityIntervalSeconds: 1000,
            cardinalityJitterSeconds: 100,
        );

        $clockB->ts = 250;
        $second->tick();

        self::assertGreaterThan(
            0,
            $this->countEvent($stream, 'cardinality_sampled'),
            'a fresh Watcher must honour the persisted schedule — this is the whole point of ADR 0052',
        );
        self::assertGreaterThan(0, $this->countEvent($stream, 'spread_sampled'));
        self::assertSame(1250, $this->storedNextSampleAt(), 'the claim must advance the schedule by interval ± jitter');
    }

    /**
     * The claim advanced the schedule, so a second tick at the same
     * instant must not sample again. This is what makes `CombinedTick`
     * safe: it calls `Watcher::tick()` once per run unconditionally and
     * again on every `CAPACITY_WAIT` round.
     */
    public function testASecondTickAtTheSameInstantDoesNotResample(): void
    {
        $stream = $this->seedOneSampleableSlot();
        $logger = new StdoutNdjsonLogger(new SystemClock(), $stream);

        $clock = $this->mutableClock();
        $watcher = $this->makeWatcher(
            $logger,
            threshold: 0.0,
            clock: $clock,
            jitterFn: $this->scriptedJitter([0, 0, 0]),
            cardinalityIntervalSeconds: 1000,
            cardinalityJitterSeconds: 100,
        );

        $clock->ts = 0;
        $watcher->tick();               // schedules, due at 0
        $watcher->tick();               // claims and samples
        $afterFirst = $this->countEvent($stream, 'cardinality_sampled');
        self::assertGreaterThan(0, $afterFirst);

        $watcher->tick();               // same instant, already claimed
        self::assertSame($afterFirst, $this->countEvent($stream, 'cardinality_sampled'));
    }

    /**
     * Genuine cross-connection exclusion of the claim itself, on two
     * sibling PDO sessions — the `SlotAffinityTest` /
     * `ChroniclerMultiWorkerClaimTest` precedent.
     *
     * This drives the repository rather than two `Watcher`s on purpose.
     * Going through `tick()` cannot reach the question: each tick reads
     * and then immediately claims, so the second Watcher's *read*
     * already sees the first one's advanced schedule and returns before
     * `claimDue()` is ever called. A Watcher-level test therefore stays
     * green even with the dueness predicate deleted — measured, not
     * assumed. Here both connections take the same stale read first,
     * which is the only arrangement in which the SQL predicate is what
     * decides.
     */
    public function testOnlyOneOfTwoConnectionsClaimsTheSameDueSample(): void
    {
        $sibling = $this->siblingConnection();

        $clock = $this->mutableClock();
        $clock->ts = 0;

        $a = new AdvisoryScheduleRepository($this->pdo, $clock);
        $b = new AdvisoryScheduleRepository($sibling, $clock);

        self::assertTrue($a->scheduleFirst(0), 'the sample is due at t=0');

        // Both observe the same due time before either claims — two cron
        // invocations, or two hosts, that overlap.
        self::assertSame(0, $a->read());
        self::assertSame(0, $b->read());

        // The two next-due values must DIFFER, or the assertion below
        // passes for the wrong reason: MySQL reports changed rows, so a
        // loser that wrote back the winner's exact values would report
        // zero affected rows even with the dueness predicate deleted.
        // Distinct values make the predicate the only thing that can
        // decide — verified by deleting it and watching this go red.
        $wins = array_filter([
            $a->claimDue(0, 1000),
            $b->claimDue(0, 2000),
        ]);

        self::assertCount(
            1,
            $wins,
            'exactly one connection may win the claim on a due advisory sample',
        );
        self::assertSame(1000, $a->read(), 'the loser must not overwrite the winner\'s schedule');
    }

    /**
     * The same exclusion at the level an operator sees it: two
     * independently constructed Watchers on separate connections
     * produce ONE sweep, not two.
     */
    public function testTwoConnectionsProduceOneSweepNotTwo(): void
    {
        $stream = $this->seedOneSampleableSlot();
        $logger = new StdoutNdjsonLogger(new SystemClock(), $stream);

        $sibling = $this->siblingConnection();

        $clock = $this->mutableClock();
        $clock->ts = 0;

        $a = $this->makeWatcher(
            $logger,
            threshold: 0.0,
            clock: $clock,
            jitterFn: $this->scriptedJitter([0, 0]),
            cardinalityIntervalSeconds: 1000,
            cardinalityJitterSeconds: 100,
        );
        $b = $this->watcherOn($sibling, $logger, $clock, $this->scriptedJitter([0, 0]));

        $a->tick();                     // schedules, due at 0
        self::assertSame(0, $this->countEvent($stream, 'cardinality_sampled'));

        $a->tick();
        $b->tick();

        // One live slot and one tenant, so a winning sweep emits exactly
        // one cardinality_sampled.
        self::assertSame(
            1,
            $this->countEvent($stream, 'cardinality_sampled'),
            'the fleet-wide schedule must yield one sweep per interval, not one per daemon',
        );
    }

    public function testFirstObservationSchedulesWithinTheIntervalAndSamplesNothing(): void
    {
        $stream = $this->seedOneSampleableSlot();
        $logger = new StdoutNdjsonLogger(new SystemClock(), $stream);

        self::assertNull($this->storedNextSampleAt(), 'a fresh bootstrap leaves the schedule unset');

        $clock = $this->mutableClock();
        $clock->ts = 5_000;
        $watcher = $this->makeWatcher(
            $logger,
            threshold: 0.0,
            clock: $clock,
            // Phase draw is clamped to [0, interval] by the scripted RNG,
            // so asking for 999 lands inside the interval.
            jitterFn: $this->scriptedJitter([999]),
            cardinalityIntervalSeconds: 1000,
            cardinalityJitterSeconds: 100,
        );

        $watcher->tick();

        self::assertSame(0, $this->countEvent($stream, 'cardinality_sampled'));
        self::assertSame(5_999, $this->storedNextSampleAt());
    }

    /**
     * `poll_complete` reports whether the tick sampled. A lost claim is
     * otherwise indistinguishable on the wire from a tick that was
     * simply not due.
     */
    public function testPollCompleteReportsWhetherTheAdvisoriesSampled(): void
    {
        $stream = $this->seedOneSampleableSlot();
        $logger = new StdoutNdjsonLogger(new SystemClock(), $stream);

        $clock = $this->mutableClock();
        $clock->ts = 0;
        $watcher = $this->makeWatcher(
            $logger,
            threshold: 0.0,
            clock: $clock,
            jitterFn: $this->scriptedJitter([0, 0]),
            cardinalityIntervalSeconds: 1000,
            cardinalityJitterSeconds: 100,
        );

        $watcher->tick();
        self::assertFalse($this->lastPollCompleteFlag($stream), 'the scheduling tick did not sample');

        $watcher->tick();
        self::assertTrue($this->lastPollCompleteFlag($stream), 'the claiming tick did');
    }

    /**
     * The `tick --advisories` force path. It must sample whatever the
     * schedule says AND reset the timer, or a forced sample would be
     * followed moments later by a scheduled one.
     */
    public function testSampleAdvisoriesForcesASampleAndResetsTheSchedule(): void
    {
        $stream = $this->seedOneSampleableSlot();
        $logger = new StdoutNdjsonLogger(new SystemClock(), $stream);

        $clock = $this->mutableClock();
        $clock->ts = 7_000;
        $watcher = $this->makeWatcher(
            $logger,
            threshold: 0.0,
            clock: $clock,
            jitterFn: $this->scriptedJitter([40]),
            cardinalityIntervalSeconds: 1000,
            cardinalityJitterSeconds: 100,
        );

        // Nothing is scheduled, so an unforced tick could not have sampled.
        self::assertNull($this->storedNextSampleAt());

        $watcher->sampleAdvisories('forced-correlation-id');

        self::assertGreaterThan(0, $this->countEvent($stream, 'cardinality_sampled'));
        self::assertGreaterThan(0, $this->countEvent($stream, 'spread_sampled'));
        self::assertSame(8_040, $this->storedNextSampleAt(), 'a forced sample must reset the timer');
    }

    /**
     * The `$from + 1` floor in `computeNextDue()`. MySQL reports changed
     * rows rather than matched rows, so a claim that wrote back the
     * value already stored would report zero affected rows and skip the
     * sample in silence. A degenerate `interval = 0, jitter = 0` config
     * is the only way to reach it — and it must still make progress.
     */
    public function testAZeroIntervalStillAdvancesTheScheduleAndKeepsSampling(): void
    {
        $stream = $this->seedOneSampleableSlot();
        $logger = new StdoutNdjsonLogger(new SystemClock(), $stream);

        $clock = $this->mutableClock();
        $clock->ts = 100;
        $watcher = $this->makeWatcher(
            $logger,
            threshold: 0.0,
            clock: $clock,
            jitterFn: $this->scriptedJitter([0, 0, 0, 0]),
            cardinalityIntervalSeconds: 0,
            cardinalityJitterSeconds: 0,
        );

        $watcher->tick();                                   // schedules at 100 + 0
        $watcher->tick();                                   // due now; claims
        self::assertSame(1, $this->countEvent($stream, 'cardinality_sampled'));
        self::assertSame(101, $this->storedNextSampleAt(), 'the row must advance or the claim reports zero rows');

        $clock->ts = 101;
        $watcher->tick();
        self::assertSame(2, $this->countEvent($stream, 'cardinality_sampled'));
    }

    /**
     * Re-running `bin/stardust bootstrap` against a live deployment must
     * not re-randomize a schedule that is already running.
     */
    public function testReBootstrapPreservesARunningSchedule(): void
    {
        $repository = new AdvisoryScheduleRepository($this->pdo, new SystemClock());
        self::assertTrue($repository->scheduleFirst(4_242));

        (new \StarDust\Bootstrap\Bootstrapper($this->pdo, $this->engine))->run();

        self::assertSame(4_242, $this->storedNextSampleAt());
    }

    /**
     * A row deleted out from under a running deployment must self-heal
     * rather than stall the advisories for ever.
     */
    public function testAMissingScheduleRowIsRecreatedRatherThanStalling(): void
    {
        $this->pdo->exec('DELETE FROM stardust_advisory_schedule');

        $repository = new AdvisoryScheduleRepository($this->pdo, new SystemClock());
        self::assertNull($repository->read());
        self::assertTrue($repository->scheduleFirst(9_000));
        self::assertSame(9_000, $repository->read());
    }

    // ---------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------

    /**
     * One live string slot carrying two values, so exactly one
     * `cardinality_sampled` is emitted per winning claim.
     *
     * @return resource the NDJSON log stream
     */
    private function seedOneSampleableSlot()
    {
        [$modelId, $_fieldId, $_pageId, $fieldName] = $this->setupModelWithReservedField(1, 'string');
        $this->seedEntry(1, $modelId, [$fieldName => 'a']);
        $this->seedEntry(1, $modelId, [$fieldName => 'b']);

        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);

        return $stream;
    }

    private function siblingConnection(): PDO
    {
        $dsn  = getenv('STARDUST_TEST_DSN');
        $user = getenv('STARDUST_TEST_USER');
        $pass = getenv('STARDUST_TEST_PASS');

        if ($dsn === false || $user === false) {
            self::markTestSkipped('STARDUST_TEST_DSN / STARDUST_TEST_USER not set.');
        }

        return new PDO($dsn, $user, $pass === false ? '' : $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    /**
     * A Watcher bound to a specific connection — `makeWatcher()` always
     * uses the fixture's own handle.
     */
    private function watcherOn(
        PDO $pdo,
        \Psr\Log\LoggerInterface $logger,
        ClockInterface $clock,
        \Closure $jitterFn,
    ): Watcher {
        return new Watcher(
            pdo: $pdo,
            clock: $clock,
            logger: $logger,
            capacityReporter: new CapacityReporter($pdo),
            pendingDemandReader: new PendingDemandReader($pdo),
            pageProvisioner: new PageProvisioner(pdo: $pdo, clock: new SystemClock(), logger: $logger, engine: $this->engine),
            cardinalitySampler: new CardinalitySampler(
                pdo: $pdo,
                logger: $logger,
                selectivityThreshold: 0.01,
                rowFloor: 10_000,
                distinctFloor: 10,
            ),
            spreadSampler: $this->makeSpreadSampler($logger),
            capacityThreshold: 0.0,
            cardinalityIntervalSeconds: 1000,
            cardinalityJitterSeconds: 100,
            jitterFn: $jitterFn,
            advisorySchedule: new AdvisoryScheduleRepository($pdo, $clock),
        );
    }

    /** The persisted due time as a UTC epoch second, or null when unset. */
    private function storedNextSampleAt(): ?int
    {
        $raw = $this->pdo
            ->query('SELECT next_sample_at FROM stardust_advisory_schedule WHERE id = 1')
            ->fetchColumn();

        if ($raw === false || $raw === null) {
            return null;
        }

        return (new DateTimeImmutable((string) $raw, new DateTimeZone('UTC')))->getTimestamp();
    }

    private function mutableClock(): ClockInterface
    {
        return new class implements ClockInterface {
            public int $ts = 0;

            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('@' . $this->ts);
            }
        };
    }

    /**
     * @param list<int> $returns successive values the RNG yields, in call order
     * @return \Closure(int, int): int
     */
    private function scriptedJitter(array $returns): \Closure
    {
        return static function (int $min, int $max) use (&$returns): int {
            $value = array_shift($returns) ?? 0;
            return max($min, min($max, $value));
        };
    }

    /** @param resource $stream */
    private function countEvent($stream, string $eventName): int
    {
        $count = 0;
        foreach ($this->events($stream) as $event) {
            if (($event['event'] ?? null) === $eventName) {
                $count++;
            }
        }

        return $count;
    }

    /** @param resource $stream */
    private function lastPollCompleteFlag($stream): ?bool
    {
        $flag = null;
        foreach ($this->events($stream) as $event) {
            if (($event['event'] ?? null) === 'poll_complete') {
                $flag = $event['advisories_sampled'] ?? null;
            }
        }

        self::assertNotNull($flag, 'poll_complete must carry advisories_sampled');

        return (bool) $flag;
    }

    /**
     * @param resource $stream
     * @return list<array<string, mixed>>
     */
    private function events($stream): array
    {
        rewind($stream);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($stream))));

        return array_map(
            static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            $lines,
        );
    }
}
