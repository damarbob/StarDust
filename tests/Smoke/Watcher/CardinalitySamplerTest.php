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
