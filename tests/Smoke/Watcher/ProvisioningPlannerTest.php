<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Watcher;

use PHPUnit\Framework\TestCase;
use StarDust\Watcher\CapacitySnapshot;
use StarDust\Watcher\FlatIndexHeadroom;
use StarDust\Watcher\IndexHeadroomPolicy;
use StarDust\Watcher\PendingDemand;
use StarDust\Watcher\ProvisioningPlan;
use StarDust\Watcher\ProvisioningPlanner;

/**
 * The provisioning policy matrix.
 *
 * Deliberately DB-free — the planner is pure, so every case including
 * the pathological ones can be scripted directly. Same posture as
 * `RetypeCoercionMatrixTest`.
 */
final class ProvisioningPlannerTest extends TestCase
{
    private const THRESHOLD = 0.20;

    /**
     * @param array<string,int> $indexedFree
     * @param array<string,int> $indexedTotal
     */
    private function snapshot(
        int $totalFree,
        int $totalSlots,
        array $indexedFree = [],
        array $indexedTotal = [],
    ): CapacitySnapshot {
        return new CapacitySnapshot(
            freeByFamily: [],
            totalByFamily: [],
            totalFree: $totalFree,
            totalSlots: $totalSlots,
            pagesInspected: 1,
            indexedFreeByFamily: $indexedFree,
            indexedTotalByFamily: $indexedTotal,
        );
    }

    /** @param array<string,int> $waiters */
    private function plan(
        CapacitySnapshot $snapshot,
        array $waiters = [],
        ?IndexHeadroomPolicy $headroom = null,
    ): ProvisioningPlan {
        return ProvisioningPlanner::plan(
            $snapshot,
            new PendingDemand($waiters),
            self::THRESHOLD,
            $headroom ?? new FlatIndexHeadroom(1),
        );
    }

    public function testNoDemandAndHealthyRatioDoesNotProvision(): void
    {
        $plan = $this->plan($this->snapshot(totalFree: 60, totalSlots: 60));

        self::assertFalse($plan->shouldProvision);
        self::assertSame(ProvisioningPlan::TRIGGER_NONE, $plan->trigger);
        self::assertSame([], $plan->indexedColumns);
    }

    /**
     * Inverted by ADR 0042. This case previously asserted `[]` — a page
     * provisioned by the low-capacity trigger with nobody waiting carried
     * no indexes at all, and was therefore capacity no reservation could
     * ever claim. It now carries headroom in every family.
     *
     * The rename is the record of the decision; the `k = 0` case below
     * preserves the old assertion as the documented opt-out.
     */
    public function testNoDemandAndLowRatioStillIndexesHeadroomOnEveryFamily(): void
    {
        $plan = $this->plan($this->snapshot(totalFree: 5, totalSlots: 120));

        self::assertTrue($plan->shouldProvision);
        self::assertSame(ProvisioningPlan::TRIGGER_LOW_CAPACITY, $plan->trigger);
        self::assertSame(
            ['i_str_01', 'i_int_01', 'i_num_01', 'i_dt_01'],
            $plan->indexedColumns,
            'One column per family at k=1, in declaration order rather than the demand map ksorted order.',
        );
    }

    /**
     * `k = 0` degrades to the pre-0042 index set exactly, which is what
     * makes it a legal opt-out rather than a way to starve a waiter. The
     * assertion is the one the case above used to carry.
     */
    public function testZeroHeadroomRestoresThePre0042IndexSet(): void
    {
        $plan = $this->plan(
            $this->snapshot(totalFree: 5, totalSlots: 120),
            [],
            new FlatIndexHeadroom(0),
        );

        self::assertTrue($plan->shouldProvision);
        self::assertSame([], $plan->indexedColumns);
    }

    /** The starvation-freedom guarantee: a healthy global ratio must not mask it. */
    public function testStarvedFamilyProvisionsEvenWhenRatioIsHealthy(): void
    {
        $plan = $this->plan(
            $this->snapshot(totalFree: 60, totalSlots: 60, indexedFree: ['dt' => 0], indexedTotal: ['dt' => 0]),
            ['dt' => 1],
        );

        self::assertTrue($plan->shouldProvision);
        self::assertSame(ProvisioningPlan::TRIGGER_UNSATISFIABLE_DEMAND, $plan->trigger);
        self::assertSame(['dt'], $plan->starvedFamilies);
        self::assertSame(
            ['i_str_01', 'i_int_01', 'i_num_01', 'i_dt_01'],
            $plan->indexedColumns,
            'The starved family is covered; headroom covers the rest.',
        );
    }

    public function testUnsatisfiableDemandTakesPrecedenceOverLowCapacityInTheReportedTrigger(): void
    {
        $plan = $this->plan(
            $this->snapshot(totalFree: 1, totalSlots: 120, indexedFree: ['str' => 0], indexedTotal: ['str' => 3]),
            ['str' => 1],
        );

        self::assertSame(ProvisioningPlan::TRIGGER_UNSATISFIABLE_DEMAND, $plan->trigger);
    }

    /** The anti-cascade lock: demand that CAN be served must not provision. */
    public function testSatisfiableDemandWithHealthyRatioDoesNotProvision(): void
    {
        $plan = $this->plan(
            $this->snapshot(totalFree: 60, totalSlots: 60, indexedFree: ['str' => 1], indexedTotal: ['str' => 1]),
            ['str' => 1],
        );

        self::assertFalse($plan->shouldProvision);
        self::assertSame([], $plan->starvedFamilies);
    }

    /**
     * Direct regression test for the cascade a usable-capacity *ratio*
     * would produce: feed the planner the state that exists right after
     * it provisions, and it must be satisfied.
     *
     * A ratio trigger would need seven pages here (0/25 → 1/26 → … →
     * 7/32 before clearing 0.20). The set test clears immediately.
     */
    public function testPlanConvergesAfterOneProvisionForASingleStarvedWaiter(): void
    {
        $before = $this->snapshot(
            totalFree: 60,
            totalSlots: 120,
            indexedFree: ['str' => 0],
            indexedTotal: ['str' => 25],
        );
        self::assertTrue($this->plan($before, ['str' => 1])->shouldProvision);

        // One page later: one indexed str column added, and free.
        $after = $this->snapshot(
            totalFree: 120,
            totalSlots: 180,
            indexedFree: ['str' => 1],
            indexedTotal: ['str' => 26],
        );

        self::assertFalse(
            $this->plan($after, ['str' => 1])->shouldProvision,
            'One page must satisfy one waiter — a ratio trigger would keep provisioning.',
        );
    }

    public function testIndexedColumnsAreCappedAtFamilyCapacity(): void
    {
        $plan = $this->plan(
            $this->snapshot(totalFree: 0, totalSlots: 60, indexedFree: ['str' => 0], indexedTotal: ['str' => 0]),
            ['str' => 300],
        );

        $str = array_values(array_filter(
            $plan->indexedColumns,
            static fn (string $c): bool => str_starts_with($c, 'i_str_'),
        ));

        self::assertCount(25, $str, 'The str family holds 25 columns per page.');
        self::assertSame('i_str_01', $str[0]);
        self::assertSame('i_str_25', $str[24]);
        self::assertCount(28, $plan->indexedColumns, '25 str, plus k=1 of each other family.');
    }

    /**
     * A page provisioned while a field waits on a family must carry an
     * index on at least one of that family's columns — even when the
     * shortfall is zero because the low-capacity trigger fired, and even
     * when headroom is switched off entirely.
     *
     * Driven at `k = 0`, because that is now the only setting where the
     * floor is load-bearing: any positive headroom supplies the column
     * anyway, and the case could not fail. The assertion is unchanged
     * from before ADR 0042 for the same reason.
     */
    public function testDemandedFamilyFloorsAtOneColumnEvenWithZeroHeadroom(): void
    {
        $plan = $this->plan(
            $this->snapshot(totalFree: 1, totalSlots: 120, indexedFree: ['int' => 5], indexedTotal: ['int' => 5]),
            ['int' => 1],
            new FlatIndexHeadroom(0),
        );

        self::assertTrue($plan->shouldProvision);
        self::assertSame(ProvisioningPlan::TRIGGER_LOW_CAPACITY, $plan->trigger);
        self::assertSame(['i_int_01'], $plan->indexedColumns);
    }

    public function testIndexedColumnsCoverEveryFamilyInDeclarationOrder(): void
    {
        $plan = $this->plan(
            $this->snapshot(totalFree: 0, totalSlots: 60),
            ['str' => 2, 'dt' => 1],
        );

        // Was ['i_dt_01', 'i_str_01', 'i_str_02'] — demanded families only,
        // ksorted. This order reaches `filterable_slots` on page_provisioned.
        self::assertSame(
            ['i_str_01', 'i_str_02', 'i_int_01', 'i_num_01', 'i_dt_01'],
            $plan->indexedColumns,
        );
    }

    /** A shortfall wider than the headroom wins: headroom is a floor, not a cap. */
    public function testShortfallWiderThanHeadroomWins(): void
    {
        $plan = $this->plan(
            $this->snapshot(totalFree: 0, totalSlots: 60),
            ['str' => 10],
            new FlatIndexHeadroom(4),
        );

        $str = array_filter(
            $plan->indexedColumns,
            static fn (string $c): bool => str_starts_with($c, 'i_str_'),
        );

        self::assertCount(10, $str);
        self::assertCount(22, $plan->indexedColumns, '10 str, plus k=4 of each other family.');
    }

    /** The shipped default: four of every family, sixteen columns. */
    public function testShippedHeadroomIndexesFourOfEveryFamily(): void
    {
        $plan = $this->plan(
            $this->snapshot(totalFree: 5, totalSlots: 120),
            [],
            new FlatIndexHeadroom(4),
        );

        self::assertCount(16, $plan->indexedColumns);
        self::assertSame(
            ['i_str_01', 'i_str_02', 'i_str_03', 'i_str_04'],
            array_slice($plan->indexedColumns, 0, 4),
        );
    }

    /**
     * The planner does not trust the policy, which is what lets ADR 0042's
     * two open refinements arrive as new implementations without anyone
     * revisiting the starvation-freedom guarantee.
     *
     * The negative case is the one that matters, and it is not theoretical:
     * `array_slice($cols, 0, -3)` returns everything *but* the last three
     * columns, so an unclamped negative return would index most of a family
     * instead of none of it.
     */
    public function testPlannerClampsAHostilePolicyInBothDirections(): void
    {
        $negative = new class implements IndexHeadroomPolicy {
            public function headroomFor(string $family): int
            {
                return -100;
            }
        };

        $enormous = new class implements IndexHeadroomPolicy {
            public function headroomFor(string $family): int
            {
                return 9_999;
            }
        };

        $snapshot = $this->snapshot(totalFree: 5, totalSlots: 120);

        self::assertSame([], $this->plan($snapshot, [], $negative)->indexedColumns);
        self::assertCount(60, $this->plan($snapshot, [], $enormous)->indexedColumns);
    }

    /**
     * With nobody waiting, all three usable figures report the global
     * ones. Reporting the ratio globally but the counts as 0 would emit
     * a self-contradictory log line ("0 free of 0 total, ratio 0.5").
     */
    public function testUsableFiguresEqualTheGlobalOnesWhenDemandIsEmpty(): void
    {
        $plan = $this->plan($this->snapshot(totalFree: 30, totalSlots: 60));

        self::assertSame(0.5, $plan->usableFreeRatio);
        self::assertSame(30, $plan->usableFree);
        self::assertSame(60, $plan->usableTotal);
    }

    public function testUsableRatioIsZeroWhenDemandedFamiliesHaveNoIndexedInventory(): void
    {
        $plan = $this->plan(
            $this->snapshot(totalFree: 60, totalSlots: 60, indexedFree: ['num' => 0], indexedTotal: ['num' => 0]),
            ['num' => 1],
        );

        self::assertSame(0.0, $plan->usableFreeRatio, 'Mirrors globalFreeRatio()\'s zero-on-empty convention.');
        self::assertSame(0, $plan->usableTotal);
    }

    public function testUsableCountsSumOnlyOverDemandedFamilies(): void
    {
        $plan = $this->plan(
            $this->snapshot(
                totalFree: 60,
                totalSlots: 60,
                indexedFree: ['str' => 4, 'dt' => 2],
                indexedTotal: ['str' => 10, 'dt' => 6],
            ),
            ['str' => 1],
        );

        self::assertSame(4, $plan->usableFree, 'dt is not demanded, so it is not usable capacity.');
        self::assertSame(10, $plan->usableTotal);
    }
}
