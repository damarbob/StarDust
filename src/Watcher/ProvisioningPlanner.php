<?php

declare(strict_types=1);

namespace StarDust\Watcher;

use StarDust\Page\PageProvisioner;

/**
 * Decides whether the Watcher provisions this cycle, and which columns
 * the new page should index.
 *
 * Pure static — no state, no I/O — so the whole policy matrix is unit
 * testable without a database. Same shape as
 * {@see \StarDust\Retype\RetypeCoercionEngine}.
 *
 * ## Two triggers, OR-composed
 *
 *   1. **Unsatisfiable demand.** A family someone is waiting on has
 *      zero claimable (indexed, free) slots. Provisions regardless of
 *      the threshold — this is the starvation-freedom guarantee.
 *   2. **Low capacity.** The global free ratio fell below the
 *      configured threshold. Unchanged from before.
 *
 * ## Why usable capacity is a set test, not a ratio
 *
 * The natural reading — "free slots that cannot satisfy pending demand
 * do not count toward the threshold" — specifies a numerator and no
 * denominator, and **both candidate denominators diverge**:
 *
 *   - *Global*: one waiter across ten pages gives a usable ratio near
 *     zero; a new page adds one usable slot but sixty global ones, so
 *     the ratio never recovers and the daemon provisions every tick.
 *   - *Usable*: converges only from an empty base. With existing
 *     inventory (threshold 0.20, one `str` waiter, 25 indexed `str`
 *     slots all reserved) it walks 0/25 → 1/26 → 2/27 → … and needs
 *     **seven pages in seven ticks for one field**. In general the
 *     fixed point needs `k ≥ (threshold·t − f) / (1 − threshold)`,
 *     unbounded in `t`.
 *
 * Converging a ratio would require indexing far more columns than
 * anyone is waiting for, which the phase plan rules out ("all other
 * columns on the page are created unindexed"). So trigger 1 is a set
 * test instead: it fires exactly when a family has nothing claimable,
 * and clears as soon as the new page carries one indexed free column of
 * that family. One page per starved family-set, no cascade.
 *
 * That still delivers what the ratio was for — a page full of unindexed
 * free columns can never mask a real shortage, because trigger 1 never
 * looks at unindexed slots. And being OR-composed with the old ratio
 * check, this is strictly *more* eager to provision than any ratio
 * form, so the guarantee holds a fortiori.
 *
 * `usableFreeRatio` is still computed and logged, because operators
 * asked for the number. **It is deliberately not a trigger.** Do not
 * "fix" it into one.
 */
final class ProvisioningPlanner
{
    private function __construct()
    {
    }

    public static function plan(
        CapacitySnapshot $snapshot,
        PendingDemand $demand,
        float $threshold,
        IndexHeadroomPolicy $headroom,
    ): ProvisioningPlan {
        $usableFree  = 0;
        $usableTotal = 0;
        $starved     = [];

        foreach ($demand->families() as $family) {
            $indexedFree = $snapshot->indexedFreeFor($family);

            $usableFree  += $indexedFree;
            $usableTotal += $snapshot->indexedTotalFor($family);

            if ($indexedFree === 0) {
                $starved[] = $family;
            }
        }

        // With nobody waiting, every free slot is usable by whatever
        // arrives next, so the honest reading of "usable" is the global
        // figure — counts included, not just the ratio. Leaving the
        // counts at 0 here would emit a self-contradictory log line
        // ("0 free of 0 total, ratio 1.0"), and reporting a 0/0 ratio
        // instead would make an idle deployment provision every tick.
        if ($demand->isEmpty()) {
            $usableFree      = $snapshot->totalFree;
            $usableTotal     = $snapshot->totalSlots;
            $usableFreeRatio = $snapshot->globalFreeRatio();
        } else {
            $usableFreeRatio = $usableTotal === 0 ? 0.0 : $usableFree / $usableTotal;
        }

        $lowCapacity = $snapshot->globalFreeRatio() < $threshold;

        $trigger = match (true) {
            // Precedence: starvation is the actionable condition, and
            // an operator seeing it should not have it reported as
            // routine headroom.
            $starved !== [] => ProvisioningPlan::TRIGGER_UNSATISFIABLE_DEMAND,
            $lowCapacity    => ProvisioningPlan::TRIGGER_LOW_CAPACITY,
            default         => ProvisioningPlan::TRIGGER_NONE,
        };

        $shouldProvision = $trigger !== ProvisioningPlan::TRIGGER_NONE;

        return new ProvisioningPlan(
            shouldProvision: $shouldProvision,
            trigger: $trigger,
            indexedColumns: $shouldProvision ? self::indexedColumnsFor($snapshot, $demand, $headroom) : [],
            starvedFamilies: $starved,
            usableFree: $usableFree,
            usableTotal: $usableTotal,
            usableFreeRatio: $usableFreeRatio,
        );
    }

    /**
     * Columns the new page should index: enough of each demanded family
     * to cover its shortfall, floored at one, widened to the
     * {@see IndexHeadroomPolicy}'s headroom, and capped at the family's
     * per-page capacity — evaluated for **every** family, not only the
     * demanded ones (ADR 0042).
     *
     * Indexing only what is demanded was coherent while ADR 0003 let a
     * non-filterable field hold a slot "for typed retrieval". ADR 0034
     * withdrew that, and with ADR 0004 requiring a filterable field's
     * slot to be indexed, an unindexed slot column can no longer be
     * occupied by anything — so a page's usable capacity is permanently
     * whatever it indexed at birth, and ADR 0012 forbids widening it
     * later. Sizing to demand alone therefore produced one page per
     * serially promoted field.
     *
     * The floor is not an optimisation — a page provisioned while a
     * field waits on that family must carry an index on at least one of
     * its free columns, and that binds the low-capacity path too, where
     * the shortfall can be zero or negative. It is applied here rather
     * than left to the policy so that no policy, including `k = 0` and
     * anything a consumer writes, can break ADR 0035's
     * starvation-freedom guarantee. **It is conditional on demand**: an
     * undemanded family floors at zero, which is what keeps `k = 0` an
     * exact opt-out to the pre-0042 policy rather than a rule that still
     * indexes four columns.
     *
     * The outer `max(0, …)` looks redundant and is not: PHP enforces
     * only `int` on the policy's return, and `array_slice($cols, 0, -3)`
     * yields everything *but* the last three columns — so a negative
     * headroom would silently index most of a family instead of none.
     *
     * @return list<string>
     */
    private static function indexedColumnsFor(
        CapacitySnapshot $snapshot,
        PendingDemand $demand,
        IndexHeadroomPolicy $headroom,
    ): array {
        $columns = [];

        // Declaration order (str, int, num, dt) rather than the demand
        // map's ksorted order. The result reaches `filterable_slots` on
        // page_provisioned and `indexed_columns` on three Watcher lines,
        // so the order is observable; grouping by family beats alphabetical.
        foreach (PageProvisioner::slotFamilies() as $family) {
            $available = PageProvisioner::slotColumnsForType($family);

            $demanded  = $demand->waitersFor($family);
            $shortfall = $demanded - $snapshot->indexedFreeFor($family);
            $want = max($demanded > 0 ? 1 : 0, $shortfall, $headroom->headroomFor($family));
            $take = max(0, min($want, count($available)));

            foreach (array_slice($available, 0, $take) as $column) {
                $columns[] = $column;
            }
        }

        return $columns;
    }
}
