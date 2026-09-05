<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Watcher;

use PHPUnit\Framework\TestCase;
use StarDust\Page\PageProvisioner;
use StarDust\Watcher\SpreadSample;

/**
 * The `theoretical_min_pages` arithmetic, in isolation.
 *
 * Intentionally DB-free — the formula is pure policy, same posture as
 * `ProvisioningPlannerTest` and `RetypeCoercionMatrixTest`. It gets its
 * own test because ADR 0033's compaction planner reuses it to choose a
 * target page set, so a regression here would silently produce
 * compactions that do not compact — or, as ADR 0044 found, refusals of
 * models that were already compact.
 */
final class SpreadFormulaTest extends TestCase
{
    /**
     * @param  array<string, int>            $capacityEach
     * @return array<int, array<string, int>> `$count` identical pages
     */
    private static function uniformPages(int $count, array $capacityEach): array
    {
        $pages = [];
        for ($i = 1; $i <= $count; $i++) {
            $pages[$i] = $capacityEach;
        }

        return $pages;
    }

    /** The full pre-ADR-0043 page layout, for the cases the ADR states in those terms. */
    private static function legacyShapedPages(int $count): array
    {
        return self::uniformPages($count, [
            'str' => PageProvisioner::STRING_SLOTS,
            'int' => PageProvisioner::INT_SLOTS,
            'num' => PageProvisioner::NUMERIC_SLOTS,
            'dt'  => PageProvisioner::DATETIME_SLOTS,
        ]);
    }

    /**
     * The single most important property: a page provides all four
     * families *simultaneously*, so the minimum is the max over
     * families, never the sum. Summing is the natural-looking error, and
     * it would make every multi-family model look permanently fragmented
     * and un-compactable.
     */
    public function testMinimumIsTheMaxOverFamiliesNotTheSum(): void
    {
        // Each family exactly fills one page's worth on its own.
        $counts = ['str' => 25, 'int' => 15, 'num' => 10, 'dt' => 10];

        self::assertSame(1, SpreadSample::theoreticalMinPages($counts, self::legacyShapedPages(1)));
    }

    /** ADR 0031's own worked example, on the page shape it was written against. */
    public function testAdrWorkedExample(): void
    {
        self::assertSame(
            2,
            SpreadSample::theoreticalMinPages(['str' => 30, 'int' => 5], self::legacyShapedPages(2)),
        );
    }

    /**
     * Uniform pages reduce the formula to the published
     * `ceil(count / capacity)`, whatever that capacity happens to be.
     *
     * @dataProvider uniformCases
     * @param array<string, int> $counts
     * @param array<string, int> $capacityEach
     */
    public function testUniformPagesReduceToTheDivision(
        array $counts,
        array $capacityEach,
        int $pages,
        int $expected,
    ): void {
        self::assertSame(
            $expected,
            SpreadSample::theoreticalMinPages($counts, self::uniformPages($pages, $capacityEach)),
        );
    }

    /** @return iterable<string, array{array<string, int>, array<string, int>, int, int}> */
    public static function uniformCases(): iterable
    {
        $legacy = ['str' => 25, 'int' => 15, 'num' => 10, 'dt' => 10];

        yield 'nothing at all'          => [[], $legacy, 1, 0];
        yield 'one string'              => [['str' => 1], $legacy, 1, 1];
        yield 'str exactly at ceiling'  => [['str' => 25], $legacy, 1, 1];
        yield 'str one over'            => [['str' => 26], $legacy, 2, 2];
        yield 'str two pages exactly'   => [['str' => 50], $legacy, 2, 2];
        yield 'int exactly at ceiling'  => [['int' => 15], $legacy, 1, 1];
        yield 'int one over'            => [['int' => 16], $legacy, 2, 2];
        yield 'num exactly at ceiling'  => [['num' => 10], $legacy, 1, 1];
        yield 'num one over'            => [['num' => 11], $legacy, 2, 2];
        yield 'dt exactly at ceiling'   => [['dt' => 10], $legacy, 1, 1];
        yield 'dt one over'             => [['dt' => 11], $legacy, 2, 2];
        yield 'most-constrained wins'   => [['str' => 1, 'dt' => 21], $legacy, 3, 3];
        yield 'zero counts are ignored' => [['str' => 5, 'int' => 0], $legacy, 1, 1];

        // The ADR 0042/0043 shape: `k` columns of each family per page.
        $headroom = ['str' => 4, 'int' => 4, 'num' => 4, 'dt' => 4];

        yield 'k fields on one k-wide page'     => [['str' => 4], $headroom, 1, 1];
        yield 'k + 1 needs a second page'       => [['str' => 5], $headroom, 2, 2];
        yield '2k + 1 needs a third'            => [['str' => 9], $headroom, 3, 3];
        yield 'families still do not sum'       => [['str' => 4, 'int' => 4], $headroom, 1, 1];
    }

    /**
     * The regression ADR 0044 exists for: five string fields over two
     * four-column pages are at their floor, not one page's worth of
     * excess. Dividing by the *layout* capacity answered 1 here, and it
     * is the answer that made `spread:report` cry wolf and
     * `compactModel()` refuse.
     */
    public function testFloorFollowsRealPageCapacityNotTheLayout(): void
    {
        $pages = self::uniformPages(2, ['str' => 4]);

        self::assertSame(2, SpreadSample::theoreticalMinPages(['str' => 5], $pages));
        self::assertSame(1, SpreadSample::theoreticalMinPages(['str' => 4], $pages));
    }

    /** Heterogeneous pages are packed roomiest-first, per family. */
    public function testRoomiestPagesAreCountedFirst(): void
    {
        $pages = [
            1 => ['str' => 1],
            2 => ['str' => 6],
            3 => ['str' => 2],
        ];

        // 6 alone covers 6; 6 + 2 covers 8; all three cover 9.
        self::assertSame(1, SpreadSample::theoreticalMinPages(['str' => 6], $pages));
        self::assertSame(2, SpreadSample::theoreticalMinPages(['str' => 7], $pages));
        self::assertSame(3, SpreadSample::theoreticalMinPages(['str' => 9], $pages));
    }

    /**
     * Each family picks its own roomiest pages, so the result is a lower
     * bound rather than an exact packing when the families disagree
     * about which page. That is the safe direction — a floor that
     * over-reports would resurrect the false alarm — and it is what
     * keeps `CompactionCapacityException` reachable at all.
     */
    public function testDivergentFamiliesGiveALowerBound(): void
    {
        $pages = [
            1 => ['str' => 2, 'int' => 0],
            2 => ['str' => 0, 'int' => 2],
        ];

        // Each family fits on one page; no single page fits both.
        self::assertSame(1, SpreadSample::theoreticalMinPages(['str' => 2, 'int' => 2], $pages));
    }

    /** Hostable capacity is the model's own slots plus what is free beside them. */
    public function testHostableIsOwnPlusFree(): void
    {
        $hostable = SpreadSample::hostableByPage(
            [1 => ['str' => 3], 2 => ['int' => 1]],
            [1 => ['str' => 2, 'int' => 1], 3 => ['dt' => 4]],
        );

        self::assertSame(['str' => 5, 'int' => 1], $hostable[1]);
        self::assertSame(['int' => 1], $hostable[2]);
        self::assertSame(['dt' => 4], $hostable[3], 'A page with no free slot still hosts its own.');
    }

    public function testFamilyOfParsesSlotColumns(): void
    {
        self::assertSame('str', SpreadSample::familyOf('i_str_01'));
        self::assertSame('int', SpreadSample::familyOf('i_int_15'));
        self::assertSame('num', SpreadSample::familyOf('i_num_10'));
        self::assertSame('dt', SpreadSample::familyOf('i_dt_07'));
        self::assertNull(SpreadSample::familyOf('tenant_id'));
        self::assertNull(SpreadSample::familyOf('i_bogus_01'));
    }

    /** `excess_pages` is derived, so it can never disagree with its inputs. */
    public function testExcessPagesIsPagesMinusMinimum(): void
    {
        $sample = new SpreadSample(
            tenantId: 1,
            modelId: 7,
            pagesOccupied: 4,
            theoreticalMinPages: 1,
            liveSlotCount: 9,
        );

        self::assertSame(3, $sample->excessPages());
    }

    /**
     * A model at its floor reports zero excess even though it occupies
     * several pages — this is the distinction the whole metric exists to
     * draw, and the reason a bare `pages_occupied` threshold was
     * rejected.
     */
    public function testFloorBoundModelHasNoExcess(): void
    {
        $minPages = SpreadSample::theoreticalMinPages(['str' => 60], self::legacyShapedPages(3));
        self::assertSame(3, $minPages);

        $sample = new SpreadSample(
            tenantId: 1,
            modelId: 7,
            pagesOccupied: 3,
            theoreticalMinPages: $minPages,
            liveSlotCount: 60,
        );

        self::assertSame(0, $sample->excessPages(), 'Unavoidable spread must not read as excess.');
    }

    /**
     * A sample built from live slots derives its own candidate set: only
     * pages the model occupies count, matching ADR 0033's restriction
     * that compaction never migrates onto an untouched page. Free
     * capacity on page 9 is unreachable and must not lower the floor.
     */
    public function testFromLiveSlotsIgnoresPagesTheModelDoesNotOccupy(): void
    {
        $sample = SpreadSample::fromLiveSlots(
            tenantId: 1,
            modelId: 7,
            pageIds: [1, 2],
            slotColumns: ['i_str_01', 'i_str_01'],
            freeByPage: [
                1 => ['str' => 0],
                2 => ['str' => 0],
                9 => ['str' => 25],
            ],
        );

        self::assertSame(2, $sample->pagesOccupied);
        self::assertSame(2, $sample->theoreticalMinPages);
        self::assertSame(0, $sample->excessPages());
    }
}
