<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Watcher;

use PHPUnit\Framework\TestCase;
use StarDust\Page\PageProvisioner;
use StarDust\Watcher\SpreadSample;

/**
 * The ADR 0031 `theoretical_min_pages` arithmetic, in isolation.
 *
 * Intentionally DB-free — the formula is pure policy, same posture as
 * `ProvisioningPlannerTest` and `RetypeCoercionMatrixTest`. It gets its
 * own test because ADR 0033's compaction planner reuses it to choose a
 * target page set, so a regression here would silently produce
 * compactions that do not compact.
 */
final class SpreadFormulaTest extends TestCase
{
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

        self::assertSame(1, SpreadSample::theoreticalMinPages($counts));
    }

    /** ADR 0031's own worked example: 30 string + 5 int ⇒ 2. */
    public function testAdrWorkedExample(): void
    {
        self::assertSame(2, SpreadSample::theoreticalMinPages(['str' => 30, 'int' => 5]));
    }

    /**
     * @dataProvider familyCeilingCases
     * @param array<string, int> $counts
     */
    public function testFamilyCeilingBoundaries(array $counts, int $expected): void
    {
        self::assertSame($expected, SpreadSample::theoreticalMinPages($counts));
    }

    /** @return iterable<string, array{array<string, int>, int}> */
    public static function familyCeilingCases(): iterable
    {
        yield 'nothing at all'          => [[], 0];
        yield 'one string'              => [['str' => 1], 1];
        yield 'str exactly at ceiling'  => [['str' => 25], 1];
        yield 'str one over'            => [['str' => 26], 2];
        yield 'str two pages exactly'   => [['str' => 50], 2];
        yield 'int exactly at ceiling'  => [['int' => 15], 1];
        yield 'int one over'            => [['int' => 16], 2];
        yield 'num exactly at ceiling'  => [['num' => 10], 1];
        yield 'num one over'            => [['num' => 11], 2];
        yield 'dt exactly at ceiling'   => [['dt' => 10], 1];
        yield 'dt one over'             => [['dt' => 11], 2];
        yield 'most-constrained wins'   => [['str' => 1, 'dt' => 21], 3];
        yield 'zero counts are ignored' => [['str' => 5, 'int' => 0], 1];
    }

    /**
     * Capacities are derived from the page DDL, not restated. If someone
     * changes a family's per-page column count, the formula must follow
     * automatically or it will misreport excess forever.
     */
    public function testCapacitiesTrackThePageLayout(): void
    {
        self::assertSame(PageProvisioner::STRING_SLOTS, SpreadSample::capacityFor('str'));
        self::assertSame(PageProvisioner::INT_SLOTS, SpreadSample::capacityFor('int'));
        self::assertSame(PageProvisioner::NUMERIC_SLOTS, SpreadSample::capacityFor('num'));
        self::assertSame(PageProvisioner::DATETIME_SLOTS, SpreadSample::capacityFor('dt'));
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
     * A model at its family-ceiling floor reports zero excess even though
     * it occupies several pages — this is the distinction the whole
     * metric exists to draw, and the reason a bare `pages_occupied`
     * threshold was rejected.
     */
    public function testFamilyCeilingBoundModelHasNoExcess(): void
    {
        $minPages = SpreadSample::theoreticalMinPages(['str' => 60]);
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
}
