<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Slot;

use Psr\Log\NullLogger;
use StarDust\Clock\SystemClock;
use StarDust\Slot\IndexedFreeCapacityReader;
use StarDust\Slot\SlotReserver;
use StarDust\Support\StringKeyLayout;
use StarDust\Tests\Smoke\WritePathTestCase;
use StarDust\Watcher\CapacityReporter;

/**
 * Capacity and reservation on a key page, where a string slot is in no index
 * and its generated key is.
 *
 * The page here is built by hand from a prefix page, by swapping each string
 * slot's prefix index for an index on its key column. That keeps the
 * registry rows identical to what the provisioner writes while the
 * provisioner itself still builds prefix pages. If the reserver and the two
 * capacity readers did not accept a key column as the slot's index, the
 * reserver would refuse every string slot on a key page, the Watcher would
 * count none of them as usable, and it would provision another page on every
 * tick.
 */
final class KeyPageCapacityTest extends WritePathTestCase
{
    /**
     * @param list<string> $stringSlots
     */
    private function handBuiltKeyPage(array $stringSlots): int
    {
        $pageId = $this->provisionPrefixPage($stringSlots);
        $table = "entry_slots_page_{$pageId}";

        foreach ($stringSlots as $slot) {
            $this->pdo->exec(sprintf(
                'ALTER TABLE %s DROP INDEX ix_%s_%s, ADD COLUMN %s, ADD KEY ix_%s_%s (tenant_id, %s)',
                $table,
                $table,
                $slot,
                StringKeyLayout::columnDdl($slot),
                $table,
                $slot,
                StringKeyLayout::keyColumn($slot),
            ));
        }

        return $pageId;
    }

    private function reserver(): SlotReserver
    {
        return new SlotReserver(pdo: $this->pdo, clock: new SystemClock(), logger: new NullLogger());
    }

    public function testTheReserverClaimsAStringSlotThatIsIndexedOnlyThroughItsKey(): void
    {
        $pageId = $this->handBuiltKeyPage(['i_str_01']);
        $fieldId = $this->createField($this->createModel(), 'string', true);

        $assignment = $this->reserver()->reserveForExhaustionBackfill($fieldId);

        self::assertNotNull($assignment, 'The key column counts as the slot\'s index.');
        self::assertSame($pageId, $assignment->pageId);
        self::assertSame('i_str_01', $assignment->slotColumn);
    }

    public function testTheReserverStillRefusesAStringSlotNothingIndexes(): void
    {
        $this->provisionLegacyPage();
        $fieldId = $this->createField($this->createModel(), 'string', true);

        self::assertNull($this->reserver()->reserveForExhaustionBackfill($fieldId));
    }

    public function testTheIndexedFreeCapacityReaderCountsKeyPagesAndPrefixPagesAndNotLegacyOnes(): void
    {
        $this->provisionLegacyPage();
        $prefix = $this->provisionPrefixPage(['i_str_01', 'i_int_01']);
        $key = $this->handBuiltKeyPage(['i_str_01', 'i_str_02']);

        self::assertEquals(
            [$prefix => ['str' => 1, 'int' => 1], $key => ['str' => 2]],
            (new IndexedFreeCapacityReader($this->pdo))->load(),
        );
    }

    public function testTheCapacityReporterCountsEveryKeyPageStringSlotAsUsable(): void
    {
        $this->handBuiltKeyPage(['i_str_01', 'i_str_02']);

        $snapshot = (new CapacityReporter($this->pdo))->report();

        self::assertSame(2, $snapshot->totalByFamily['str']);
        self::assertSame(2, $snapshot->indexedTotalFor('str'));
        self::assertSame(2, $snapshot->indexedFreeFor('str'), 'Free and claimable must be the same set on a key page.');
    }

    public function testMixedShapesAreCountedEachOnItsOwnTerms(): void
    {
        $this->provisionLegacyPage();
        $this->provisionPrefixPage(['i_str_01']);
        $this->handBuiltKeyPage(['i_str_01', 'i_str_02']);

        $snapshot = (new CapacityReporter($this->pdo))->report();

        // 25 on the legacy page, 1 on the prefix page, 2 on the key page.
        self::assertSame(28, $snapshot->totalByFamily['str']);
        // Only the prefix page's slot and the key page's two are indexed.
        self::assertSame(3, $snapshot->indexedTotalFor('str'));
        self::assertSame(3, $snapshot->indexedFreeFor('str'));
    }
}
