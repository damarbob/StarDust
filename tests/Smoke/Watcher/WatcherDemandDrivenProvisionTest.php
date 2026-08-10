<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Watcher;

use StarDust\Clock\SystemClock;
use StarDust\Logging\StdoutNdjsonLogger;
use StarDust\Tests\Smoke\Phase5TestCase;

/**
 * End-to-end: the Watcher indexes each new page for the fields
 * currently waiting on one, and provisions when a family has nothing
 * claimable even if raw capacity looks fine.
 *
 * Before this, every page the Watcher created carried zero composite
 * indexes, so a field waiting on an indexed slot could never be
 * satisfied by Watcher-provisioned capacity.
 */
final class WatcherDemandDrivenProvisionTest extends Phase5TestCase
{
    /** @return list<array<string, mixed>> */
    private function tickAndReadLog(float $threshold = 0.20): array
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);

        $this->makeWatcher(new StdoutNdjsonLogger(new SystemClock(), $stream), threshold: $threshold)->tick();

        rewind($stream);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($stream))));

        return array_map(
            static fn (string $l): array => json_decode($l, true, flags: JSON_THROW_ON_ERROR),
            $lines,
        );
    }

    /** @param list<array<string, mixed>> $records */
    private function record(array $records, string $event): ?array
    {
        foreach ($records as $r) {
            if (($r['event'] ?? null) === $event) {
                return $r;
            }
        }
        return null;
    }

    private function countPages(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_pages')->fetchColumn();
    }

    /** @return list<string> */
    private function indexedColumnsOf(int $pageId): array
    {
        $table = 'entry_slots_page_' . $pageId;
        $rows = $this->pdo->query("SHOW INDEX FROM {$table}")->fetchAll(\PDO::FETCH_ASSOC);

        $cols = [];
        foreach ($rows as $row) {
            $cols[] = (string) $row['Column_name'];
        }

        return array_values(array_unique($cols));
    }

    public function testProvisionedPageCarriesAnIndexForTheDemandedFamily(): void
    {
        $modelId = $this->createModel(1);
        $this->unmappedFilterableField($modelId, 'int');

        $this->tickAndReadLog();

        self::assertSame(1, $this->countPages());
        self::assertContains('i_int_01', $this->indexedColumnsOf(1));
    }

    /**
     * The shape the fix exists for: an all-free page reports a global
     * ratio of 1.0, yet nothing on it can serve a waiter.
     */
    public function testStarvedFamilyProvisionsDespiteHealthyGlobalRatio(): void
    {
        $this->provisionPage();                       // 60 free slots, none indexed
        $modelId = $this->createModel(1);
        $this->unmappedFilterableField($modelId, 'datetime');

        $records = $this->tickAndReadLog();

        $started = $this->record($records, 'poll_started');
        self::assertNotNull($started);
        self::assertSame(1.0, (float) $started['free_ratio'], 'Raw capacity looks perfectly healthy…');
        self::assertSame(['dt'], $started['starved_families'], '…but the dt waiter has nothing claimable.');

        self::assertSame(2, $this->countPages());
        self::assertContains('i_dt_01', $this->indexedColumnsOf(2));
    }

    /** Anti-cascade: once the waiter can be served, the next tick must be quiet. */
    public function testWatcherDoesNotProvisionTwiceForOneSatisfiableWaiter(): void
    {
        $modelId = $this->createModel(1);
        $this->unmappedFilterableField($modelId, 'string');

        $this->tickAndReadLog();
        self::assertSame(1, $this->countPages());

        $records = $this->tickAndReadLog();
        self::assertSame(1, $this->countPages(), 'One page satisfies one waiter.');

        $complete = $this->record($records, 'poll_complete');
        self::assertNotNull($complete);
        self::assertSame('no_action', $complete['action']);
        self::assertSame('none', $complete['trigger']);
    }

    public function testProvisionEventsCarryIndexedColumnsAndPendingDemand(): void
    {
        $modelId = $this->createModel(1);
        $this->unmappedFilterableField($modelId, 'numeric');

        $records = $this->tickAndReadLog();

        foreach (['provision_started', 'provision_complete'] as $event) {
            $record = $this->record($records, $event);
            self::assertNotNull($record, "{$event} must fire.");
            self::assertSame(['i_num_01'], $record['indexed_columns'], "{$event} reports the columns emitted.");
            self::assertSame(['num' => 1], $record['pending_demand'], "{$event} reports the demand behind them.");
            self::assertSame('unsatisfiable_demand', $record['trigger']);
        }
    }

    public function testPollStartedCarriesUsableCapacityAndPendingDemand(): void
    {
        $this->provisionPageWithIndexedFamily('str', 2);
        $modelId = $this->createModel(1);
        $this->unmappedFilterableField($modelId, 'string');

        $records = $this->tickAndReadLog();
        $started = $this->record($records, 'poll_started');

        self::assertNotNull($started);
        self::assertSame(2, $started['usable_total_slots']);
        self::assertSame(2, $started['usable_free_slots']);
        self::assertSame(1.0, (float) $started['usable_free_ratio']);
        self::assertSame(['str' => 1], $started['pending_demand']);
        self::assertSame(1, $started['pending_waiters']);
        self::assertSame([], $started['starved_families']);
    }

    /** With nobody waiting, a headroom page indexes nothing — no speculative indexes. */
    public function testHeadroomProvisioningWithNoDemandIndexesNothing(): void
    {
        $records = $this->tickAndReadLog();

        $complete = $this->record($records, 'provision_complete');
        self::assertNotNull($complete);
        self::assertSame('low_capacity', $complete['trigger']);
        self::assertSame([], $complete['indexed_columns']);

        // Only the always-present tenant key and the primary key.
        self::assertSame(['entry_id', 'tenant_id'], $this->indexedColumnsOf(1));
    }
}
