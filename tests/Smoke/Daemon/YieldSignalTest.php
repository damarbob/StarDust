<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Daemon;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use StarDust\Daemon\CompositeYield;
use StarDust\Daemon\DeadlineYield;
use StarDust\Daemon\ShutdownSignal;
use StarDust\Daemon\ShutdownYield;

/**
 * ADR 0050 cooperative-yield signals.
 *
 * Deliberately DB-free — all three implementations are pure once given
 * a clock reading or a `ShutdownSignal` verdict, same posture as
 * `TickBudgetTest`. The one production consumer,
 * {@see \StarDust\Chronicler\ExportJobProcessor::process()}, is
 * covered against a real database in
 * `tests/Smoke/Chronicler/ChroniclerYieldTest`.
 */
final class YieldSignalTest extends TestCase
{
    public function testDeadlineYieldReportsNullBeforeTheDeadline(): void
    {
        $yield = new DeadlineYield($this->fixedClock(1_000), deadlineTimestamp: 2_000);
        self::assertNull($yield->yieldCause());
    }

    public function testDeadlineYieldReportsBudgetAtTheDeadline(): void
    {
        $yield = new DeadlineYield($this->fixedClock(2_000), deadlineTimestamp: 2_000);
        self::assertSame('budget', $yield->yieldCause());
    }

    public function testDeadlineYieldReportsBudgetPastTheDeadline(): void
    {
        $yield = new DeadlineYield($this->fixedClock(3_000), deadlineTimestamp: 2_000);
        self::assertSame('budget', $yield->yieldCause());
    }

    public function testShutdownYieldMirrorsTheWrappedSignal(): void
    {
        $notRequested = new ShutdownYield($this->shutdownSignal(false));
        self::assertNull($notRequested->yieldCause());

        $requested = new ShutdownYield($this->shutdownSignal(true));
        self::assertSame('shutdown', $requested->yieldCause());
    }

    public function testCompositeYieldReturnsNullWhenEverySignalIsNull(): void
    {
        $composite = new CompositeYield(
            new ShutdownYield($this->shutdownSignal(false)),
            new DeadlineYield($this->fixedClock(1_000), deadlineTimestamp: 2_000),
        );
        self::assertNull($composite->yieldCause());
    }

    public function testCompositeYieldShortCircuitsOnTheFirstNonNullCause(): void
    {
        $composite = new CompositeYield(
            new DeadlineYield($this->fixedClock(2_000), deadlineTimestamp: 2_000), // 'budget'
            new ShutdownYield($this->shutdownSignal(true)),                        // 'shutdown' — never reached
        );
        self::assertSame('budget', $composite->yieldCause());
    }

    public function testCompositeYieldFallsThroughToALaterSignal(): void
    {
        $composite = new CompositeYield(
            new DeadlineYield($this->fixedClock(1_000), deadlineTimestamp: 2_000), // null
            new ShutdownYield($this->shutdownSignal(true)),                        // 'shutdown'
        );
        self::assertSame('shutdown', $composite->yieldCause());
    }

    public function testCompositeYieldWithNoSignalsReturnsNull(): void
    {
        $composite = new CompositeYield();
        self::assertNull($composite->yieldCause());
    }

    private function fixedClock(int $timestamp): ClockInterface
    {
        return new class($timestamp) implements ClockInterface {
            public function __construct(private readonly int $timestamp)
            {
            }

            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('@' . $this->timestamp);
            }
        };
    }

    private function shutdownSignal(bool $requested): ShutdownSignal
    {
        return new class($requested) implements ShutdownSignal {
            public function __construct(private readonly bool $requested)
            {
            }

            public function isRequested(): bool
            {
                return $this->requested;
            }
        };
    }
}
