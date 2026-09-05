<?php

declare(strict_types=1);

namespace StarDust\Watcher;

/**
 * How many columns of one slot family a page being provisioned right
 * now should index, before the planner's floor and cap are applied.
 *
 * ## Why this is an interface rather than a constant
 *
 * ADR 0042 establishes a flat headroom `k` and is explicit that the
 * measurements behind it cannot separate `k=2` from `k=4` — the
 * constant follows from an asymmetry argument, not from an optimum. It
 * then leaves two refinements open, and **both change the rule rather
 * than the number**: a per-family `k` (the 25/15/10/10 layout already
 * encodes a belief that string fields dominate), and a registry-derived
 * `k` clamped to `[k_min, k_max]`.
 *
 * Each of those is a different implementation of this one method, so
 * settling the question later costs a new `final` class and one line in
 * {@see \StarDust\StarDust::watcher()} — not an edit to the planner, the
 * Watcher, or {@see \StarDust\Page\PageProvisioner}.
 *
 * Note the registry-derived form is **not reachable from today's
 * inputs**: it wants a model's registered field counts per family, and
 * {@see PendingDemand} is keyed by family alone. Any implementation
 * needing more than the family name must be constructed with it — the
 * I/O belongs in the Watcher's tick, not behind this method, which is
 * why the signature deliberately takes nothing else.
 *
 * ## The contract, which the planner does not trust
 *
 * An implementation returns a count. It is **not** required to respect
 * the family's per-page capacity, nor to guarantee that a family
 * somebody is waiting on gets at least one column: the planner applies
 * both independently, so no implementation can break ADR 0035's
 * starvation-freedom guarantee or emit a column that does not exist.
 *
 * A negative return is clamped to zero rather than rejected. That guard
 * is load-bearing and looks redundant: PHP enforces only `int`, and a
 * negative length reaching `array_slice()` selects everything *but* the
 * last N columns — silently indexing most of a family instead of none
 * of it.
 */
interface IndexHeadroomPolicy
{
    /**
     * @param string $family a slot family code (`str`, `int`, `num`, `dt`)
     *                       matching the `stardust_slot_assignments.slot_type`
     *                       ENUM. Implementations must be total over it.
     */
    public function headroomFor(string $family): int;
}
