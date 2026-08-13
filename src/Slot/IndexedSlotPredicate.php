<?php

declare(strict_types=1);

namespace StarDust\Slot;

/**
 * The single definition of "this slot column is indexed".
 *
 * Two consumers ask the question and they MUST ask it identically:
 * {@see SlotReserver} filters reservation candidates with it, and the
 * Watcher's capacity reporter counts usable inventory with it. If the
 * two ever drift, the Watcher reports capacity the reserver refuses —
 * a page looks provisioned-and-healthy while every reservation against
 * it returns `null`. Hence one shared string rather than two literals.
 *
 * The predicate answers "does this column participate in ANY index on
 * its page?", not "does it carry the `(tenant_id, slot)` composite?".
 * For engine-built pages the two are equivalent: {@see PageProvisioner}
 * emits only `PRIMARY KEY (entry_id)`, `ix_<table>_tenant (tenant_id)`,
 * and one `ix_<table>_<slot> (tenant_id, <slot>)` per filterable slot,
 * so a slot column appears in an index only when it was named at
 * provisioning time. An operator-added single-column index on a slot
 * would also be accepted — by both consumers alike, which is the point.
 *
 * Reading `information_schema` per row is the cost of the registry not
 * persisting the emitted index set: `PageProvisioner` logs
 * `filterable_slots` but stores it nowhere. A future schema change
 * (`stardust_slot_assignments.is_indexed`, written at inventory-insert
 * time) would let both consumers drop the data-dictionary dependency;
 * this class is the seam that keeps that a one-file migration.
 */
final class IndexedSlotPredicate
{
    private function __construct()
    {
    }

    /**
     * SQL `EXISTS (...)` fragment testing that the slot column of
     * `$assignmentAlias` is indexed on the page table named by
     * `$pageAlias`.
     *
     * Takes aliases rather than being a bare constant so each call site
     * keeps its own naming; the defaults match `SlotReserver`'s.
     *
     * @param string $assignmentAlias alias of `stardust_slot_assignments`
     * @param string $pageAlias       alias of `stardust_pages`
     */
    public static function existsSql(string $assignmentAlias = 'a', string $pageAlias = 'p'): string
    {
        return 'EXISTS ('
            . '   SELECT 1 FROM information_schema.STATISTICS s'
            . '   WHERE s.TABLE_SCHEMA = DATABASE()'
            . "     AND s.TABLE_NAME = {$pageAlias}.table_name"
            . "     AND s.COLUMN_NAME = {$assignmentAlias}.slot_column"
            . ' )';
    }
}
