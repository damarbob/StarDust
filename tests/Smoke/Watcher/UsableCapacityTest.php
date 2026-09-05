<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Watcher;

use StarDust\Page\PageProvisioner;
use StarDust\Tests\Smoke\Phase5TestCase;
use StarDust\Watcher\CapacityReporter;

/**
 * Index-aware capacity counting.
 *
 * Every reservation path with a production caller demands an indexed
 * slot, so free inventory on unindexed columns is capacity nobody can
 * claim. These tests pin that the reporter can tell the two apart —
 * and, just as importantly, that adding the distinction left the
 * pre-existing global counts untouched.
 */
final class UsableCapacityTest extends Phase5TestCase
{
    private function report(): \StarDust\Watcher\CapacitySnapshot
    {
        return (new CapacityReporter($this->pdo))->report();
    }

    public function testIndexedCountsCoverOnlyColumnsWithAnIndex(): void
    {
        $this->provisionPage(['i_str_01', 'i_int_01']);

        $snapshot = $this->report();

        self::assertSame(1, $snapshot->indexedTotalFor('str'));
        self::assertSame(1, $snapshot->indexedFreeFor('str'));
        self::assertSame(1, $snapshot->indexedTotalFor('int'));
        self::assertSame(0, $snapshot->indexedTotalFor('num'), 'num was not named at provisioning.');
        self::assertSame(0, $snapshot->indexedTotalFor('dt'));

        // ADR 0043: the page carries only what it indexes, so the
        // family's total *is* its indexed total. This used to read
        // `STRING_SLOTS` — twenty-five rows for one usable column.
        self::assertSame(1, $snapshot->totalByFamily['str']);
    }

    /**
     * ADR 0043: on a current-shape page every free slot is claimable, so
     * the gauge an operator reads is a number they can act on. This is
     * the assertion the whole decision exists to make true.
     */
    public function testEveryFreeSlotOnACurrentShapePageIsClaimable(): void
    {
        $this->provisionPage(['i_str_01', 'i_str_02', 'i_int_01', 'i_dt_01']);

        $snapshot = $this->report();

        self::assertSame(4, $snapshot->totalSlots);
        self::assertSame(4, $snapshot->totalFree);

        $indexedFree = 0;
        foreach (['str', 'int', 'num', 'dt'] as $family) {
            self::assertSame(
                $snapshot->freeByFamily[$family],
                $snapshot->indexedFreeFor($family),
                "Free and claimable must be the same set for {$family}.",
            );
            $indexedFree += $snapshot->indexedFreeFor($family);
        }
        self::assertSame($snapshot->totalFree, $indexedFree);
    }

    /**
     * The same property through the production path that actually
     * creates pages. The test above provisions directly, so it proves
     * the provisioner keeps its promise; this one proves the Watcher
     * asks for a page whose inventory is entirely claimable — which is
     * the number `poll_started` reports as `usable_free_slots`.
     *
     * ADR 0042's measured repro logged `usable_free_slots: 177` against
     * zero claimable slots; the ratio below is what that reads now.
     */
    public function testAWatcherProvisionedPageReportsNoUnclaimableCapacity(): void
    {
        $this->makeWatcher(threshold: 0.20)->tick();

        $snapshot = $this->report();

        self::assertGreaterThan(0, $snapshot->totalSlots, 'The tick must have provisioned something.');

        $claimable = 0;
        foreach (['str', 'int', 'num', 'dt'] as $family) {
            $claimable += $snapshot->indexedFreeFor($family);
        }

        self::assertSame(
            $snapshot->totalFree,
            $claimable,
            'Every free slot the Watcher reports must be one a reservation could take.',
        );
        self::assertSame(1.0, $snapshot->globalFreeRatio());
    }

    /**
     * The exact shape that used to mask a shortage: plenty free, none
     * claimable. Pages provisioned before ADR 0043 keep their sixty
     * columns for good (ADR 0012 is forward-only), so this is permanent
     * coverage for `IndexedSlotPredicate`, not a transitional case.
     */
    public function testLegacyShapedPageContributesZeroIndexedCapacity(): void
    {
        $this->provisionLegacyPage();

        $snapshot = $this->report();

        self::assertSame(60, $snapshot->totalSlots);
        self::assertSame(60, $snapshot->totalFree);
        self::assertSame(1.0, $snapshot->globalFreeRatio(), 'Globally the page looks entirely free…');

        foreach (['str', 'int', 'num', 'dt'] as $family) {
            self::assertSame(0, $snapshot->indexedFreeFor($family), "…but no {$family} slot is claimable.");
        }
    }

    public function testReservingAnIndexedSlotDropsIndexedFreeButNotIndexedTotal(): void
    {
        $this->provisionPage(['i_str_01', 'i_str_02']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'claimed');
        $this->reserveSlotFor($fieldId);

        $snapshot = $this->report();

        self::assertSame(2, $snapshot->indexedTotalFor('str'));
        self::assertSame(1, $snapshot->indexedFreeFor('str'));
    }

    /**
     * Regression lock: the index-aware aggregate replaced the old
     * GROUP BY, so prove the numbers it already reported are unchanged.
     */
    public function testGlobalCountsAreUnchangedByTheIndexAwareAggregate(): void
    {
        $this->provisionPage(['i_str_01']);
        $this->provisionLegacyPage();

        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'claimed');
        $this->reserveSlotFor($fieldId);

        $snapshot = $this->report();

        // One current-shape page (a single indexed string column) plus
        // one legacy page (all sixty). Counted per page from its own
        // inventory, which is the only way to count them now.
        $expectedTotal = 1 + 60;
        self::assertSame($expectedTotal, $snapshot->totalSlots);
        self::assertSame($expectedTotal - 1, $snapshot->totalFree, 'One slot reserved.');
        self::assertSame(2, $snapshot->pagesInspected);
        self::assertSame(($expectedTotal - 1) / $expectedTotal, $snapshot->globalFreeRatio());

        self::assertSame(1 + PageProvisioner::STRING_SLOTS, $snapshot->totalByFamily['str']);
        self::assertSame(PageProvisioner::DATETIME_SLOTS, $snapshot->totalByFamily['dt']);
    }

    public function testFreshDatabaseWithNoPagesReportsZeroEverything(): void
    {
        $snapshot = $this->report();

        self::assertSame(0, $snapshot->totalSlots);
        self::assertSame(0, $snapshot->pagesInspected);
        self::assertSame(0.0, $snapshot->globalFreeRatio());
        self::assertSame(0, $snapshot->indexedFreeFor('str'));
    }
}
