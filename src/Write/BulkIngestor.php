<?php

declare(strict_types=1);

namespace StarDust\Write;

use PDO;
use Psr\Log\LoggerInterface;
use StarDust\Exception\PayloadTooLargeException;
use Throwable;

/**
 * Phase 3 synchronous chunked bulk-ingest entry point.
 *
 * Per ADR 0011:
 *   - Up to 1 000 entities per call. Anything larger throws
 *     {@see PayloadTooLargeException} carrying a pointer to the
 *     async path ({@see BulkIngestSubmitter}).
 *   - The batch is split into chunks of `BulkIngestOptions::$chunkSize`
 *     (default 500). Each chunk runs inside its own database
 *     transaction so InnoDB lock duration, undo-log growth, and
 *     replication lag stay bounded.
 *   - Failure in chunk N rolls back chunk N entirely. Chunks 1…N-1
 *     stay committed; chunks N+1…end still execute.
 *   - The inter-chunk delay is applied **between** chunks, not before
 *     the first or after the last (implementation_phases.md §3).
 *
 * Structured-log events (closed vocabulary, ADR 0020):
 *   - `bulk_chunk_committed`   (source: `bulk_api`)
 *   - `bulk_chunk_rolled_back` (source: `bulk_api`)
 *   - `payload_too_large`      (source: `bulk_api`) — emitted before
 *                                the throw on oversized payloads.
 *
 * Per-entity `entry_written` and `exhaustion_fallback` events are
 * NOT emitted by the bulk path — the chunk-level events carry the
 * relevant manifest. The Phase 5 Reconciler will emit its own per-
 * entity events when it drains the backfill queue.
 */
final class BulkIngestor
{
    public const SYNC_THRESHOLD = 1000;

    /** @var callable(int): void Sleep function for tests; defaults to usleep(). */
    private $sleepFn;

    public function __construct(
        private readonly PDO $pdo,
        private readonly EntryWriter $entryWriter,
        private readonly LoggerInterface $logger,
        ?callable $sleepFn = null,
    ) {
        $this->sleepFn = $sleepFn ?? static function (int $micros): void {
            if ($micros > 0) {
                usleep($micros);
            }
        };
    }

    /**
     * @param list<EntryPayload> $payloads
     */
    public function ingest(array $payloads, ?BulkIngestOptions $options = null): BulkIngestResult
    {
        $options ??= new BulkIngestOptions();
        $count = count($payloads);

        if ($count > self::SYNC_THRESHOLD) {
            $this->logger->info('payload too large for sync bulk ingest', [
                'event'        => 'payload_too_large',
                'source'       => 'bulk_api',
                'entry_count'  => $count,
                'threshold'    => self::SYNC_THRESHOLD,
            ]);
            throw new PayloadTooLargeException(
                'Synchronous bulk ingest accepts at most ' . self::SYNC_THRESHOLD
                . " entities per call; got {$count}. Use submitBulkWrite() for async submission."
            );
        }

        // Validate tenant_id on every payload up-front so a forged
        // record at index 47 doesn't burn 46 chunks first.
        foreach ($payloads as $p) {
            TenantId::assertValid($p->tenantId);
        }

        if ($count === 0) {
            return new BulkIngestResult(chunks: [], entriesCommitted: 0);
        }

        $chunks = array_chunk($payloads, $options->chunkSize);
        $chunkCount = count($chunks);

        $results = [];
        $totalCommitted = 0;

        foreach ($chunks as $i => $chunk) {
            $result = $this->processChunk($i, $chunk);
            $results[] = $result;
            if ($result->outcome === BulkChunkResult::OUTCOME_COMMITTED) {
                $totalCommitted += count($result->entryIds);
            }

            $isLast = ($i === $chunkCount - 1);
            if (!$isLast && $options->interChunkDelayMicros > 0) {
                ($this->sleepFn)($options->interChunkDelayMicros);
            }
        }

        return new BulkIngestResult(chunks: $results, entriesCommitted: $totalCommitted);
    }

    /**
     * @param list<EntryPayload> $chunk
     */
    private function processChunk(int $index, array $chunk): BulkChunkResult
    {
        $chunkSize = count($chunk);
        $entryIds = [];

        $this->pdo->beginTransaction();
        try {
            foreach ($chunk as $payload) {
                $result = $this->entryWriter->writeWithinTransaction($payload);
                $entryIds[] = $result->entryId;
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            $failureReason = $e::class . ': ' . $e->getMessage();

            $this->logger->info('bulk chunk rolled back', [
                'event'          => 'bulk_chunk_rolled_back',
                'source'         => 'bulk_api',
                'chunk_index'    => $index,
                'chunk_size'     => $chunkSize,
                'failure_reason' => $failureReason,
            ]);

            return new BulkChunkResult(
                chunkIndex: $index,
                chunkSize: $chunkSize,
                outcome: BulkChunkResult::OUTCOME_ROLLED_BACK,
                entryIds: [],
                failureReason: $failureReason,
            );
        }

        $this->logger->info('bulk chunk committed', [
            'event'         => 'bulk_chunk_committed',
            'source'        => 'bulk_api',
            'chunk_index'   => $index,
            'chunk_size'    => $chunkSize,
            'entry_id_first' => $entryIds[0] ?? null,
            'entry_id_last'  => $entryIds[count($entryIds) - 1] ?? null,
        ]);

        return new BulkChunkResult(
            chunkIndex: $index,
            chunkSize: $chunkSize,
            outcome: BulkChunkResult::OUTCOME_COMMITTED,
            entryIds: $entryIds,
            failureReason: null,
        );
    }
}
