<?php

declare(strict_types=1);

namespace StarDust\Support;

use DateTimeInterface;

/**
 * Detects a date or time that PHP moved forward to make it valid.
 *
 * `new DateTimeImmutable('2026-02-31T00:00:00Z')` does not throw. It
 * returns March 3. Hour 24 becomes the next day and second 60 becomes the
 * next minute. A regex cannot see that, because `2026-02-31` has the right
 * shape, so every place that accepts a datetime string asks this class
 * after parsing. The write path, the filter check and the retype backfill
 * share it so that one value cannot be valid in one of them and not in
 * another.
 */
final class DatetimeRollover
{
    /**
     * True when `$parsed` does not read back as the date and time in
     * `$text`.
     *
     * `$text` must already match a datetime shape, so its first 19
     * characters are `Y-m-d`, one separator character and `H:i:s`.
     * `$parsed` must be the object built from `$text` and still in the
     * offset the text carries, before any conversion to UTC, because the
     * comparison is made on the fields as written.
     */
    public static function occurred(string $text, DateTimeInterface $parsed): bool
    {
        $given = substr($text, 0, 10) . 'T' . substr($text, 11, 8);

        return $parsed->format('Y-m-d\TH:i:s') !== $given;
    }
}
