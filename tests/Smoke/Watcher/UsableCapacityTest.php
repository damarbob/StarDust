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

        self::assertSame(PageProvisioner::STRING_SLOTS, $snapshot->totalByFamily['str']);
    }

    /** The exact shape that used to mask a shortage: plenty free, none claimable. */
    public function testUnindexedPageContributesZeroIndexedCapacity(): void
    {
        $this->provisionPage();

        $snapshot = $this->report();

        self::assertSame(PageProvisioner::SLOTS_PER_PAGE, $snapshot->totalSlots);
        self::assertSame(PageProvisioner::SLOTS_PER_PAGE, $snapshot->totalFree);
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
        $this->provisionPage();

        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'claimed');
        $this->reserveSlotFor($fieldId);

        $snapshot = $this->report();

        $expectedTotal = 2 * PageProvisioner::SLOTS_PER_PAGE;
        self::assertSame($expectedTotal, $snapshot->totalSlots);
        self::assertSame($expectedTotal - 1, $snapshot->totalFree, 'One slot reserved.');
        self::assertSame(2, $snapshot->pagesInspected);
        self::assertSame(($expectedTotal - 1) / $expectedTotal, $snapshot->globalFreeRatio());

        self::assertSame(2 * PageProvisioner::STRING_SLOTS, $snapshot->totalByFamily['str']);
        self::assertSame(2 * PageProvisioner::DATETIME_SLOTS, $snapshot->totalByFamily['dt']);
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
