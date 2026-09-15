<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Daemon;

use PHPUnit\Framework\TestCase;
use StarDust\Daemon\TickBudget;

/**
 * ADR 0048 tick-budget resolution.
 *
 * Deliberately DB-free — {@see TickBudget} is pure once given a
 * `max_execution_time` reading, same posture as
 * `Watcher\ProvisioningPlannerTest`. The clamp matrix is driven through
 * {@see TickBudget::fromCeiling()} rather than `resolve()`, because
 * `resolve()`'s own `set_time_limit(0)` call resets real SAPI state to
 * 0 on success — this process cannot fake a surviving nonzero ceiling
 * by `ini_set()`-ing one first, so the arithmetic has to be testable on
 * its own. `testResolveIsUnclampedUnderTheCliSapi()` below is the one
 * test that exercises `resolve()` itself, confirming empirically (not
 * merely by assumption) that the CLI SAPI this test suite runs under
 * reports `max_execution_time = 0`.
 */
final class TickBudgetTest extends TestCase
{
    public function testNoCeilingLeavesTheConfiguredBudgetUntouched(): void
    {
        $budget = TickBudget::fromCeiling(configuredSeconds: 50, marginSeconds: 5, maxExecutionTime: 0);

        self::assertSame(50, $budget->seconds);
        self::assertFalse($budget->clamped);
    }

    public function testCeilingAboveTheConfiguredBudgetLeavesItUntouched(): void
    {
        // max_execution_time 120, margin 5 -> ceiling 115, well above
        // the configured 50.
        $budget = TickBudget::fromCeiling(configuredSeconds: 50, marginSeconds: 5, maxExecutionTime: 120);

        self::assertSame(50, $budget->seconds);
        self::assertFalse($budget->clamped);
    }

    public function testCeilingExactlyAtTheConfiguredBudgetIsNotClamped(): void
    {
        // max_execution_time 55, margin 5 -> ceiling 50, equal to the
        // configured budget. >= is not clamped.
        $budget = TickBudget::fromCeiling(configuredSeconds: 50, marginSeconds: 5, maxExecutionTime: 55);

        self::assertSame(50, $budget->seconds);
        self::assertFalse($budget->clamped);
    }

    public function testCeilingBelowTheConfiguredBudgetClamps(): void
    {
        // max_execution_time 30, margin 5 -> ceiling 25, below 50.
        $budget = TickBudget::fromCeiling(configuredSeconds: 50, marginSeconds: 5, maxExecutionTime: 30);

        self::assertSame(25, $budget->seconds);
        self::assertTrue($budget->clamped);
    }

    public function testCeilingAtOrUnderTheMarginClampsToExactlyZero(): void
    {
        // max_execution_time 5, margin 5 -> ceiling 0. Not an error —
        // CombinedTick's loop always completes one round before it
        // checks the budget, so 0 means "one round, then stop".
        $budget = TickBudget::fromCeiling(configuredSeconds: 50, marginSeconds: 5, maxExecutionTime: 5);

        self::assertSame(0, $budget->seconds);
        self::assertTrue($budget->clamped);
    }

    public function testCeilingBelowTheMarginFloorsAtZeroRatherThanGoingNegative(): void
    {
        // max_execution_time 3, margin 5 -> ceiling -2, floored at 0.
        $budget = TickBudget::fromCeiling(configuredSeconds: 50, marginSeconds: 5, maxExecutionTime: 3);

        self::assertSame(0, $budget->seconds);
        self::assertTrue($budget->clamped);
    }

    public function testResolveIsUnclampedUnderTheCliSapi(): void
    {
        // Empirical, not assumed: PHPUnit runs under the CLI SAPI,
        // whose default max_execution_time is already 0, and
        // set_time_limit(0) is expected to succeed there — both probed
        // by hand (`php -r 'var_dump(set_time_limit(0));
        // var_dump(ini_get("max_execution_time"));'`) before this test
        // was written. If that ever stops holding in CI's environment,
        // this is the test that will say so.
        $budget = TickBudget::resolve(configuredSeconds: 50, marginSeconds: 5);

        self::assertSame(50, $budget->seconds);
        self::assertFalse($budget->clamped);
    }
}
