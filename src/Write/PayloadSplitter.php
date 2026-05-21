<?php

declare(strict_types=1);

namespace StarDust\Write;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use StarDust\Exception\UncoercibleSlotValueException;

/**
 * Pure value-mapping from `EntryPayload::$fields` to a per-page write
 * plan, given the live slots in a {@see LiveSlotMap}.
 *
 * Output:
 *   - `slotWrites`: `array<int $pageId, array<string $slotColumn, mixed $value>>`
 *     ready for `INSERT … ON DUPLICATE KEY UPDATE` against each
 *     `entry_slots_page_N`.
 *   - `missingSlotFields`: list of field names that appear in the
 *     payload but have no live slot mapping. The write path uses this
 *     to decide whether to enqueue into `stardust_sync_queue` (ADR 0007
 *     exhaustion fallback).
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
 *   - declared_type=`datetime`: DateTimeInterface or
 *     `Y-m-d H:i:s` / RFC 3339 string ⇒ `Y-m-d H:i:s` UTC.
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
            $entry = $map->get((string) $fieldName);
            if ($entry === null) {
                // No live slot (unmapped, never assigned, tombstoned, or
                // assignment never landed because of capacity). Value
                // stays in entry_data.fields per ADR 0013.
                $missingSlotFields[] = (string) $fieldName;
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
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }
        throw new UncoercibleSlotValueException(
            "Field '{$fieldName}': cannot coerce " . get_debug_type($value) . ' to string.'
        );
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

    private static function coerceDatetime(mixed $value, string $fieldName): string
    {
        if ($value instanceof DateTimeInterface) {
            return (clone $value)
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        }
        if (is_string($value) && $value !== '') {
            try {
                $dt = new DateTimeImmutable($value);
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
