<?php

declare(strict_types=1);

namespace StarDust\Read;

/**
 * What a {@see SortSpec} orders by.
 *
 * The split is a cost boundary, not a naming convenience: the two
 * intrinsic targets are columns of `entry_data` and stay index-ordered,
 * while `Field` orders by a slot column on a joined extension page and
 * forces a temporary table plus a filesort over the whole filtered set.
 *
 * The backing values appear in cursor payloads and so are part of the
 * opaque token's internal format.
 */
enum SortTarget: string
{
    case Id        = 'id';
    case CreatedAt = 'created_at';
    case Field     = 'field';
}
