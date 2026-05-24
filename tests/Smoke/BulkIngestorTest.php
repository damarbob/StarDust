<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use Psr\Log\NullLogger;
use StarDust\Clock\SystemClock;
use StarDust\Exception\InvalidTenantIdException;
use StarDust\Exception\PayloadTooLargeException;
use StarDust\Logging\StdoutNdjsonLogger;
use StarDust\Write\BulkChunkResult;
use StarDust\Write\BulkIngestOptions;
use StarDust\Write\BulkIngestor;
use StarDust\Write\EntryPayload;
use StarDust\Write\EntryWriter;
use StarDust\Write\SlotRowUpserter;

/**
 * Phase 3 BulkIngestor smoke suite.
 */
final class BulkIngestorTest extends WritePathTestCase
{
    /**
     * @param callable(int):void|null $sleepFn
     */
    private function newIngestor(?callable $sleepFn = null, ?StdoutNdjsonLogger $logger = null): BulkIngestor
    {
        $log = $logger ?? new NullLogger();
        $writer = new EntryWriter(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: $log,
            slotRowUpserter: new SlotRowUpserter($this->pdo),
        );
        return new BulkIngestor(
            pdo: $this->pdo,
            entryWriter: $writer,
            logger: $log,
            sleepFn: $sleepFn,
        );
    }

    /** Exit criterion: N entries in chunks of K produce N entry_data rows, no dupes. */
    public function testNEntriesInKChunksProduceNRowsNoDuplicates(): void
    {
        [$modelId, $_fieldId, $_pageId, $fieldName] = $this->setupModelWithReservedField(1, 'string');

        $payloads = [];
        for ($i = 0; $i < 7; $i++) {
            $payloads[] = new EntryPayload(
                tenantId: 1,
                modelId: $modelId,
                fields: [$fieldName => "row-{$i}"],
            );
        }

        $result = $this->newIngestor()->ingest($payloads, new BulkIngestOptions(chunkSize: 3));

        self::assertSame(7, $result->entriesCommitted);
        self::assertCount(3, $result->chunks, 'Chunks of 3 over 7 entities yield 3 chunks (3+3+1).');

        $entryDataCount = (int) $this->pdo->query('SELECT COUNT(*) FROM entry_data')->fetchColumn();
        self::assertSame(7, $entryDataCount);

        $distinctIds = (int) $this->pdo->query('SELECT COUNT(DISTINCT id) FROM entry_data')->fetchColumn();
        self::assertSame(7, $distinctIds, 'No duplicate entry_data rows.');
    }

    /** Exit criterion: inter-chunk delay applied between chunks only. */
    public function testInterChunkDelayAppliedBetweenChunksOnly(): void
    {
        [$modelId, $_fieldId, $_pageId, $fieldName] = $this->setupModelWithReservedField(1, 'string');

        $sleepCalls = [];
        $sleepFn = static function (int $micros) use (&$sleepCalls): void {
            $sleepCalls[] = $micros;
        };

        $payloads = [];
        for ($i = 0; $i < 6; $i++) {
            $payloads[] = new EntryPayload(
                tenantId: 1,
                modelId: $modelId,
                fields: [$fieldName => "row-{$i}"],
            );
        }

        // 6 entities, chunkSize 2 → 3 chunks → 2 inter-chunk delays.
        $this->newIngestor(sleepFn: $sleepFn)->ingest(
            $payloads,
            new BulkIngestOptions(chunkSize: 2, interChunkDelayMicros: 1234),
        );

        self::assertSame([1234, 1234], $sleepCalls, 'Sleep must fire exactly N-1 times for N chunks.');
    }

    /** Inter-chunk delay is skipped when set to 0 even with multiple chunks. */
    public function testZeroInterChunkDelayMeansNoSleepCalls(): void
    {
        [$modelId, $_fieldId, $_pageId, $fieldName] = $this->setupModelWithReservedField(1, 'string');

        $sleepCalls = [];
        $sleepFn = static function (int $micros) use (&$sleepCalls): void {
            $sleepCalls[] = $micros;
        };

        $payloads = [
            new EntryPayload(1, $modelId, [$fieldName => 'a']),
            new EntryPayload(1, $modelId, [$fieldName => 'b']),
            new EntryPayload(1, $modelId, [$fieldName => 'c']),
        ];

        $this->newIngestor(sleepFn: $sleepFn)->ingest(
            $payloads,
            new BulkIngestOptions(chunkSize: 1, interChunkDelayMicros: 0),
        );

        self::assertSame([], $sleepCalls, 'Zero-delay must not call sleep at all.');
    }

    /** Exit criterion: sync > 1 000 entities throws PayloadTooLargeException. */
    public function testRejectsOversizedSyncPayload(): void
    {
        [$modelId, $_fieldId, $_pageId, $fieldName] = $this->setupModelWithReservedField(1, 'string');

        $payloads = [];
        for ($i = 0; $i < BulkIngestor::SYNC_THRESHOLD + 1; $i++) {
            $payloads[] = new EntryPayload(1, $modelId, [$fieldName => "row-{$i}"]);
        }

        $this->expectException(PayloadTooLargeException::class);
        $this->newIngestor()->ingest($payloads);
    }

    /** Empty input is a no-op, not an error. */
    public function testEmptyInputCommitsZeroRows(): void
    {
        $result = $this->newIngestor()->ingest([]);
        self::assertSame(0, $result->entriesCommitted);
        self::assertSame([], $result->chunks);
    }

    /** tenant_id validation rejects on any payload before any chunk runs. */
    public function testRejectsAnyPayloadWithInvalidTenantId(): void
    {
        [$modelId, $_fieldId, $_pageId, $fieldName] = $this->setupModelWithReservedField(1, 'string');

        $payloads = [
            new EntryPayload(1, $modelId, [$fieldName => 'a']),
            new EntryPayload(0, $modelId, [$fieldName => 'b']), // invalid
        ];

        $entryCountBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM entry_data')->fetchColumn();

        $this->expectException(InvalidTenantIdException::class);
        try {
            $this->newIngestor()->ingest($payloads);
        } finally {
            $entryCountAfter = (int) $this->pdo->query('SELECT COUNT(*) FROM entry_data')->fetchColumn();
            self::assertSame($entryCountBefore, $entryCountAfter, 'No row may land before validation completes.');
        }
    }

    /** ADR 0020 `bulk_chunk_committed` event lands on the structured-log stream. */
    public function testEmitsChunkCommittedEvents(): void
    {
        [$modelId, $_fieldId, $_pageId, $fieldName] = $this->setupModelWithReservedField(1, 'string');

        $stream = fopen('php://memory', 'r+');
        $ingestor = $this->newIngestor(
            logger: new StdoutNdjsonLogger(new SystemClock(), $stream),
        );

        $payloads = [
            new EntryPayload(1, $modelId, [$fieldName => 'a']),
            new EntryPayload(1, $modelId, [$fieldName => 'b']),
        ];

        $ingestor->ingest($payloads, new BulkIngestOptions(chunkSize: 1));

        rewind($stream);
        $records = array_values(array_filter(explode("\n", (string) stream_get_contents($stream))));

        // 2 chunks → 2 `bulk_chunk_committed` events.
        $chunkEvents = array_values(array_filter(
            array_map(static fn(string $r): array => json_decode($r, true) ?? [], $records),
            static fn(array $d): bool => ($d['event'] ?? '') === 'bulk_chunk_committed',
        ));
        self::assertCount(2, $chunkEvents);

        self::assertSame('bulk_api', $chunkEvents[0]['source'] ?? null);
        self::assertSame(0, $chunkEvents[0]['chunk_index'] ?? null);
        self::assertSame(1, $chunkEvents[0]['chunk_size'] ?? null);
    }

    /** ADR 0020 `payload_too_large` event fires before the exception. */
    public function testEmitsPayloadTooLargeEvent(): void
    {
        [$modelId, $_fieldId, $_pageId, $fieldName] = $this->setupModelWithReservedField(1, 'string');

        $stream = fopen('php://memory', 'r+');
        $ingestor = $this->newIngestor(
            logger: new StdoutNdjsonLogger(new SystemClock(), $stream),
        );

        $payloads = [];
        for ($i = 0; $i < BulkIngestor::SYNC_THRESHOLD + 1; $i++) {
            $payloads[] = new EntryPayload(1, $modelId, [$fieldName => "x{$i}"]);
        }

        try {
            $ingestor->ingest($payloads);
        } catch (PayloadTooLargeException) {
            // expected
        }

        rewind($stream);
        $records = array_values(array_filter(explode("\n", (string) stream_get_contents($stream))));
        self::assertNotEmpty($records);

        $decoded = json_decode($records[0], true);
        self::assertSame('payload_too_large', $decoded['event'] ?? null);
        self::assertSame('bulk_api', $decoded['source'] ?? null);
        self::assertSame(BulkIngestor::SYNC_THRESHOLD + 1, $decoded['entry_count'] ?? null);
        self::assertSame(BulkIngestor::SYNC_THRESHOLD, $decoded['threshold'] ?? null);
    }

    /** Chunk transactional integrity: a failing chunk doesn't leave partial commits. */
    public function testChunkRollbackIsAtomicAcrossEntities(): void
    {
        [$modelId, $_fieldId, $_pageId, $fieldName] = $this->setupModelWithReservedField(1, 'int');

        // Two entries per chunk; the second one in chunk 1 has a
        // non-coercible value for the int field, which throws inside
        // EntryWriter and aborts the chunk's transaction.
        $payloads = [
            new EntryPayload(1, $modelId, [$fieldName => 1]),
            new EntryPayload(1, $modelId, [$fieldName => 'NOT-AN-INT']),
            new EntryPayload(1, $modelId, [$fieldName => 3]),
            new EntryPayload(1, $modelId, [$fieldName => 4]),
        ];

        $result = $this->newIngestor()->ingest($payloads, new BulkIngestOptions(chunkSize: 2));

        // Chunk 0 (entries 0,1) rolls back: no commits.
        // Chunk 1 (entries 2,3) succeeds: 2 commits.
        self::assertCount(2, $result->chunks);
        self::assertSame(BulkChunkResult::OUTCOME_ROLLED_BACK, $result->chunks[0]->outcome);
        self::assertSame(BulkChunkResult::OUTCOME_COMMITTED, $result->chunks[1]->outcome);
        self::assertSame(2, $result->entriesCommitted);

        $entryDataCount = (int) $this->pdo->query('SELECT COUNT(*) FROM entry_data')->fetchColumn();
        self::assertSame(2, $entryDataCount, 'Only chunk 1\'s entries should be durable.');
    }
}
