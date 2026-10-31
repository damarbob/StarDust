<?php

declare(strict_types=1);

namespace StarDust\Read;

/**
 * Where entries with no value for the sort field appear in a field sort.
 *
 * MySQL puts them first in an ascending sort and last in a descending one,
 * and that stays the placement whenever a caller names none.
 *
 * The backing values appear in cursor payloads, so they are part of the
 * opaque token's internal format and must not be renamed without a cursor
 * version bump.
 */
enum NullPlacement: string
{
    case First = 'first';
    case Last  = 'last';

    /**
     * The placement the database gives a sort in this direction when the
     * `ORDER BY` says nothing about NULL.
     */
    public static function nativeFor(SortDirection $direction): self
    {
        return $direction === SortDirection::Asc ? self::First : self::Last;
    }
}
