<?php

declare(strict_types=1);

namespace StarDust\Retype;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Pure implementation of the ADR 0024 type-coercion matrix.
 *
 * The 4×4 declared_type matrix has 16 cells:
 *   - 4 identity diagonals → value passes through (normalised for
 *     `datetime` to the slot's `Y-m-d H:i:s` UTC representation).
 *   - 8 coercible off-diagonals → each implemented as a tight
 *     per-cell parser that returns either `coerced(value)` or
 *     `nullCoerced(reason)` with a closed-taxonomy reason.
 *   - 4 categorically-rejected off-diagonals (`int↔datetime`,
 *     `numeric↔datetime`) → {@see self::isCategoricallyRejected()}.
 *     {@see \StarDust\Retype\RetypeInitiator} consults this up-front
 *     and throws {@see \StarDust\Exception\IncompatibleRetypeException}
 *     before any registry mutation. Defensive: if a stale checkpoint
 *     somehow reaches the engine for a rejected pair, the call
 *     returns `nullCoerced('epoch_coercion_rejected')` rather than
 *     coercing.
 *
 * The matrix applies only to retype-backfill (per ADR 0024
 * scope-declaration). It is NOT a substitute for the Phase 3
 * first-write {@see \StarDust\Write\PayloadSplitter} coercion, whose
 * semantics are intentionally stricter (caller-input must already be
 * in target shape — see {@see \StarDust\Exception\UncoercibleSlotValueException}).
 *
 * Closed `reason` taxonomy emitted on `nullCoerced`:
 *   - `out_of_range` — value parsed but exceeds the target's range.
 *   - `non_integer` — `numeric → int` with a non-integer source.
 *   - `malformed_datetime` — `string → datetime` that does not parse.
 *   - `malformed_number` — `string → numeric` that does not parse.
 *   - `epoch_coercion_rejected` — categorically rejected datetime pair.
 *   - `unparseable` — any other coercion that the cell rule rejects.
 */
final class RetypeCoercionEngine
{
    /** Pairs the matrix categorically refuses. */
    private const REJECTED_TARGETS = [
        'int'      => ['datetime'],
        'numeric'  => ['datetime'],
        'datetime' => ['int', 'numeric'],
    ];

    public static function isCategoricallyRejected(string $from, string $to): bool
    {
        return in_array($to, self::REJECTED_TARGETS[$from] ?? [], true);
    }

    /**
     * Apply the matrix cell `($from, $to)` to one JSON-decoded value.
     *
     * `$valuePresent` distinguishes "JSON key absent" from "key
     * present, value JSON null" — both still produce
     * {@see CoercionOutcome::notAttempted()} (no event, write NULL)
     * but the executor only inspects `$value` when present.
     */
    public static function attempt(
        mixed $value,
        bool $valuePresent,
        string $from,
        string $to,
    ): CoercionOutcome {
        if (!$valuePresent || $value === null) {
            return CoercionOutcome::notAttempted();
        }
        if (self::isCategoricallyRejected($from, $to)) {
            return CoercionOutcome::nullCoerced('epoch_coercion_rejected');
        }
        if ($from === $to) {
            return self::identity($value, $from);
        }
        return match (true) {
            $from === 'string'   && $to === 'int'      => self::coerceStringToInt($value),
            $from === 'string'   && $to === 'numeric'  => self::coerceStringToNumeric($value),
            $from === 'string'   && $to === 'datetime' => self::coerceStringToDatetime($value),
            $from === 'int'      && $to === 'string'   => self::coerceIntToString($value),
            $from === 'int'      && $to === 'numeric'  => self::coerceIntToNumeric($value),
            $from === 'numeric'  && $to === 'string'   => self::coerceNumericToString($value),
            $from === 'numeric'  && $to === 'int'      => self::coerceNumericToInt($value),
            $from === 'datetime' && $to === 'string'   => self::coerceDatetimeToString($value),
            default => CoercionOutcome::nullCoerced('unparseable'),
        };
    }

    private static function identity(mixed $value, string $type): CoercionOutcome
    {
        return match ($type) {
            'string'   => is_scalar($value) || $value instanceof \Stringable
                ? CoercionOutcome::coerced((string) $value)
                : CoercionOutcome::nullCoerced('unparseable'),
            'int'      => is_int($value)
                ? CoercionOutcome::coerced($value)
                : self::coerceStringToInt(is_string($value) ? $value : (string) $value),
            'numeric'  => (is_int($value) || is_float($value))
                ? CoercionOutcome::coerced((float) $value)
                : self::coerceStringToNumeric(is_string($value) ? $value : (string) $value),
            'datetime' => is_string($value)
                ? self::reformatDatetimeForSlot($value)
                : CoercionOutcome::nullCoerced('malformed_datetime'),
            default    => CoercionOutcome::nullCoerced('unparseable'),
        };
    }

    /**
     * string → int — base-10 integer literal: optional leading `-`,
     * digits `[0-9]+`, no whitespace, no decimals, no thousands
     * separators, no leading `+`; result MUST be in signed 64-bit
     * range; else NULL.
     */
    private static function coerceStringToInt(mixed $value): CoercionOutcome
    {
        if (!is_string($value)) {
            return CoercionOutcome::nullCoerced('unparseable');
        }
        if (preg_match('/^-?[0-9]+$/', $value) !== 1) {
            return CoercionOutcome::nullCoerced('unparseable');
        }
        // PHP_INT_MAX = 9223372036854775807; round-tripping through (int)
        // overflows silently, so we re-stringify and compare.
        $asInt = (int) $value;
        if ((string) $asInt !== ltrim($value, '+')) {
            return CoercionOutcome::nullCoerced('out_of_range');
        }
        return CoercionOutcome::coerced($asInt);
    }

    /**
     * string → numeric — JSON-number grammar (RFC 8259 §6): optional
     * leading `-`, integer or fractional, optional exponent
     * `[eE][+-]?[0-9]+`; no whitespace; no `NaN`/`Infinity`; else NULL.
     */
    private static function coerceStringToNumeric(mixed $value): CoercionOutcome
    {
        if (!is_string($value)) {
            return CoercionOutcome::nullCoerced('malformed_number');
        }
        if (preg_match('/^-?(0|[1-9][0-9]*)(\.[0-9]+)?([eE][+-]?[0-9]+)?$/', $value) !== 1) {
            return CoercionOutcome::nullCoerced('malformed_number');
        }
        $f = (float) $value;
        if (!is_finite($f)) {
            return CoercionOutcome::nullCoerced('out_of_range');
        }
        return CoercionOutcome::coerced($f);
    }

    /**
     * string → datetime — strict RFC 3339 with explicit UTC offset
     * (`Z` or `±HH:MM`); naive datetimes → NULL. Result normalised to
     * UTC and formatted for the slot column (MySQL `DATETIME`,
     * `Y-m-d H:i:s`; the underlying column does not store sub-second
     * precision so fractional seconds are truncated).
     */
    private static function coerceStringToDatetime(mixed $value): CoercionOutcome
    {
        if (!is_string($value)) {
            return CoercionOutcome::nullCoerced('malformed_datetime');
        }
        // RFC 3339 with explicit offset. The literal `T` separator is
        // canonical; we also accept a single space per RFC 3339 §5.6
        // NOTE.
        $pattern = '/^\d{4}-\d{2}-\d{2}[Tt ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/';
        if (preg_match($pattern, $value) !== 1) {
            return CoercionOutcome::nullCoerced('malformed_datetime');
        }
        try {
            $dt = new DateTimeImmutable($value);
        } catch (Throwable) {
            return CoercionOutcome::nullCoerced('malformed_datetime');
        }
        return CoercionOutcome::coerced(
            $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')
        );
    }

    /**
     * int → string — decimal stringification, no leading zeros,
     * leading `-` for negatives.
     */
    private static function coerceIntToString(mixed $value): CoercionOutcome
    {
        if (is_int($value)) {
            return CoercionOutcome::coerced((string) $value);
        }
        // Defensive: a JSON numeric string for an `int` declared_type.
        if (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1) {
            $asInt = (int) $value;
            if ((string) $asInt !== ltrim($value, '+')) {
                return CoercionOutcome::nullCoerced('out_of_range');
            }
            return CoercionOutcome::coerced((string) $asInt);
        }
        return CoercionOutcome::nullCoerced('unparseable');
    }

    /**
     * int → numeric — identity (lossless widening; `int` ⊂ `numeric`
     * representable range).
     */
    private static function coerceIntToNumeric(mixed $value): CoercionOutcome
    {
        if (is_int($value)) {
            return CoercionOutcome::coerced((float) $value);
        }
        if (is_string($value) && preg_match('/^-?[0-9]+$/', $value) === 1) {
            $asInt = (int) $value;
            if ((string) $asInt !== ltrim($value, '+')) {
                return CoercionOutcome::nullCoerced('out_of_range');
            }
            return CoercionOutcome::coerced((float) $asInt);
        }
        return CoercionOutcome::nullCoerced('unparseable');
    }

    /**
     * numeric → string — canonical decimal stringification: shortest
     * representation that round-trips, no scientific notation,
     * trailing zeros after the decimal trimmed, `-0` normalised to
     * `0`, no trailing decimal point.
     */
    private static function coerceNumericToString(mixed $value): CoercionOutcome
    {
        $f = self::extractFloat($value);
        if ($f === null) {
            return CoercionOutcome::nullCoerced('malformed_number');
        }
        if ($f === 0.0) {
            return CoercionOutcome::coerced('0');
        }
        // PHP default float→string uses serialize_precision; force a
        // fixed-notation rendering and trim per ADR 0024 canonical
        // rules.
        $s = is_int($value) ? (string) $value : self::floatToCanonicalString($f);
        return CoercionOutcome::coerced($s);
    }

    /**
     * numeric → int — only if integer-valued (`floor(v) == v`) AND in
     * signed 64-bit range; otherwise NULL (no truncation, no
     * rounding).
     */
    private static function coerceNumericToInt(mixed $value): CoercionOutcome
    {
        $f = self::extractFloat($value);
        if ($f === null) {
            return CoercionOutcome::nullCoerced('malformed_number');
        }
        if (floor($f) !== $f) {
            return CoercionOutcome::nullCoerced('non_integer');
        }
        // Compare against float-representable bounds. PHP_INT_MAX as a
        // float is 9.2233720368548E+18 — strictly less-equal catches
        // overflows that lose precision at the float boundary.
        if ($f < (float) PHP_INT_MIN || $f > (float) PHP_INT_MAX) {
            return CoercionOutcome::nullCoerced('out_of_range');
        }
        return CoercionOutcome::coerced((int) $f);
    }

    /**
     * datetime → string — RFC 3339 normalised to UTC with `Z` suffix
     * (not `+00:00`). The source is stored as a MySQL DATETIME and
     * comes through JSON as a `Y-m-d H:i:s` string (no offset); we
     * treat the absence of an offset as UTC per the slot column's
     * convention. Microseconds are formatted as `.000000` for ADR
     * 0024 canonical fidelity even though the slot drops them.
     */
    private static function coerceDatetimeToString(mixed $value): CoercionOutcome
    {
        if (!is_string($value)) {
            return CoercionOutcome::nullCoerced('malformed_datetime');
        }
        try {
            // Datetime values originate from a MySQL DATETIME slot
            // (`Y-m-d H:i:s` UTC). Treat naive input as UTC.
            $hasOffset = preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value) === 1;
            $dt = $hasOffset
                ? new DateTimeImmutable($value)
                : new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return CoercionOutcome::nullCoerced('malformed_datetime');
        }
        return CoercionOutcome::coerced(
            $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z')
        );
    }

    private static function reformatDatetimeForSlot(string $value): CoercionOutcome
    {
        try {
            $hasOffset = preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $value) === 1;
            $dt = $hasOffset
                ? new DateTimeImmutable($value)
                : new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return CoercionOutcome::nullCoerced('malformed_datetime');
        }
        return CoercionOutcome::coerced(
            $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')
        );
    }

    private static function extractFloat(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            $f = (float) $value;
            return is_finite($f) ? $f : null;
        }
        if (is_string($value)
            && preg_match('/^-?(0|[1-9][0-9]*)(\.[0-9]+)?([eE][+-]?[0-9]+)?$/', $value) === 1
        ) {
            $f = (float) $value;
            return is_finite($f) ? $f : null;
        }
        return null;
    }

    private static function floatToCanonicalString(float $f): string
    {
        // `serialize_precision = -1` (PHP 7.1+ default) yields the
        // shortest round-trip representation. `(string)` honors it.
        $s = (string) $f;

        // Strip scientific notation by rendering fixed if needed.
        if (stripos($s, 'e') !== false) {
            // 14-digit precision is enough for IEEE-754 doubles round-trip.
            $s = rtrim(rtrim(sprintf('%.14F', $f), '0'), '.');
            if ($s === '' || $s === '-') {
                $s = '0';
            }
        }

        // Drop trailing zeros after the decimal; drop a trailing `.`.
        if (str_contains($s, '.')) {
            $s = rtrim(rtrim($s, '0'), '.');
        }
        return $s;
    }
}
