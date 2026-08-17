<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Watcher;

use StarDust\Clock\SystemClock;
use StarDust\Logging\StdoutNdjsonLogger;
use StarDust\Tests\Smoke\Phase5TestCase;

/**
 * ADR 0031 spread advisory against a real registry.
 *
 * The fixtures place slots on *specific* pages and in *specific*
 * statuses by direct registry UPDATE, because that is the only way to
 * construct a deliberately-fragmented model — `SlotReserver` packs onto
 * the oldest page first, which is precisely the behaviour that makes
 * spread hard to reproduce through the normal path. Same bypass
 * precedent as `Phase6aTestCase::seedSlotValues()`.
 */
final class SpreadSamplerTest extends Phase5TestCase
{
    /**
     * The headline measurement: three live string slots split across two
     * pages. The model needs one page (3 ≤ 25), so one of the two is
     * avoidable.
     */
    public function testMeasuresPagesOccupiedAgainstTheTheoreticalMinimum(): void
    {
        [$page1, $page2] = $this->twoPages();
        $modelId = $this->createModel(1);

        $this->bindSlot($page1, 'i_str_01', $this->createField($modelId, 'string', true), 'assigned');
        $this->bindSlot($page1, 'i_str_02', $this->createField($modelId, 'string', true), 'ready');
        $this->bindSlot($page2, 'i_str_01', $this->createField($modelId, 'string', true), 'assigned');

        $samples = $this->makeSpreadSampler()->report(1, $modelId);

        self::assertCount(1, $samples);
        self::assertSame(2, $samples[0]->pagesOccupied);
        self::assertSame(1, $samples[0]->theoreticalMinPages);
        self::assertSame(1, $samples[0]->excessPages());
        self::assertSame(3, $samples[0]->liveSlotCount);
    }

    /**
     * The trap ADR 0031 names by hand: `backfilling` belongs to a
     * filterable field but services no query, so it contributes no join
     * cost. Counting it would inflate both `live_slot_count` and
     * `pages_occupied` — which is exactly why the field is named
     * `live_slot_count` and not `filterable_slot_count`.
     */
    public function testBackfillingAndTombstonedSlotsAreExcluded(): void
    {
        [$page1, $page2] = $this->twoPages();
        $modelId = $this->createModel(1);

        $this->bindSlot($page1, 'i_str_01', $this->createField($modelId, 'string', true), 'assigned');
        // Both live on page2 — if either counted, pages_occupied would be 2.
        $this->bindSlot($page2, 'i_str_01', $this->createField($modelId, 'string', true), 'backfilling');
        $this->bindSlot($page2, 'i_str_02', $this->createField($modelId, 'string', true), 'tombstoned');

        $samples = $this->makeSpreadSampler()->report(1, $modelId);

        self::assertCount(1, $samples);
        self::assertSame(1, $samples[0]->pagesOccupied, 'Non-query-servicing slots must not add a page.');
        self::assertSame(1, $samples[0]->liveSlotCount);
        self::assertSame(0, $samples[0]->excessPages());
    }

    /**
     * ADR 0034 makes non-filterable fields JSON-only, but pre-0034
     * databases can still hold grandfathered live slots on them. The
     * `is_filterable = 1` predicate keeps those out of the join count —
     * the read path never consults such a slot, so it costs nothing.
     */
    public function testGrandfatheredNonFilterableSlotIsExcluded(): void
    {
        [$page1, $page2] = $this->twoPages();
        $modelId = $this->createModel(1);

        $this->bindSlot($page1, 'i_str_01', $this->createField($modelId, 'string', true), 'assigned');
        $this->bindSlot($page2, 'i_str_01', $this->createField($modelId, 'string', false), 'assigned');

        $samples = $this->makeSpreadSampler()->report(1, $modelId);

        self::assertSame(1, $samples[0]->pagesOccupied);
        self::assertSame(1, $samples[0]->liveSlotCount);
    }

    /** Spread is per-tenant per-model; partitions are measured independently. */
    public function testSamplesAreScopedPerTenantAndModel(): void
    {
        [$page1, $page2] = $this->twoPages();
        $modelA = $this->createModel(1);
        $modelB = $this->createModel(2);

        $this->bindSlot($page1, 'i_str_01', $this->createField($modelA, 'string', true), 'assigned');
        $this->bindSlot($page2, 'i_str_01', $this->createField($modelA, 'string', true), 'assigned');
        $this->bindSlot($page1, 'i_str_02', $this->createField($modelB, 'string', true), 'assigned');

        $all = $this->makeSpreadSampler()->report();
        self::assertCount(2, $all, 'Both tenants must be measured by an unscoped report.');

        $tenantOne = $this->makeSpreadSampler()->report(1);
        self::assertCount(1, $tenantOne);
        self::assertSame($modelA, $tenantOne[0]->modelId);
        self::assertSame(2, $tenantOne[0]->pagesOccupied);

        $tenantTwo = $this->makeSpreadSampler()->report(2);
        self::assertSame(1, $tenantTwo[0]->pagesOccupied);
    }

    /** A model with no live filterable slot has no spread, so no sample. */
    public function testModelWithNoLiveSlotProducesNoSample(): void
    {
        $this->twoPages();
        $modelId = $this->createModel(1);
        $this->createField($modelId, 'string', true);

        self::assertSame([], $this->makeSpreadSampler()->report(1, $modelId));
    }

    /**
     * `high_spread_model` needs BOTH bounds. A single-page model is never
     * flagged no matter what, and one avoidable join stays quiet under
     * the default threshold of 2.
     */
    public function testHighSpreadRequiresBothBounds(): void
    {
        [$page1, $page2] = $this->twoPages();
        $modelId = $this->createModel(1);

        $this->bindSlot($page1, 'i_str_01', $this->createField($modelId, 'string', true), 'assigned');
        $this->bindSlot($page2, 'i_str_01', $this->createField($modelId, 'string', true), 'assigned');

        // excess_pages = 1, below the default threshold of 2.
        $names = $this->sampleAndCollectEvents(2, 1, $modelId);
        self::assertContains('spread_sampled', $names, 'Every sample emits the continuous signal.');
        self::assertNotContains('high_spread_model', $names, 'excess_pages=1 must not alert at threshold 2.');

        // Same fragmentation, threshold tightened to 1 — now it alerts.
        $names = $this->sampleAndCollectEvents(1, 1, $modelId);
        self::assertContains('high_spread_model', $names, 'excess_pages=1 must alert at threshold 1.');
    }

    /**
     * The `pages_occupied >= 2` half of the gate, isolated: a model that
     * legitimately fits on one page can never be flagged, even with the
     * threshold driven to zero.
     */
    public function testSinglePageModelIsNeverFlagged(): void
    {
        [$page1] = $this->twoPages();
        $modelId = $this->createModel(1);
        $this->bindSlot($page1, 'i_str_01', $this->createField($modelId, 'string', true), 'assigned');

        $names = $this->sampleAndCollectEvents(0, 1, $modelId);

        self::assertContains('spread_sampled', $names);
        self::assertNotContains('high_spread_model', $names);
    }

    /** Every sample carries the full ADR 0031 field set. */
    public function testEventCarriesTheNormativeFields(): void
    {
        [$page1, $page2] = $this->twoPages();
        $modelId = $this->createModel(1);
        $this->bindSlot($page1, 'i_str_01', $this->createField($modelId, 'string', true), 'assigned');
        $this->bindSlot($page2, 'i_str_01', $this->createField($modelId, 'string', true), 'assigned');

        $events = $this->sampleAndCollectEvents(1, 1, $modelId, raw: true);
        $sampled = null;
        $flagged = null;
        foreach ($events as $event) {
            if ($event['event'] === 'spread_sampled') {
                $sampled = $event;
            }
            if ($event['event'] === 'high_spread_model') {
                $flagged = $event;
            }
        }

        self::assertNotNull($sampled);
        self::assertSame('registry', $sampled['source'], 'ADR 0020 places both events on the registry source.');
        self::assertSame(1, $sampled['tenant_id']);
        self::assertSame($modelId, $sampled['model_id']);
        self::assertSame(2, $sampled['pages_occupied']);
        self::assertSame(1, $sampled['theoretical_min_pages']);
        self::assertSame(1, $sampled['excess_pages']);
        self::assertSame(2, $sampled['live_slot_count']);
        self::assertSame('on_demand', $sampled['trigger']);
        self::assertArrayNotHasKey(
            'filterable_slot_count',
            $sampled,
            'The field is live_slot_count — the discriminator is liveness, not filterability.',
        );

        self::assertNotNull($flagged);
        self::assertSame(1, $flagged['threshold'], 'high_spread_model reports the bound in force.');
    }

    /** Trigger labels distinguish the three ADR 0031 sampling paths. */
    public function testTriggerLabels(): void
    {
        [$page1, $page2] = $this->twoPages();
        $modelId = $this->createModel(1);
        $this->bindSlot($page1, 'i_str_01', $this->createField($modelId, 'string', true), 'assigned');
        $this->bindSlot($page2, 'i_str_01', $this->createField($modelId, 'string', true), 'assigned');

        self::assertSame('on_demand', $this->firstTriggerFrom(
            static fn ($sampler) => $sampler->report(1, $modelId)
        ));
        self::assertSame('post_relocation', $this->firstTriggerFrom(
            static fn ($sampler) => $sampler->sampleModel(1, $modelId)
        ));
        self::assertSame('periodic', $this->firstTriggerFrom(
            static fn ($sampler) => $sampler->sampleAll()
        ));
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    /** @return array{0: int, 1: int} two pages, each indexed for the str family */
    private function twoPages(): array
    {
        return [
            $this->provisionPage(['i_str_01', 'i_str_02']),
            $this->provisionPage(['i_str_01', 'i_str_02']),
        ];
    }

    /**
     * Bind a field to one exact page + column + status by direct UPDATE.
     *
     * `SlotReserver` deliberately packs onto the oldest page first, so it
     * cannot produce a fragmented model on demand — placement has to be
     * forced for spread to be observable at all.
     */
    private function bindSlot(int $pageId, string $slotColumn, int $fieldId, string $status): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE stardust_slot_assignments'
            . ' SET field_id = ?, status = ?, updated_at = UTC_TIMESTAMP()'
            . ' WHERE page_id = ? AND slot_column = ?'
        );
        $stmt->execute([$fieldId, $status, $pageId, $slotColumn]);
    }

    /**
     * @return ($raw is true ? list<array<string, mixed>> : list<string>)
     */
    private function sampleAndCollectEvents(
        int $threshold,
        int $tenantId,
        int $modelId,
        bool $raw = false,
    ): array {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $logger = new StdoutNdjsonLogger(new SystemClock(), $stream);

        $this->makeSpreadSampler($logger, $threshold)->report($tenantId, $modelId);

        $events = $this->decodeStream($stream);

        return $raw ? $events : array_map(static fn (array $e) => (string) $e['event'], $events);
    }

    /** @param callable(\StarDust\Watcher\SpreadSampler): mixed $run */
    private function firstTriggerFrom(callable $run): string
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $run($this->makeSpreadSampler(new StdoutNdjsonLogger(new SystemClock(), $stream)));

        $events = $this->decodeStream($stream);
        self::assertNotSame([], $events, 'Expected at least one spread event.');

        return (string) $events[0]['trigger'];
    }

    /**
     * @param  resource $stream
     * @return list<array<string, mixed>>
     */
    private function decodeStream($stream): array
    {
        rewind($stream);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($stream))));

        return array_map(
            static fn (string $l) => (array) json_decode($l, true, flags: JSON_THROW_ON_ERROR),
            $lines,
        );
    }
}
