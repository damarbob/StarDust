<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Read;

use StarDust\Read\SlotResolver;
use StarDust\Read\SnapshotEntry;
use StarDust\Support\ServerEngine;
use StarDust\Tests\Smoke\WritePathTestCase;

/**
 * Which index {@see SlotResolver} records for a slot column, against real
 * DDL.
 *
 * The compiler may name that index in a `FORCE INDEX` hint, so the answer
 * has to be an index that exists, is usable and returns equal values in
 * `entry_id` order. Each case below is a way the data dictionary can differ
 * from what the engine's own page DDL would produce: an index an operator
 * named themselves, a column indexed on its own, a longer index, a prefix
 * index, a second qualifying index and an index the optimiser skips.
 *
 * Each rule was checked by breaking it and watching a case here go red:
 * accepting a longer index, accepting a prefix on the slot part, ignoring
 * the skipped-index flag, taking the first name seen instead of the
 * smallest by bytes, not requiring `tenant_id` to lead, and returning no
 * index at all.
 */
final class OrderedIndexResolutionTest extends WritePathTestCase
{
    private int $modelId = 0;

    public function testAnEnginePageResolvesItsCompositeIndex(): void
    {
        [$pageId, $table, $snapshot] = $this->intFieldOnAFreshPage();

        self::assertSame("ix_{$table}_i_int_01", $snapshot->orderedIndexFor($pageId, 'i_int_01'));
    }

    public function testAnOperatorNamedIndexResolvesWhenTheEngineNameIsGone(): void
    {
        [$pageId, $table] = $this->intFieldOnAFreshPage();
        $this->pdo->exec("ALTER TABLE {$table} DROP INDEX ix_{$table}_i_int_01, ADD INDEX operator_idx (tenant_id, i_int_01)");

        self::assertSame('operator_idx', $this->reload()->orderedIndexFor($pageId, 'i_int_01'));
    }

    public function testAnIndexOnTheSlotAloneResolvesNothing(): void
    {
        [$pageId, $table] = $this->intFieldOnAFreshPage();
        $this->pdo->exec("ALTER TABLE {$table} DROP INDEX ix_{$table}_i_int_01, ADD INDEX slot_only (i_int_01)");

        self::assertNull($this->reload()->orderedIndexFor($pageId, 'i_int_01'));
    }

    public function testAnIndexThatTenantDoesNotLeadResolvesNothing(): void
    {
        // With the slot first, equal slot values are spread across tenants,
        // so the rows for one tenant do not come back in `entry_id` order.
        [$pageId, $table] = $this->intFieldOnAFreshPage();
        $this->pdo->exec("ALTER TABLE {$table} DROP INDEX ix_{$table}_i_int_01, ADD INDEX slot_first (i_int_01, tenant_id)");

        $snapshot = $this->reload();

        self::assertNull($snapshot->orderedIndexFor($pageId, 'i_int_01'));
        // The whole map, because a wrongly accepted index would register
        // under the other column name (`tenant_id`), not under the slot.
        self::assertSame([], $snapshot->orderedIndexes);
    }

    public function testALongerIndexResolvesNothing(): void
    {
        // A third key part would sit between the slot and `entry_id` and
        // break the order, so the index is skipped even though `entry_id`
        // would in fact be reachable through it.
        [$pageId, $table] = $this->intFieldOnAFreshPage(['i_int_01', 'i_int_02']);
        $this->pdo->exec(
            "ALTER TABLE {$table} DROP INDEX ix_{$table}_i_int_01, ADD INDEX three_part (tenant_id, i_int_01, i_int_02)"
        );

        $snapshot = $this->reload();

        self::assertNull($snapshot->orderedIndexFor($pageId, 'i_int_01'));
        self::assertSame("ix_{$table}_i_int_02", $snapshot->orderedIndexFor($pageId, 'i_int_02'), 'the neighbouring column keeps its own index');
    }

    public function testAStringPrefixIndexNeverResolves(): void
    {
        $pageId = $this->provisionPage(['i_str_01']);
        $modelId = $this->createModel();
        $this->reserveSlotFor($this->createField($modelId, 'string', true, 'label'));

        $snapshot = SlotResolver::load($this->pdo, $modelId, 1);

        self::assertNull($snapshot->orderedIndexFor($pageId, 'i_str_01'));
    }

    public function testTheSmallestNameByBytesWinsWhenSeveralIndexesQualify(): void
    {
        [$pageId, $table] = $this->intFieldOnAFreshPage();
        // The dictionary lists names case-insensitively, so `a_idx` comes
        // before `B_idx`. By bytes `B_idx` is smaller, and byte order is the
        // one that does not depend on the server's collation.
        $this->pdo->exec(
            "ALTER TABLE {$table} ADD INDEX a_idx (tenant_id, i_int_01), ADD INDEX B_idx (tenant_id, i_int_01)"
        );

        self::assertSame('B_idx', $this->reload()->orderedIndexFor($pageId, 'i_int_01'));
    }

    public function testAnIndexTheOptimiserSkipsIsLeftOut(): void
    {
        [$pageId, $table] = $this->intFieldOnAFreshPage();
        // A hint that names such an index fails the read, so it must not resolve.
        $this->pdo->exec(
            $this->engine === ServerEngine::MARIADB
                ? "ALTER TABLE {$table} ALTER INDEX ix_{$table}_i_int_01 IGNORED"
                : "ALTER TABLE {$table} ALTER INDEX ix_{$table}_i_int_01 INVISIBLE"
        );

        self::assertNull($this->reload()->orderedIndexFor($pageId, 'i_int_01'));
    }

    public function testAModelWithNoSlotsResolvesNothing(): void
    {
        $modelId = $this->createModel();
        $this->createField($modelId, 'int', false, 'unindexed');

        $snapshot = SlotResolver::load($this->pdo, $modelId, 1);

        self::assertSame([], $snapshot->orderedIndexes);
        self::assertNull($snapshot->orderedIndexFor(1, 'i_int_01'));
    }

    /**
     * One page with the given indexed columns, and one filterable int field
     * holding `i_int_01`.
     *
     * @param list<string> $slots
     * @return array{int, string, SnapshotEntry} [pageId, table name, snapshot]
     */
    private function intFieldOnAFreshPage(array $slots = ['i_int_01']): array
    {
        $pageId = $this->provisionPage($slots);
        $modelId = $this->createModel();
        $this->reserveSlotFor($this->createField($modelId, 'int', true, 'rank'));
        $this->modelId = $modelId;

        return [$pageId, "entry_slots_page_{$pageId}", SlotResolver::load($this->pdo, $modelId, 1)];
    }

    /** Reloads the snapshot of the model {@see self::intFieldOnAFreshPage()} created. */
    private function reload(): SnapshotEntry
    {
        return SlotResolver::load($this->pdo, $this->modelId, 1);
    }
}
