<?php

declare(strict_types=1);

namespace StarDust\Write;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use StarDust\Exception\UncoercibleSlotValueException;
use StarDust\Filter\Limits\FilterLimits;

/**
 * Pure value-mapping from `EntryPayload::$fields` to a per-page write
 * plan, given the live slots in a {@see LiveSlotMap}.
 *
 * Output:
 *   - `slotWrites`: `array<int $pageId, array<string $slotColumn, mixed $value>>`
 *     ready for `INSERT … ON DUPLICATE KEY UPDATE` against each
 *     `entry_slots_page_N`.
 *   - `missingSlotFields`: list of *registered, filterable* field names
 *     that appear in the payload but have no live slot. Unknown payload
 *     keys (not in `stardust_fields` for this model) and non-filterable
 *     fields (JSON-only per ADR 0034 — having no slot is their steady
 *     state, not a degradation) are silently dropped and never appear
 *     here. The write path enqueues into `stardust_sync_queue` iff this
 *     list is non-empty (ADR 0007 exhaustion fallback).
 *
 * Coercion rules (Phase 3 first-write policy, distinct from the
 * Reconciler retype-backfill rules in ADR 0024):
 *   - NULL always passes through as NULL.
 *   - declared_type=`string`:   any scalar/Stringable becomes its
 *     string representation.
 *   - declared_type=`int`:      requires an int or a numeric string
 *     that fits in BIGINT (PHP_INT_MIN … PHP_INT_MAX); floats are
 *     accepted only if they have no fractional part.
 *   - declared_type=`numeric`:  int, float, or numeric-string ⇒ float.
 *   - declared_type=`datetime`: DateTimeInterface, or a string in one
 *     of exactly two unambiguous shapes — naive `Y-m-d H:i:s` /
 *     `Y-m-d\TH:i:s` (treated as already UTC, never resolved against
 *     the host's runtime default timezone) or RFC 3339 with an
 *     explicit offset (`Z` or `±HH:MM`, converted to UTC) — ⇒
 *     `Y-m-d H:i:s` UTC.
 *
 * **Any other string shape is rejected, not guessed.** `coerceDatetime()`
 * used to hand any string straight to `new DateTimeImmutable($value)`,
 * which accepts far more than the two shapes above. Two consequences,
 * both silent: a naive string was resolved against `date_default_timezone_get()`
 * rather than treated as UTC, so the same input coerced to a different
 * instant depending on the host's `date.timezone` ini setting; and a
 * slash-separated day-first date (`05/01/2026`, the default format in
 * most non-US locales, Indonesia included) was silently reinterpreted
 * as month-first (5 January read back as 1 May) whenever the day was
 * ≤ 12, and outright rejected — but only then — once the day exceeded
 * 12. Confirmed on PHP 8.4: `new DateTimeImmutable('13/06/2026')`
 * throws while `new DateTimeImmutable('12/06/2026')` silently returns
 * 2026-12-06. Sorting or ranging on a field fed that way does not read
 * as "broken" so much as "scrambled" — most rows land close to right,
 * a few land months away, and nothing in the write path complained.
 * The two accepted shapes are validated by strict regex before any
 * `DateTimeImmutable` construction is attempted, the same posture
 * {@see \StarDust\Search\PreFlight\ValueTypeValidator::isRfc3339WithOffset()}
 * already takes on the filter side — this closes the asymmetry between
 * the two: the filter side has required an explicit offset since it
 * shipped, the write side did not.
 *
 * Anything else throws {@see UncoercibleSlotValueException}; the
 * EntryWriter catches the throw at its transaction boundary and rolls
 * the entry write back.
 */
final class PayloadSplitter
{
    /**
     * @param array<string, mixed> $fields
     * @return SplitPlan
     */
    public static function split(LiveSlotMap $map, array $fields): SplitPlan
    {
        $slotWrites = [];
        $missingSlotFields = [];

        foreach ($fields as $fieldName => $value) {
            $name = (string) $fieldName;

            // Two silent-drop cases, both leaving the value in
            // entry_data.fields (ADR 0013):
            //   - not registered in stardust_fields for this model;
            //   - registered but non-filterable — JSON-only per ADR
            //     0034, so it has no slot to write and must never
            //     enqueue. This also covers a grandfathered legacy slot:
            //     the check precedes the entry lookup, so a pre-0034
            //     slot held by a non-filterable field is left untouched
            //     rather than being kept up to date for a read path
            //     that never consults it.
            //
            // Ordering is load-bearing: putting the entry lookup first
            // would route a slotless non-filterable field into
            // $missingSlotFields and re-create the unsatisfiable
            // capacity_wait loop ADR 0034 exists to kill.
            if (! $map->isFilterable($name)) {
                continue;
            }

            $entry = $map->get($name);
            if ($entry === null) {
                // Filterable registered field with no live slot — ADR
                // 0007 exhaustion fallback: enqueue so the Reconciler
                // can backfill once capacity is restored.
                $missingSlotFields[] = $name;
                continue;
            }

            $coerced = self::coerce($value, $entry->declaredType, $entry->fieldName);
            $slotWrites[$entry->pageId] ??= [];
            $slotWrites[$entry->pageId][$entry->slotColumn] = $coerced;
        }

        return new SplitPlan(
            slotWrites: $slotWrites,
            missingSlotFields: $missingSlotFields,
        );
    }

    private static function coerce(mixed $value, string $declaredType, string $fieldName): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($declaredType) {
            'string'   => self::coerceString($value, $fieldName),
            'int'      => self::coerceInt($value, $fieldName),
            'numeric'  => self::coerceNumeric($value, $fieldName),
            'datetime' => self::coerceDatetime($value, $fieldName),
            default    => throw new UncoercibleSlotValueException(
                "Field '{$fieldName}': unsupported declared_type '{$declaredType}'."
            ),
        };
    }

    private static function coerceString(mixed $value, string $fieldName): string
    {
        if (is_string($value)) {
            $str = $value;
        } elseif (is_int($value) || is_float($value) || is_bool($value)) {
            $str = (string) $value;
        } elseif ($value instanceof \Stringable) {
            $str = (string) $value;
        } else {
            throw new UncoercibleSlotValueException(
                "Field '{$fieldName}': cannot coerce " . get_debug_type($value) . ' to string.'
            );
        }

        // A filterable string slot is `TEXT` sized for the normative 4096-char
        // QueryFilter bound (ADR 0030); past that, the slot UPSERT would raise a
        // raw MySQL 1406. Guard here — before any SQL — so the failure is a typed
        // StarDust exception, and so the write contract matches the filter bound
        // the slot is queried by (mb_strlen, FilterLimits::DEFAULT_MAX_STRING_LENGTH,
        // exactly as JsonFilterDecoder and ValueTypeValidator measure it).
        $length = mb_strlen($str);
        if ($length > FilterLimits::DEFAULT_MAX_STRING_LENGTH) {
            throw new UncoercibleSlotValueException(sprintf(
                "Field '%s': string value of length %d exceeds the maximum filterable string length of %d characters.",
                $fieldName,
                $length,
                FilterLimits::DEFAULT_MAX_STRING_LENGTH
            ));
        }

        return $str;
    }

    private static function coerceInt(mixed $value, string $fieldName): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            if (floor($value) !== $value || $value < PHP_INT_MIN || $value > PHP_INT_MAX) {
                throw new UncoercibleSlotValueException(
                    "Field '{$fieldName}': float {$value} cannot be losslessly coerced to BIGINT."
                );
            }
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            // PHP_INT_MAX is 9223372036854775807 on 64-bit hosts (the
            // BIGINT signed maximum). Strings longer than that or
            // outside the bound overflow on cast — guard explicitly.
            $asInt = (int) $value;
            if ((string) $asInt !== $value) {
                throw new UncoercibleSlotValueException(
                    "Field '{$fieldName}': integer string '{$value}' overflows BIGINT."
                );
            }
            return $asInt;
        }
        throw new UncoercibleSlotValueException(
            "Field '{$fieldName}': cannot coerce " . get_debug_type($value) . ' to int.'
        );
    }

    private static function coerceNumeric(mixed $value, string $fieldName): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }
        throw new UncoercibleSlotValueException(
            "Field '{$fieldName}': cannot coerce " . get_debug_type($value) . ' to numeric.'
        );
    }

    /**
     * Naive `Y-m-d H:i:s` / `Y-m-d\TH:i:s`, optional fractional seconds,
     * no offset — treated as already UTC.
     */
    private const NAIVE_DATETIME_PATTERN =
        '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/';

    /**
     * RFC 3339 with an explicit offset — same shape
     * {@see \StarDust\Search\PreFlight\ValueTypeValidator::isRfc3339WithOffset()}
     * requires on the filter side.
     */
    private const OFFSET_DATETIME_PATTERN =
        '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+\-]\d{2}:\d{2})$/';

    private static function coerceDatetime(mixed $value, string $fieldName): string
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        }
        if (is_string($value) && $value !== '') {
            // Reject before attempting to parse. `new DateTimeImmutable()`
            // accepts far more than these two shapes, including strings
            // that silently mean something other than what the caller
            // intended — see the class docblock.
            $isNaive  = preg_match(self::NAIVE_DATETIME_PATTERN, $value) === 1;
            $isOffset = ! $isNaive && preg_match(self::OFFSET_DATETIME_PATTERN, $value) === 1;
            if (! $isNaive && ! $isOffset) {
                throw new UncoercibleSlotValueException(
                    "Field '{$fieldName}': cannot coerce '{$value}' to datetime — expected"
                    . " 'Y-m-d H:i:s' (assumed UTC) or RFC 3339 with an explicit UTC offset"
                    . " ('Z' or '+HH:MM')."
                );
            }
            try {
                // UTC context is always safe: an offset-carrying string
                // is parsed by its own explicit offset regardless of the
                // constructor's timezone argument (PHP resolves it from
                // the string content), and a naive string is what this
                // makes deterministic — resolved as UTC rather than
                // against whatever the host's runtime default timezone
                // happens to be.
                $dt = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            } catch (\Throwable $e) {
                throw new UncoercibleSlotValueException(
                    "Field '{$fieldName}': cannot parse '{$value}' as datetime: " . $e->getMessage()
                );
            }
            return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        throw new UncoercibleSlotValueException(
            "Field '{$fieldName}': cannot coerce " . get_debug_type($value) . ' to datetime.'
        );
    }
}
