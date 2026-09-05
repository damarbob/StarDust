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
 * `k = 0` is legal and degrades the Watcher to demand-sizing rather
 * than to starvation: the planner's own floor still gives a family
 * somebody is waiting on one column. It is not an exact return to the
 * pre-0042 policy, because that policy also provisioned pages for
 * families nobody was waiting on, and under ADR 0043 such a page has no
 * columns at all — the planner declines it instead.
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
