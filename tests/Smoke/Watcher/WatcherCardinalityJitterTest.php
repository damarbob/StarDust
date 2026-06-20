<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Watcher;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use StarDust\Clock\SystemClock;
use StarDust\Logging\StdoutNdjsonLogger;
use StarDust\Tests\Smoke\Phase5TestCase;

/**
 * Gap 7 — the cardinality sample must fire on a genuinely *jittered*
 * schedule, not a fixed offset. Two behaviours are locked:
 *
 *   1. The FIRST sample is phase-randomized across the whole interval
 *      (`now + rand(0, interval)`), so a lockstep-started fleet spreads
 *      across the day rather than all firing at ~24 h.
 *   2. Each subsequent sample fires at `interval ± jitter` with a FRESH
 *      draw per cycle — the precise inverse of the old deterministic
 *      `interval - 10%` cadence.
 *
 * Scheduling is driven through the injected clock + `jitterFn`, so the
 * test is deterministic and adds no wall-clock latency.
 */
final class WatcherCardinalityJitterTest extends Phase5TestCase
{
    public function testFirstSampleIsPhaseRandomizedAcrossTheInterval(): void
    {
        // One live slot with data so `cardinality_sampled` is observable.
        [$modelId, $_fieldId, $_pageId, $fieldName] = $this->setupModelWithReservedField(1, 'string');
        $this->seedEntry(1, $modelId, [$fieldName => 'a']);
        $this->seedEntry(1, $modelId, [$fieldName => 'b']);

        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $logger = new StdoutNdjsonLogger(new SystemClock(), $stream);

        $clock = $this->mutableClock();
        // First (phase) draw returns interval/4 = 250; the post-fire steady
        // draw is irrelevant here (0).
        $jitterFn = $this->scriptedJitter([250, 0]);

        $watcher = $this->makeWatcher(
            $logger,
            threshold: 0.0,                  // 0.0 ⇒ never provision; isolate scheduling
            clock: $clock,
            jitterFn: $jitterFn,
            cardinalityIntervalSeconds: 1000,
            cardinalityJitterSeconds: 100,
        );

        // t=0: first tick only schedules the first sample (due at 250).
        $clock->ts = 0;
        $watcher->tick();
        self::assertSame(0, $this->countSampled($stream), 'first tick must not sample — it phase-schedules');

        // t=249: still before the phase-randomized due time.
        $clock->ts = 249;
        $watcher->tick();
        self::assertSame(0, $this->countSampled($stream), 'no sample strictly before the due time');

        // t=250 (= interval/4, well before interval=1000): the sample fires.
        // Under the old fixed cadence it could only fire near the interval;
        // firing this early proves the phase is randomized across [0, interval].
        $clock->ts = 250;
        $watcher->tick();
        self::assertGreaterThan(0, $this->countSampled($stream), 'sample must fire at the phase-randomized due time');
    }

    public function testSteadyStateUsesSymmetricFreshDrawEachCycle(): void
    {
        [$modelId, $_fieldId, $_pageId, $fieldName] = $this->setupModelWithReservedField(1, 'string');
        $this->seedEntry(1, $modelId, [$fieldName => 'a']);
        $this->seedEntry(1, $modelId, [$fieldName => 'b']);

        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $logger = new StdoutNdjsonLogger(new SystemClock(), $stream);

        $clock = $this->mutableClock();
        // phase=0 (fire immediately), then steady draws +50 then -80.
        $jitterFn = $this->scriptedJitter([0, 50, -80, 0]);

        $watcher = $this->makeWatcher(
            $logger,
            threshold: 0.0,
            clock: $clock,
            jitterFn: $jitterFn,
            cardinalityIntervalSeconds: 1000,
            cardinalityJitterSeconds: 100,
        );

        $prev = 0;
        $fired = function (int $t) use ($watcher, $clock, $stream, &$prev): bool {
            $clock->ts = $t;
            $watcher->tick();
            $n = $this->countSampled($stream);
            $delta = $n - $prev;
            $prev = $n;
            return $delta > 0;
        };

        // First tick schedules (phase=0); the next tick at the same instant fires.
        self::assertFalse($fired(0), 'first tick schedules, does not sample');
        self::assertTrue($fired(0), 'sample fires once due (phase=0)');

        // Cycle 1: next due at interval + 50 = 1050.
        self::assertFalse($fired(1049), 'no sample one second before due');
        self::assertTrue($fired(1050), 'sample fires at interval + 50');

        // Cycle 2: next due at 1050 + interval - 80 = 1970.
        // Gap differs from cycle 1 (920 vs 1050) AND brackets the interval
        // on the opposite side — proving a fresh symmetric draw per cycle,
        // not a fixed offset.
        self::assertFalse($fired(1969), 'no sample one second before due');
        self::assertTrue($fired(1970), 'sample fires at prior + interval - 80');
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
            // Guard the script against drift: a steady-state draw must stay
            // within [min, max]; the phase draw uses min=0.
            return max($min, min($max, $value));
        };
    }

    private function countSampled($stream): int
    {
        rewind($stream);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($stream))));
        $count = 0;
        foreach ($lines as $line) {
            $event = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (($event['event'] ?? null) === 'cardinality_sampled') {
                $count++;
            }
        }
        return $count;
    }
}
