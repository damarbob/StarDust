<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Reconciler;

use PHPUnit\Framework\TestCase;
use StarDust\Reconciler\Reconciler;
use StarDust\Reconciler\ReconcilerWorkSource;
use StarDust\Reconciler\TickOutcome;

/**
 * Reconciler::tick() orchestration. Pure-PHP test — no DB. Verifies:
 *   - round-robin order across work sources;
 *   - CAPACITY_WAIT from any source short-circuits the tick and triggers
 *     `$capacityWaitMillis` sleep;
 *   - WORK_DONE from a source triggers `$interChunkDelayMicros` sleep
 *     before the next source;
 *   - IDLE from a source does NOT trigger any sleep.
 */
final class ReconcilerTickTest extends TestCase
{
    public function testTicksWorkSourcesInOrder(): void
    {
        $callOrder = [];
        $sourceA = $this->recordingSource('A', TickOutcome::WORK_DONE, $callOrder);
        $sourceB = $this->recordingSource('B', TickOutcome::IDLE, $callOrder);

        $reconciler = new Reconciler(
            workSources: [$sourceA, $sourceB],
            capacityWaitMillis: 100,
            interChunkDelayMicros: 0,
            sleepFn: static fn (int $_) => null,
        );
        $reconciler->tick();

        self::assertSame(['A', 'B'], $callOrder);
    }

    public function testCapacityWaitShortCircuitsAndSleeps(): void
    {
        $callOrder = [];
        $sleeps = [];
        $sourceA = $this->recordingSource('A', TickOutcome::CAPACITY_WAIT, $callOrder);
        $sourceB = $this->recordingSource('B', TickOutcome::WORK_DONE, $callOrder);

        $reconciler = new Reconciler(
            workSources: [$sourceA, $sourceB],
            capacityWaitMillis: 5_000,
            interChunkDelayMicros: 0,
            sleepFn: function (int $micros) use (&$sleeps): void {
                $sleeps[] = $micros;
            },
        );
        $reconciler->tick();

        self::assertSame(['A'], $callOrder, 'CAPACITY_WAIT must short-circuit; B must NOT be ticked.');
        self::assertSame([5_000_000], $sleeps, 'CAPACITY_WAIT must trigger capacityWaitMillis * 1000 micros sleep.');
    }

    public function testInterChunkDelayFiresAfterEachWorkDone(): void
    {
        $callOrder = [];
        $sleeps = [];
        $sourceA = $this->recordingSource('A', TickOutcome::WORK_DONE, $callOrder);
        $sourceB = $this->recordingSource('B', TickOutcome::WORK_DONE, $callOrder);

        $reconciler = new Reconciler(
            workSources: [$sourceA, $sourceB],
            capacityWaitMillis: 1,
            interChunkDelayMicros: 1_500,
            sleepFn: function (int $micros) use (&$sleeps): void {
                $sleeps[] = $micros;
            },
        );
        $reconciler->tick();

        self::assertSame(['A', 'B'], $callOrder);
        self::assertSame([1_500, 1_500], $sleeps, 'Inter-chunk delay must fire after each WORK_DONE.');
    }

    public function testIdleOutcomesNeverSleep(): void
    {
        $sleeps = [];
        $sourceA = $this->fixedSource(TickOutcome::IDLE);
        $sourceB = $this->fixedSource(TickOutcome::IDLE);

        $reconciler = new Reconciler(
            workSources: [$sourceA, $sourceB],
            capacityWaitMillis: 9_999,
            interChunkDelayMicros: 9_999,
            sleepFn: function (int $micros) use (&$sleeps): void {
                $sleeps[] = $micros;
            },
        );
        $reconciler->tick();

        self::assertSame([], $sleeps, 'IDLE outcomes must not consume any inter-chunk delay.');
    }

    public function testZeroInterChunkDelaySkipsSleep(): void
    {
        $sleeps = [];
        $sourceA = $this->fixedSource(TickOutcome::WORK_DONE);

        $reconciler = new Reconciler(
            workSources: [$sourceA],
            capacityWaitMillis: 1,
            interChunkDelayMicros: 0,
            sleepFn: function (int $micros) use (&$sleeps): void {
                $sleeps[] = $micros;
            },
        );
        $reconciler->tick();

        self::assertSame([], $sleeps);
    }

    /**
     * @param list<string> $callOrder Captured by reference for assertion.
     */
    private function recordingSource(string $name, TickOutcome $outcome, array &$callOrder): ReconcilerWorkSource
    {
        return new class ($name, $outcome, $callOrder) implements ReconcilerWorkSource {
            /** @param list<string> $callOrder */
            public function __construct(
                private readonly string $name,
                private readonly TickOutcome $outcome,
                private array &$callOrder,
            ) {
            }

            public function tickOne(string $chunkCorrelationId): TickOutcome
            {
                $this->callOrder[] = $this->name;
                return $this->outcome;
            }
        };
    }

    private function fixedSource(TickOutcome $outcome): ReconcilerWorkSource
    {
        return new class ($outcome) implements ReconcilerWorkSource {
            public function __construct(private readonly TickOutcome $outcome)
            {
            }
            public function tickOne(string $chunkCorrelationId): TickOutcome
            {
                return $this->outcome;
            }
        };
    }
}
