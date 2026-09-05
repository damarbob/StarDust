<?php

declare(strict_types=1);

namespace StarDust\Watcher;

/**
 * ADR 0042's flat headroom: the same `k` for every slot family.
 *
 * `k` comes from `Config::$pageIndexHeadroom`. The value is fixed per
 * page at creation — ADR 0012 forbids adding an index to a populated
 * page — so changing it affects pages provisioned afterwards and
 * nothing already on disk.
 *
 * `k = 0` is a legal and exact opt-out to the pre-0042 policy: the
 * planner's own floor still gives a family somebody is waiting on one
 * column, so the Watcher degrades to demand-sizing rather than to
 * starvation.
 */
final class FlatIndexHeadroom implements IndexHeadroomPolicy
{
    public function __construct(private readonly int $k)
    {
    }

    public function headroomFor(string $family): int
    {
        return $this->k;
    }
}
