<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Retype;

use PHPUnit\Framework\TestCase;
use StarDust\Retype\CoercionOutcome;
use StarDust\Retype\RetypeCoercionEngine;

/**
 * Pure-PHP guard for the ADR 0024 coercion matrix. No database, no
 * fixtures — exercises every cell of {@see RetypeCoercionEngine::attempt()}.
 *
 * Spec references (verbatim from ADR 0024):
 *   - `string → int`: base-10 integer literal; no whitespace, no
 *     decimals, no thousands separators, no leading `+`; signed 64-bit
 *     range; else NULL.
 *   - `string → numeric`: JSON-number grammar (RFC 8259 §6); no
 *     whitespace, no `NaN`/`Infinity`; else NULL.
 *   - `string → datetime`: strict RFC 3339 with explicit UTC offset
 *     (`Z` or `±HH:MM`); naive datetimes → NULL.
 *   - `numeric → int`: only if integer-valued AND in signed 64-bit
 *     range; otherwise NULL.
 *   - `int↔datetime`, `numeric↔datetime`: categorically rejected.
 */
final class RetypeCoercionMatrixTest extends TestCase
{
    // ────────────────────────────────────────────────────────────────
    //  Categorical rejection (ADR 0024 Commitment 2)
    // ────────────────────────────────────────────────────────────────

    /** @return iterable<string, array{string, string}> */
    public static function rejectedPairs(): iterable
    {
        yield 'int → datetime'      => ['int',      'datetime'];
        yield 'numeric → datetime'  => ['numeric',  'datetime'];
        yield 'datetime → int'      => ['datetime', 'int'];
        yield 'datetime → numeric'  => ['datetime', 'numeric'];
    }

    /** @dataProvider rejectedPairs */
    public function testCategoricallyRejectedPairsReportTrue(string $from, string $to): void
    {
        self::assertTrue(RetypeCoercionEngine::isCategoricallyRejected($from, $to));
    }

    public function testCoerciblePairsReportFalse(): void
    {
        foreach (['string', 'int', 'numeric', 'datetime'] as $from) {
            foreach (['string', 'int', 'numeric', 'datetime'] as $to) {
                if (in_array("{$from}->{$to}", [
                    'int->datetime', 'numeric->datetime',
                    'datetime->int', 'datetime->numeric',
                ], true)) {
                    continue;
                }
                self::assertFalse(
                    RetypeCoercionEngine::isCategoricallyRejected($from, $to),
                    "Pair {$from} → {$to} should NOT be categorically rejected."
                );
            }
        }
    }

    /** @dataProvider rejectedPairs */
    public function testAttemptOnRejectedPairReturnsNullCoercedEpochRejected(string $from, string $to): void
    {
        $outcome = RetypeCoercionEngine::attempt('any value', true, $from, $to);
        self::assertTrue($outcome->isNullCoerced());
        self::assertSame('epoch_coercion_rejected', $outcome->reason());
    }

    // ────────────────────────────────────────────────────────────────
    //  NotAttempted: absent key OR JSON null (ADR 0024 Commitment 3)
    // ────────────────────────────────────────────────────────────────

    public function testValueNotPresentReturnsNotAttempted(): void
    {
        $outcome = RetypeCoercionEngine::attempt(null, false, 'string', 'int');
        self::assertTrue($outcome->isNotAttempted());
    }

    public function testJsonNullReturnsNotAttempted(): void
    {
        $outcome = RetypeCoercionEngine::attempt(null, true, 'string', 'int');
        self::assertTrue($outcome->isNotAttempted());
    }

    // ────────────────────────────────────────────────────────────────
    //  string → int
    // ────────────────────────────────────────────────────────────────

    public function testStringToIntAcceptsValidLiterals(): void
    {
        self::assertCoerced(42, RetypeCoercionEngine::attempt('42', true, 'string', 'int'));
        self::assertCoerced(-1, RetypeCoercionEngine::attempt('-1', true, 'string', 'int'));
        self::assertCoerced(0, RetypeCoercionEngine::attempt('0', true, 'string', 'int'));
    }

    public function testStringToIntRejectsMalformed(): void
    {
        self::assertNullCoerced('unparseable', RetypeCoercionEngine::attempt('hello', true, 'string', 'int'));
        self::assertNullCoerced('unparseable', RetypeCoercionEngine::attempt('3.5', true, 'string', 'int'));
        self::assertNullCoerced('unparseable', RetypeCoercionEngine::attempt('+42', true, 'string', 'int'));
        self::assertNullCoerced('unparseable', RetypeCoercionEngine::attempt(' 42', true, 'string', 'int'));
        self::assertNullCoerced('unparseable', RetypeCoercionEngine::attempt('1,234', true, 'string', 'int'));
    }

    /** @return iterable<string, array{string, int}> */
    public static function intLiteralsThatFit(): iterable
    {
        yield 'leading zeros'       => ['007', 7];
        yield 'negative zero'       => ['-0', 0];
        yield 'negative with zeros' => ['-007', -7];
        yield 'zeros only'          => ['000', 0];
        yield 'long run of zeros'   => ['00000000000000000000042', 42];
        yield 'largest'             => ['9223372036854775807', PHP_INT_MAX];
        yield 'largest with zeros'  => ['009223372036854775807', PHP_INT_MAX];
        yield 'smallest'            => ['-9223372036854775808', PHP_INT_MIN];
    }

    /** @dataProvider intLiteralsThatFit */
    public function testStringToIntAcceptsEveryLiteralTheGrammarAllows(string $literal, int $expected): void
    {
        $outcome = RetypeCoercionEngine::attempt($literal, true, 'string', 'int');
        self::assertTrue($outcome->isCoerced());
        self::assertSame($expected, $outcome->value());
    }

    /** @return iterable<string, array{string}> */
    public static function intLiteralsThatOverflow(): iterable
    {
        yield 'one past the largest'   => ['9223372036854775808'];
        yield 'one past the smallest'  => ['-9223372036854775809'];
        yield 'twenty digits'          => ['99999999999999999999'];
    }

    /** @dataProvider intLiteralsThatOverflow */
    public function testStringToIntReportsOverflowAsOutOfRange(string $literal): void
    {
        self::assertNullCoerced('out_of_range', RetypeCoercionEngine::attempt($literal, true, 'string', 'int'));
    }

    public function testNumericStringsInAnIntFieldFollowTheSameGrammar(): void
    {
        $toString = RetypeCoercionEngine::attempt('007', true, 'int', 'string');
        self::assertTrue($toString->isCoerced());
        self::assertSame('7', $toString->value());

        $toNumeric = RetypeCoercionEngine::attempt('-0', true, 'int', 'numeric');
        self::assertTrue($toNumeric->isCoerced());
        self::assertSame(0.0, $toNumeric->value());

        $identity = RetypeCoercionEngine::attempt('007', true, 'int', 'int');
        self::assertTrue($identity->isCoerced());
        self::assertSame(7, $identity->value());
    }

    public function testStringToIntRejectsOutOfRange(): void
    {
        // PHP_INT_MAX = 9223372036854775807 (19 digits) — add one digit
        // to overflow BIGINT.
        $outcome = RetypeCoercionEngine::attempt('92233720368547758070', true, 'string', 'int');
        self::assertNullCoerced('out_of_range', $outcome);
    }

    // ────────────────────────────────────────────────────────────────
    //  string → numeric
    // ────────────────────────────────────────────────────────────────

    public function testStringToNumericAcceptsValidJsonNumbers(): void
    {
        self::assertCoerced(42.0, RetypeCoercionEngine::attempt('42', true, 'string', 'numeric'));
        self::assertCoerced(3.5, RetypeCoercionEngine::attempt('3.5', true, 'string', 'numeric'));
        self::assertCoerced(-0.5, RetypeCoercionEngine::attempt('-0.5', true, 'string', 'numeric'));
        self::assertCoerced(1.5e2, RetypeCoercionEngine::attempt('1.5e2', true, 'string', 'numeric'));
    }

    public function testStringToNumericRejectsMalformed(): void
    {
        self::assertNullCoerced('malformed_number', RetypeCoercionEngine::attempt('hello', true, 'string', 'numeric'));
        self::assertNullCoerced('malformed_number', RetypeCoercionEngine::attempt('NaN', true, 'string', 'numeric'));
        self::assertNullCoerced('malformed_number', RetypeCoercionEngine::attempt('Infinity', true, 'string', 'numeric'));
        self::assertNullCoerced('malformed_number', RetypeCoercionEngine::attempt('+3.5', true, 'string', 'numeric'));
        self::assertNullCoerced('malformed_number', RetypeCoercionEngine::attempt('.5', true, 'string', 'numeric'));
    }

    // ────────────────────────────────────────────────────────────────
    //  string → datetime
    // ────────────────────────────────────────────────────────────────

    public function testStringToDatetimeAcceptsRfc3339WithOffset(): void
    {
        $outcome = RetypeCoercionEngine::attempt('2026-01-15T08:00:00Z', true, 'string', 'datetime');
        self::assertTrue($outcome->isCoerced());
        self::assertSame('2026-01-15 08:00:00', $outcome->value());

        $outcome = RetypeCoercionEngine::attempt('2026-01-15T08:00:00+05:00', true, 'string', 'datetime');
        self::assertTrue($outcome->isCoerced());
        self::assertSame('2026-01-15 03:00:00', $outcome->value()); // normalised to UTC
    }

    public function testStringToDatetimeRejectsNaive(): void
    {
        self::assertNullCoerced('malformed_datetime', RetypeCoercionEngine::attempt('2026-01-15 08:00:00', true, 'string', 'datetime'));
        self::assertNullCoerced('malformed_datetime', RetypeCoercionEngine::attempt('2026-01-15', true, 'string', 'datetime'));
        self::assertNullCoerced('malformed_datetime', RetypeCoercionEngine::attempt('not-a-date', true, 'string', 'datetime'));
    }

    /** @return iterable<string, array{string}> */
    public static function impossibleCalendarValues(): iterable
    {
        yield 'february 31'            => ['2026-02-31T00:00:00Z'];
        yield 'february 29, not leap'  => ['2026-02-29T12:00:00Z'];
        yield 'april 31'               => ['2026-04-31T00:00:00Z'];
        yield 'february 31 with offset' => ['2026-02-31T00:00:00+05:00'];
        yield 'hour 24'                => ['2026-01-01T24:00:00Z'];
        yield 'second 60'              => ['2026-01-01T00:00:60Z'];
        yield 'month 13'               => ['2026-13-01T00:00:00Z'];
        yield 'day 00'                 => ['2026-01-00T00:00:00Z'];
    }

    /** @dataProvider impossibleCalendarValues */
    public function testStringToDatetimeRejectsImpossibleCalendarValues(string $value): void
    {
        self::assertNullCoerced(
            'malformed_datetime',
            RetypeCoercionEngine::attempt($value, true, 'string', 'datetime')
        );
    }

    public function testStringToDatetimeKeepsRealBoundaryValues(): void
    {
        $leapDay = RetypeCoercionEngine::attempt('2024-02-29T12:00:00Z', true, 'string', 'datetime');
        self::assertCoerced('2024-02-29 12:00:00', $leapDay);

        $yearEnd = RetypeCoercionEngine::attempt('2026-12-31T23:59:59-05:00', true, 'string', 'datetime');
        self::assertCoerced('2027-01-01 04:59:59', $yearEnd);

        $fraction = RetypeCoercionEngine::attempt('2026-01-15T08:00:00.123456Z', true, 'string', 'datetime');
        self::assertCoerced('2026-01-15 08:00:00', $fraction);
    }

    /** @return iterable<string, array{string}> */
    public static function storedDatetimesThatAreNotReadBack(): iterable
    {
        yield 'date only'                => ['2026-01-15'];
        yield 'day first with slashes'   => ['12/06/2026'];
        yield 'relative word'            => ['tomorrow'];
        yield 'offset without a colon'   => ['2026-01-15 08:00:00+0500'];
        yield 'impossible date'          => ['2026-02-31 08:00:00'];
        yield 'impossible date, offset'  => ['2026-02-31T08:00:00Z'];
        yield 'hour 24'                  => ['2026-01-01 24:00:00'];
    }

    /** @dataProvider storedDatetimesThatAreNotReadBack */
    public function testDatetimeSameTypeBackfillReadsOnlyTheShapesAWriteAccepts(string $value): void
    {
        self::assertNullCoerced(
            'malformed_datetime',
            RetypeCoercionEngine::attempt($value, true, 'datetime', 'datetime')
        );
    }

    /** @dataProvider storedDatetimesThatAreNotReadBack */
    public function testDatetimeToStringReadsOnlyTheShapesAWriteAccepts(string $value): void
    {
        self::assertNullCoerced(
            'malformed_datetime',
            RetypeCoercionEngine::attempt($value, true, 'datetime', 'string')
        );
    }

    public function testStoredDatetimesInTheAcceptedShapesAreStillRead(): void
    {
        $naive = RetypeCoercionEngine::attempt('2026-01-15T08:00:00', true, 'datetime', 'datetime');
        self::assertTrue($naive->isCoerced());
        self::assertSame('2026-01-15 08:00:00', $naive->value());

        $offset = RetypeCoercionEngine::attempt('2026-01-15T08:00:00+05:00', true, 'datetime', 'datetime');
        self::assertTrue($offset->isCoerced());
        self::assertSame('2026-01-15 03:00:00', $offset->value());

        $text = RetypeCoercionEngine::attempt('2026-01-15 08:00:00', true, 'datetime', 'string');
        self::assertTrue($text->isCoerced());
        self::assertSame('2026-01-15T08:00:00.000000Z', $text->value());
    }

    // ────────────────────────────────────────────────────────────────
    //  int → string / int → numeric
    // ────────────────────────────────────────────────────────────────

    public function testIntToString(): void
    {
        self::assertCoerced('42', RetypeCoercionEngine::attempt(42, true, 'int', 'string'));
        self::assertCoerced('-1', RetypeCoercionEngine::attempt(-1, true, 'int', 'string'));
        self::assertCoerced('0', RetypeCoercionEngine::attempt(0, true, 'int', 'string'));
    }

    public function testIntToNumeric(): void
    {
        self::assertCoerced(42.0, RetypeCoercionEngine::attempt(42, true, 'int', 'numeric'));
    }

    // ────────────────────────────────────────────────────────────────
    //  numeric → string / numeric → int
    // ────────────────────────────────────────────────────────────────

    public function testNumericToStringCanonicalises(): void
    {
        self::assertCoerced('0', RetypeCoercionEngine::attempt(0.0, true, 'numeric', 'string'));
        self::assertCoerced('0', RetypeCoercionEngine::attempt(-0.0, true, 'numeric', 'string'));
        self::assertCoerced('42', RetypeCoercionEngine::attempt(42.0, true, 'numeric', 'string'));
        self::assertCoerced('1.5', RetypeCoercionEngine::attempt(1.5, true, 'numeric', 'string'));
    }

    /** @return iterable<string, array{float, string}> */
    public static function numericToStringExact(): iterable
    {
        yield 'tiny'                 => [1.0E-20, '0.00000000000000000001'];
        yield 'smallest denormal'    => [5.0E-324, '0.' . str_repeat('0', 323) . '5'];
        yield 'negative small'       => [-2.5E-7, '-0.00000025'];
        yield 'sum of two tenths'    => [0.1 + 0.2, '0.30000000000000004'];
        yield 'seventeen digits'     => [3.141592653589793, '3.141592653589793'];
        yield 'plain fraction'       => [1234.5, '1234.5'];
        yield 'large integer valued' => [1.0E+15, '1000000000000000'];
        yield 'large with exponent'  => [1.5E+20, '150000000000000000000'];
    }

    /** @dataProvider numericToStringExact */
    public function testNumericToStringKeepsEveryDigitAndNeverUsesAnExponent(float $value, string $expected): void
    {
        $outcome = RetypeCoercionEngine::attempt($value, true, 'numeric', 'string');
        self::assertTrue($outcome->isCoerced());
        self::assertSame($expected, $outcome->value());
        self::assertSame($value, (float) $outcome->value(), 'The text must parse back to the same double.');
    }

    public function testNumericToIntAcceptsIntegerValuedFloats(): void
    {
        self::assertCoerced(42, RetypeCoercionEngine::attempt(42.0, true, 'numeric', 'int'));
    }

    public function testNumericToIntRejectsNonIntegerValuedFloats(): void
    {
        self::assertNullCoerced('non_integer', RetypeCoercionEngine::attempt(3.5, true, 'numeric', 'int'));
    }

    // ────────────────────────────────────────────────────────────────
    //  Identity cells (filterability promotion path)
    // ────────────────────────────────────────────────────────────────

    public function testIdentityStringPassesThrough(): void
    {
        self::assertCoerced('hello', RetypeCoercionEngine::attempt('hello', true, 'string', 'string'));
    }

    public function testIdentityIntPassesThrough(): void
    {
        self::assertCoerced(42, RetypeCoercionEngine::attempt(42, true, 'int', 'int'));
    }

    public function testIdentityNumericPassesThrough(): void
    {
        self::assertCoerced(3.5, RetypeCoercionEngine::attempt(3.5, true, 'numeric', 'numeric'));
    }

    public function testIdentityDatetimeReformatsForSlot(): void
    {
        // Naive datetime is treated as UTC (matches the slot column convention).
        $outcome = RetypeCoercionEngine::attempt('2026-01-15 08:00:00', true, 'datetime', 'datetime');
        self::assertCoerced('2026-01-15 08:00:00', $outcome);
    }

    // ────────────────────────────────────────────────────────────────
    //  Helpers
    // ────────────────────────────────────────────────────────────────

    private static function assertCoerced(mixed $expected, CoercionOutcome $outcome): void
    {
        self::assertTrue(
            $outcome->isCoerced(),
            'Expected Coerced; got '
                . ($outcome->isNullCoerced() ? 'NullCoerced(' . $outcome->reason() . ')' : 'NotAttempted')
        );
        self::assertEquals($expected, $outcome->value());
    }

    private static function assertNullCoerced(string $expectedReason, CoercionOutcome $outcome): void
    {
        self::assertTrue(
            $outcome->isNullCoerced(),
            'Expected NullCoerced; got '
                . ($outcome->isCoerced()
                    ? 'Coerced(' . var_export($outcome->value(), true) . ')'
                    : 'NotAttempted')
        );
        self::assertSame($expectedReason, $outcome->reason());
    }
}
