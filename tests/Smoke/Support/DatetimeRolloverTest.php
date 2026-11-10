<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Support;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use StarDust\Support\DatetimeRollover;

/**
 * Pure-PHP guard for the one definition of "PHP moved this date forward".
 * No database. The write path, the filter check and the retype backfill
 * each pin their own use of it as well.
 */
final class DatetimeRolloverTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function impossibleValues(): iterable
    {
        yield 'february 31'              => ['2026-02-31T00:00:00Z'];
        yield 'february 29, not leap'    => ['2026-02-29T12:00:00Z'];
        yield 'april 31'                 => ['2026-04-31T00:00:00Z'];
        yield 'february 31 with offset'  => ['2026-02-31T00:00:00+05:00'];
        yield 'february 31, naive'       => ['2026-02-31 00:00:00'];
        yield 'hour 24'                  => ['2026-01-01T24:00:00Z'];
        yield 'second 60'                => ['2026-01-01T00:00:60Z'];
        yield 'day 00'                   => ['2026-01-00T00:00:00Z'];
    }

    /** @dataProvider impossibleValues */
    public function testItDetectsAValuePhpMovedForward(string $text): void
    {
        $parsed = new DateTimeImmutable($text, new DateTimeZone('UTC'));

        self::assertTrue(DatetimeRollover::occurred($text, $parsed));
    }

    /** @return iterable<string, array{string}> */
    public static function realValues(): iterable
    {
        yield 'leap day'                => ['2024-02-29T12:00:00Z'];
        yield 'last second of the year' => ['2026-12-31T23:59:59Z'];
        yield 'first second of the year' => ['2026-01-01T00:00:00Z'];
        yield 'positive offset'         => ['2026-01-15T08:00:00+05:00'];
        yield 'negative offset'         => ['2026-12-31T23:59:59-05:00'];
        yield 'fraction'                => ['2026-01-15T08:00:00.123456Z'];
        yield 'space separator'         => ['2026-01-15 08:00:00'];
        yield 'lowercase t'             => ['2026-01-15t08:00:00Z'];
    }

    /** @dataProvider realValues */
    public function testItLeavesARealValueAlone(string $text): void
    {
        $parsed = new DateTimeImmutable($text, new DateTimeZone('UTC'));

        self::assertFalse(DatetimeRollover::occurred($text, $parsed));
    }
}
