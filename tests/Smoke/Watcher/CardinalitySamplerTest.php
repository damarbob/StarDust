<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Watcher;

use StarDust\Clock\SystemClock;
use StarDust\Logging\StdoutNdjsonLogger;
use StarDust\Tests\Smoke\Phase5TestCase;
use StarDust\Watcher\CardinalitySampler;

/**
 * ADR 0019 cardinality advisory exit criteria:
 *   - `cardinality_sampled` event per (tenant, slot) on every sample;
 *   - `low_cardinality_index` fires when distinct floor is breached;
 *   - both events carry `source: 'registry'`.
 */
final class CardinalitySamplerTest extends Phase5TestCase
{
    public function testEmitsCardinalitySampledForEveryActiveSlot(): void
    {
        [$modelId, $_fieldId, $_pageId, $fieldName] = $this->setupModelWithReservedField(1, 'string');
        $this->seedEntry(1, $modelId, [$fieldName => 'distinct-1']);
        $this->seedEntry(1, $modelId, [$fieldName => 'distinct-2']);

        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $logger = new StdoutNdjsonLogger(new SystemClock(), $stream);

        $sampler = new CardinalitySampler(
            pdo: $this->pdo,
            logger: $logger,
            selectivityThreshold: 0.01,
            rowFloor: 10_000,
            distinctFloor: 10,
        );
        $sampler->sample();

        $events = $this->readEvents($stream);
        $sampled = array_filter($events, static fn (array $e) => ($e['event'] ?? null) === 'cardinality_sampled');
        self::assertNotEmpty($sampled);
        foreach ($sampled as $event) {
            self::assertSame('registry', $event['source'] ?? null);
            self::assertArrayHasKey('row_count', $event);
            self::assertArrayHasKey('distinct_values', $event);
            self::assertArrayHasKey('selectivity', $event);
            self::assertSame('periodic', $event['trigger'] ?? null);
        }
    }

    public function testLowDistinctFloorTriggersWarning(): void
    {
        [$modelId, $_fieldId, $_pageId, $fieldName] = $this->setupModelWithReservedField(1, 'string');
        // Five entries, all the same value → distinct = 1 < floor = 10.
        for ($i = 0; $i < 5; $i++) {
            $this->seedEntry(1, $modelId, [$fieldName => 'same']);
        }

        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $logger = new StdoutNdjsonLogger(new SystemClock(), $stream);

        $sampler = new CardinalitySampler(
            pdo: $this->pdo,
            logger: $logger,
            selectivityThreshold: 0.01,
            rowFloor: 10_000,
            distinctFloor: 10,
        );
        $sampler->sample();

        $events = $this->readEvents($stream);
        $low = array_filter($events, static fn (array $e) => ($e['event'] ?? null) === 'low_cardinality_index');
        self::assertNotEmpty($low, 'distinct=1 must trigger low_cardinality_index');
        foreach ($low as $event) {
            self::assertStringContainsString('distinct_floor', (string) ($event['threshold_violated'] ?? ''));
        }
    }

    /**
     * ADR 0052's companion: the on-demand trigger backing
     * `bin/stardust cardinality:report`. Like `SpreadSampler::report()`
     * it both emits and returns, so the CLI prints without re-running
     * the aggregates.
     */
    public function testReportReturnsSamplesAndEmitsOnDemandTrigger(): void
    {
        [$modelId, $_fieldId, $_pageId, $fieldName] = $this->setupModelWithReservedField(1, 'string');
        $this->seedEntry(1, $modelId, [$fieldName => 'a']);
        $this->seedEntry(1, $modelId, [$fieldName => 'b']);
        $this->seedEntry(1, $modelId, [$fieldName => 'b']);

        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $sampler = $this->sampler(new StdoutNdjsonLogger(new SystemClock(), $stream));

        $samples = $sampler->report();

        self::assertCount(1, $samples, 'one live slot, one tenant');
        $sample = $samples[0];
        self::assertSame(1, $sample->tenantId);
        self::assertSame(3, $sample->rowCount);
        self::assertSame(2, $sample->distinctValues);
        self::assertSame(0.6667, $sample->selectivity);
        self::assertSame('i_str_01', $sample->slotColumn);

        $sampled = array_values(array_filter(
            $this->readEvents($stream),
            static fn (array $e) => ($e['event'] ?? null) === 'cardinality_sampled',
        ));
        self::assertCount(1, $sampled);
        self::assertSame('on_demand', $sampled[0]['trigger']);
        self::assertSame('registry', $sampled[0]['source']);
        // The printed row and the emitted event must never disagree.
        self::assertSame($sample->selectivity, $sampled[0]['selectivity']);
    }

    /**
     * `--model` narrows which SLOTS are examined. It deliberately does
     * not narrow the row counts: ADR 0019's aggregate is per
     * `(tenant, slot)` over the whole page, because the index it
     * describes is `(tenant_id, slot_column)` and a page is shared.
     */
    public function testModelFilterNarrowsWhichSlotsAreSampled(): void
    {
        [$modelA, $_fA, $pageId, $fieldA] = $this->setupModelWithReservedField(1, 'string');
        $this->seedEntry(1, $modelA, [$fieldA => 'a']);

        // A second model with its own filterable field on the SAME page.
        $modelB = $this->createModel(1, 'cardinality_filter_b');
        $fieldB = $this->createField($modelB, 'string', true, 'other');
        $this->reserveSlotFor($fieldB);
        $this->seedEntry(1, $modelB, ['other' => 'b']);

        $sampler = $this->sampler();

        self::assertCount(2, $sampler->report(), 'unfiltered sees both models\' slots');

        $onlyA = $sampler->report(null, $modelA);
        self::assertCount(1, $onlyA);
        self::assertSame($pageId, $onlyA[0]->pageId);
        self::assertSame('i_str_01', $onlyA[0]->slotColumn);

        $onlyB = $sampler->report(null, $modelB);
        self::assertCount(1, $onlyB);
        self::assertNotSame($onlyA[0]->slotColumn, $onlyB[0]->slotColumn);
    }

    public function testTenantFilterRestrictsSamplesToThatTenant(): void
    {
        [$modelId, $_fieldId, $_pageId, $fieldName] = $this->setupModelWithReservedField(1, 'string');
        $this->seedEntry(1, $modelId, [$fieldName => 'tenant-one']);
        $this->seedEntry(2, $modelId, [$fieldName => 'tenant-two']);

        $sampler = $this->sampler();

        self::assertCount(2, $sampler->report(), 'both tenants present on the page');

        $onlyOne = $sampler->report(1);
        self::assertCount(1, $onlyOne);
        self::assertSame(1, $onlyOne[0]->tenantId);
        self::assertSame(1, $onlyOne[0]->rowCount);
    }

    /**
     * A tenant filter must CONFIRM the tenant is on the page, not
     * assume it.
     *
     * Taking the filter value on trust invents a sample — `row_count:
     * 0`, `distinct_values: 0` — which then trips
     * `low_cardinality_index` on the distinct floor and warns about an
     * index holding none of that tenant's data. An operator triaging a
     * tenant id they mistyped, or one whose rows all live on another
     * page, would get a warning about every live slot in the
     * deployment.
     */
    public function testATenantWithNoRowsOnThePageYieldsNoSampleAndNoWarning(): void
    {
        [$modelId, $_fieldId, $_pageId, $fieldName] = $this->setupModelWithReservedField(1, 'string');
        $this->seedEntry(1, $modelId, [$fieldName => 'a']);

        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);

        $samples = $this->sampler(new StdoutNdjsonLogger(new SystemClock(), $stream))->report(999);

        self::assertSame([], $samples, 'a tenant absent from the page has no cardinality to report');

        $events = $this->readEvents($stream);
        self::assertSame([], array_values(array_filter(
            $events,
            static fn (array $e) => ($e['event'] ?? null) === 'low_cardinality_index',
        )), 'an absent tenant must not trip the distinct floor');
        self::assertSame([], array_values(array_filter(
            $events,
            static fn (array $e) => ($e['event'] ?? null) === 'cardinality_sampled',
        )), 'and must emit no sample at all');
    }

    /**
     * A tombstoned slot carries `field_id = NULL`, so the LEFT join has
     * to stay a LEFT join — an INNER one would silently shrink the
     * unfiltered scan the periodic trigger has always covered.
     */
    public function testAnOrphanedSlotIsStillSampledWithANullFieldId(): void
    {
        [$modelId, $fieldId, $_pageId, $fieldName] = $this->setupModelWithReservedField(1, 'string');
        $this->seedEntry(1, $modelId, [$fieldName => 'a']);

        // Sever the field without changing status: the shape ADR 0037's
        // two-step tombstone passes through, and what a grandfathered
        // pre-0034 row can look like.
        $this->pdo->exec('UPDATE stardust_slot_assignments SET field_id = NULL WHERE field_id = ' . $fieldId);

        $samples = $this->sampler()->report();

        self::assertCount(1, $samples);
        self::assertNull($samples[0]->fieldId);
        self::assertSame(1, $samples[0]->rowCount);
    }

    public function testReportReturnsNothingWhenNoSlotIsLive(): void
    {
        $this->provisionPage(['i_str_01']);

        self::assertSame([], $this->sampler()->report());
    }

    private function sampler(?\Psr\Log\LoggerInterface $logger = null): CardinalitySampler
    {
        return new CardinalitySampler(
            pdo: $this->pdo,
            logger: $logger ?? new \Psr\Log\NullLogger(),
            selectivityThreshold: 0.01,
            rowFloor: 10_000,
            distinctFloor: 10,
        );
    }

    /** @return list<array<string, mixed>> */
    private function readEvents($stream): array
    {
        rewind($stream);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($stream))));
        return array_map(
            static fn (string $l) => json_decode($l, true, flags: JSON_THROW_ON_ERROR),
            $lines,
        );
    }
}
