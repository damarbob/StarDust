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
