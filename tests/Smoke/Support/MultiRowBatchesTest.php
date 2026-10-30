<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Support;

use PHPUnit\Framework\TestCase;
use StarDust\Support\MultiRowBatches;

/**
 * Pure-PHP guard for the one definition of how a chunk is cut into
 * multi-row statements. No database. The chunk writer and the retype
 * backfill reach it through their own smoke tests as well, so this file
 * pins the arithmetic itself.
 */
final class MultiRowBatchesTest extends TestCase
{
    private const OVERHEAD = 1024;

    public function testNoRowsMakeNoRanges(): void
    {
        self::assertSame([], MultiRowBatches::ranges([], 3, 60000, self::unusedBudget()));
    }

    public function testASingleRowIsOneRangeAndNeverNeedsThePacketSize(): void
    {
        // The callable throws if it is called: a one-row chunk must not
        // cost a session read just to learn a limit it cannot exceed.
        self::assertSame([[0, 1]], MultiRowBatches::ranges([999999], 3, 60000, self::unusedBudget()));
    }

    public function testRowsAreCutByThePlaceholderBudget(): void
    {
        // Six placeholders at three per row is two rows a statement.
        $ranges = MultiRowBatches::ranges(array_fill(0, 7, 10), 3, 6, static fn (): int => 1 << 20);

        self::assertSame([[0, 2], [2, 2], [4, 2], [6, 1]], $ranges);
    }

    public function testRowsAreCutByTheByteBudget(): void
    {
        // 250 usable bytes after the fixed allowance, 100 a row: two rows fit.
        $ranges = MultiRowBatches::ranges(array_fill(0, 5, 100), 3, 60000, static fn (): int => self::OVERHEAD + 250);

        self::assertSame([[0, 2], [2, 2], [4, 1]], $ranges);
    }

    public function testARowOverTheByteBudgetStillGetsARangeOfItsOwn(): void
    {
        $ranges = MultiRowBatches::ranges([10, 5000, 10], 3, 60000, static fn (): int => self::OVERHEAD + 100);

        self::assertSame([[0, 1], [1, 1], [2, 1]], $ranges);
    }

    public function testAPlaceholderBudgetSmallerThanOneRowStillMakesProgress(): void
    {
        $ranges = MultiRowBatches::ranges(array_fill(0, 3, 10), 5, 1, static fn (): int => 1 << 20);

        self::assertSame([[0, 1], [1, 1], [2, 1]], $ranges);
    }

    public function testRangesCoverEveryRowOnceInOrder(): void
    {
        $costs = [];
        for ($i = 0; $i < 53; $i++) {
            $costs[] = 10 + ($i * 37) % 90;
        }

        $ranges = MultiRowBatches::ranges($costs, 4, 40, static fn (): int => self::OVERHEAD + 300);

        $next = 0;
        foreach ($ranges as [$offset, $length]) {
            self::assertSame($next, $offset, 'A range must start where the previous one ended.');
            self::assertGreaterThanOrEqual(1, $length);
            self::assertLessThanOrEqual(10, $length, '40 placeholders at 4 per row is 10 rows at most.');
            $next += $length;
        }
        self::assertSame(53, $next);
    }

    public function testCostOfCountsStringsTwiceTheirLengthAndEverythingElseAsAFixedSize(): void
    {
        // 4 for the tuple, 24 per non-string, 2 * length + 3 per string.
        self::assertSame(4 + 24 + 24 + 24, MultiRowBatches::costOf([0, 0, 42]));
        self::assertSame(4 + 24 + 24 + (2 * 3 + 3), MultiRowBatches::costOf([0, 0, 'abc']));
        self::assertSame(4 + 24 + 24 + 24, MultiRowBatches::costOf([0, 0, null]));
    }

    /** @return callable():int */
    private static function unusedBudget(): callable
    {
        return static function (): int {
            throw new \LogicException('The byte budget must not be read here.');
        };
    }
}
