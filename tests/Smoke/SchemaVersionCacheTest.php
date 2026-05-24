<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use StarDust\Clock\SystemClock;
use StarDust\Logging\StdoutNdjsonLogger;
use StarDust\Read\EntryQuery;

final class SchemaVersionCacheTest extends ReadPathTestCase
{
    public function testFirstReadEmitsCacheMissAndSubsequentReadDoesNot(): void
    {
        [$modelId] = $this->setupFilterableStringField();
        $this->seedEntry(1, $modelId, ['name' => 'a']);

        [$logger, $stream] = $this->newRecordingLogger();
        $reader = $this->reader($logger);

        // First read populates the cache → cache_miss event.
        $reader->read(new EntryQuery(tenantId: 1, modelId: $modelId));
        $afterFirst = $this->collectEvents($stream, 'cache_miss');
        self::assertCount(1, $afterFirst, 'first read must emit one cache_miss');
        self::assertSame('api', $afterFirst[0]['source']);
        self::assertSame($modelId, $afterFirst[0]['model_id']);
        self::assertNull($afterFirst[0]['cached_version']);

        // Second read with no registry mutation → must hit cache.
        $reader->read(new EntryQuery(tenantId: 1, modelId: $modelId));
        $afterSecond = $this->collectEvents($stream, 'cache_miss');
        self::assertCount(1, $afterSecond, 'second read must not emit cache_miss');
    }

    public function testRegistryMutationTriggersCacheRefresh(): void
    {
        [$modelId] = $this->setupFilterableStringField();
        $this->seedEntry(1, $modelId, ['name' => 'a']);

        [$logger, $stream] = $this->newRecordingLogger();
        $reader = $this->reader($logger);

        $reader->read(new EntryQuery(tenantId: 1, modelId: $modelId));
        self::assertCount(1, $this->collectEvents($stream, 'cache_miss'));

        // Simulate a registry write by bumping the version directly
        // (in production this happens inside the same transaction as
        // every page-provisioning / slot-reservation event).
        $this->pdo->exec('UPDATE stardust_schema_version SET version = version + 1 WHERE id = 1');

        $reader->read(new EntryQuery(tenantId: 1, modelId: $modelId));
        $events = $this->collectEvents($stream, 'cache_miss');
        self::assertCount(2, $events, 'version bump must invalidate cache');
        self::assertNotNull($events[1]['cached_version']);
        self::assertGreaterThan($events[1]['cached_version'], $events[1]['live_version']);
    }

    /**
     * @return array{0: StdoutNdjsonLogger, 1: resource}
     */
    private function newRecordingLogger(): array
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        return [new StdoutNdjsonLogger(new SystemClock(), $stream), $stream];
    }

    /**
     * @param resource $stream
     * @return list<array<string, mixed>>
     */
    private function collectEvents($stream, string $eventName): array
    {
        rewind($stream);
        $raw = (string) stream_get_contents($stream);
        $records = [];
        foreach (explode("\n", trim($raw)) as $line) {
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded) && ($decoded['event'] ?? null) === $eventName) {
                $records[] = $decoded;
            }
        }
        return $records;
    }
}
