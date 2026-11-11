<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Retype;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use StarDust\Exception\UncoercibleSlotValueException;
use StarDust\Filter\Ast\FieldRef;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Filter\QueryFilterValidationException;
use StarDust\Read\FieldDescriptor;
use StarDust\Retype\RetypeCoercionEngine;
use StarDust\Search\PreFlight\ValueTypeValidator;
use StarDust\Write\LiveSlotEntry;
use StarDust\Write\LiveSlotMap;
use StarDust\Write\PayloadSplitter;

/**
 * Pure-PHP guard for the rule that a retype admits only what a write and a
 * filter bound admit. No database.
 *
 * The same text goes to three places: the retype engine's `string → datetime`
 * cell, the write path's datetime coercion, and the filter check. For a text
 * with an explicit offset all three must agree, and where the write path and
 * the engine both accept it they must name the same instant. A text without
 * an offset is the one deliberate difference, covered by its own test.
 *
 * The fraction width is one of the things they agree on, at most six digits.
 */
final class DatetimeSpellingAgreementTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function offsetTexts(): iterable
    {
        yield 'utc'                       => ['2026-01-15T08:00:00Z', true];
        yield 'positive offset'           => ['2026-01-15T08:00:00+05:00', true];
        yield 'negative offset'           => ['2026-12-31T23:59:59-05:00', true];
        yield 'one fractional digit'      => ['2026-01-15T08:00:00.5Z', true];
        yield 'six fractional digits'     => ['2026-01-15T08:00:00.123456Z', true];
        yield 'leap day'                  => ['2024-02-29T12:00:00Z', true];
        yield 'seven fractional digits'   => ['2026-01-15T08:00:00.1234567Z', false];
        yield 'nine fractional digits'    => ['2026-01-15T08:00:00.123456789+05:00', false];
        yield 'space separator'           => ['2026-01-15 08:00:00Z', false];
        yield 'space with offset'         => ['2026-01-15 08:00:00+05:00', false];
        yield 'lowercase t'               => ['2026-01-15t08:00:00Z', false];
        yield 'lowercase z'               => ['2026-01-15T08:00:00z', false];
        yield 'offset without a colon'    => ['2026-01-15T08:00:00+0500', false];
        yield 'february 31'               => ['2026-02-31T00:00:00Z', false];
        yield 'february 29, not leap'     => ['2026-02-29T00:00:00Z', false];
        yield 'hour 24'                   => ['2026-01-01T24:00:00Z', false];
        yield 'second 60'                 => ['2026-01-01T00:00:60Z', false];
        yield 'month 13'                  => ['2026-13-01T00:00:00Z', false];
        yield 'date only'                 => ['2026-01-15', false];
        yield 'day first with slashes'    => ['12/06/2026', false];
        yield 'relative word'             => ['tomorrow', false];
        yield 'empty'                     => ['', false];
    }

    /** @dataProvider offsetTexts */
    public function testTheRetypeTheWriteAndTheFilterAgreeOnATextWithAnOffset(string $text, bool $accepted): void
    {
        self::assertSame($accepted, self::engineAccepts($text), 'retype engine');
        self::assertSame($accepted, self::writeAccepts($text), 'write path');
        self::assertSame($accepted, self::filterAccepts($text), 'filter check');
    }

    /** @return iterable<string, array{string}> */
    public static function acceptedOffsetTexts(): iterable
    {
        foreach (self::offsetTexts() as $name => [$text, $accepted]) {
            if ($accepted) {
                yield $name => [$text];
            }
        }
    }

    /** @dataProvider acceptedOffsetTexts */
    public function testTheRetypeAndTheWriteNameTheSameInstant(string $text): void
    {
        $outcome = RetypeCoercionEngine::attempt($text, true, 'string', 'datetime');
        self::assertTrue($outcome->isCoerced());
        self::assertSame(self::writeSlotValue($text), $outcome->value());
    }

    /** @return iterable<string, array{string}> */
    public static function naiveTexts(): iterable
    {
        yield 'space'     => ['2026-01-15 08:00:00'];
        yield 'letter t'  => ['2026-01-15T08:00:00'];
    }

    /**
     * A write reads a text without an offset as UTC. A filter bound and a
     * `string → datetime` retype refuse it, because a stored datetime is
     * always UTC and the engine does not guess which zone the caller meant.
     *
     * @dataProvider naiveTexts
     */
    public function testATextWithoutAnOffsetIsReadByAWriteAndRefusedByTheOthers(string $text): void
    {
        self::assertTrue(self::writeAccepts($text), 'write path');
        self::assertFalse(self::filterAccepts($text), 'filter check');
        self::assertFalse(self::engineAccepts($text), 'retype engine');
    }

    private static function engineAccepts(string $text): bool
    {
        return RetypeCoercionEngine::attempt($text, true, 'string', 'datetime')->isCoerced();
    }

    private static function writeAccepts(string $text): bool
    {
        try {
            self::writeSlotValue($text);

            return true;
        } catch (UncoercibleSlotValueException) {
            return false;
        }
    }

    private static function writeSlotValue(string $text): mixed
    {
        $map = new LiveSlotMap(
            ['when' => new LiveSlotEntry(1, 'when', 'datetime', 'i_dt_01', 1, 'ready')],
            ['when' => true],
        );

        return PayloadSplitter::split($map, ['when' => $text])->slotWrites[1]['i_dt_01'];
    }

    private static function filterAccepts(string $text): bool
    {
        $descriptor = new FieldDescriptor(1, 'when', 'datetime', true, 'i_dt_01', 'ready', 1);
        $leaf = LeafNode::local('when', 'eq', $text)->withResolvedField(
            FieldRef::local('when')->withResolved(1, $descriptor)
        );

        try {
            (new ValueTypeValidator(new NullLogger()))->validate($leaf, 1, 'test-correlation');

            return true;
        } catch (QueryFilterValidationException) {
            return false;
        }
    }
}
