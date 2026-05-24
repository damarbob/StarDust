<?php

declare(strict_types=1);

namespace StarDust\Reconciler;

use PDO;
use Psr\Log\LoggerInterface;
use StarDust\Exception\EntryDataMissingException;
use StarDust\Exception\UncoercibleSlotValueException;
use StarDust\Write\BackfillExecutor;
use Throwable;

/**
 * Drains `stardust_sync_queue` one chunk at a time.
 *
 * Chunk shape (one transaction):
 *   1. `SELECT id, entry_id FROM stardust_sync_queue ORDER BY id
 *      LIMIT ? FOR UPDATE SKIP LOCKED` — claims a disjoint slice for
 *      this worker (ADR 0008 multi-worker safety).
 *   2. Emits `chunk_claimed`.
 *   3. For each row, calls {@see BackfillExecutor::backfill()}.
 *      - On {@see EntryDataMissingException}: writes a DLQ row with
 *        `reason='missing_entry_data'`, deletes the queue row, marks
 *        the chunk partial.
 *      - On {@see UncoercibleSlotValueException}: DLQ with
 *        `reason='schema_incompatibility'`, deletes the queue row.
 *      - On any other Throwable: DLQ with `reason='other'`, deletes
 *        the queue row.
 *      - On {@see \StarDust\Write\BackfillResult::hasStillUnmapped()}:
 *        emits `capacity_wait`, ROLLS BACK the whole chunk so every
 *        claimed row goes back on the queue, returns CAPACITY_WAIT.
 *   4. Deletes successfully-backfilled queue rows.
 *   5. Emits `chunk_complete` (or `chunk_partial` if any DLQ rows
 *      were inserted) and commits.
 *
 * `chunk_partial` and `chunk_complete` are alternatives, not
 * cumulative — every successful tick emits exactly one of them.
 */
final class SyncQueueWorkSource implements ReconcilerWorkSource
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LoggerInterface $logger,
        private readonly BackfillExecutor $backfillExecutor,
        private readonly DlqWriter $dlqWriter,
        private readonly int $chunkSize,
    ) {
    }

    public function tickOne(string $chunkCorrelationId): TickOutcome
    {
        $this->pdo->beginTransaction();
        try {
            $rows = $this->claimChunk();
            if ($rows === []) {
                $this->pdo->commit();
                return TickOutcome::IDLE;
            }

            $this->logger->info('sync_queue chunk claimed', [
                'event'          => 'chunk_claimed',
                'source'         => 'reconciler',
                'correlation_id' => $chunkCorrelationId,
                'queue'          => 'sync_queue',
                'rows_claimed'   => count($rows),
            ]);

            $processedQueueIds = [];
            $dlqCount = 0;
            $successCount = 0;
            $capacityWait = false;

            foreach ($rows as $row) {
                $queueId = (int) $row['id'];
                $entryId = (int) $row['entry_id'];

                try {
                    $result = $this->backfillExecutor->backfill($entryId);
                } catch (EntryDataMissingException $e) {
                    $this->writeDlq(
                        chunkCorrelationId: $chunkCorrelationId,
                        entryId: $entryId,
                        reason: 'missing_entry_data',
                        errorMessage: $e->getMessage(),
                    );
                    $processedQueueIds[] = $queueId;
                    $dlqCount++;
                    continue;
                } catch (UncoercibleSlotValueException $e) {
                    $this->writeDlq(
                        chunkCorrelationId: $chunkCorrelationId,
                        entryId: $entryId,
                        reason: 'schema_incompatibility',
                        errorMessage: $e->getMessage(),
                    );
                    $processedQueueIds[] = $queueId;
                    $dlqCount++;
                    continue;
                } catch (Throwable $e) {
                    $this->writeDlq(
                        chunkCorrelationId: $chunkCorrelationId,
                        entryId: $entryId,
                        reason: 'other',
                        errorMessage: $e->getMessage(),
                    );
                    $processedQueueIds[] = $queueId;
                    $dlqCount++;
                    continue;
                }

                if ($result->hasStillUnmapped()) {
                    $capacityWait = true;
                    break;
                }

                $processedQueueIds[] = $queueId;
                $successCount++;
            }

            if ($capacityWait) {
                $this->pdo->rollBack();
                $this->logger->warning('reconciler capacity wait', [
                    'event'          => 'capacity_wait',
                    'source'         => 'reconciler',
                    'correlation_id' => $chunkCorrelationId,
                    'queue'          => 'sync_queue',
                    'rows_claimed'   => count($rows),
                ]);
                return TickOutcome::CAPACITY_WAIT;
            }

            if ($processedQueueIds !== []) {
                $this->deleteQueueRows($processedQueueIds);
            }

            $event = $dlqCount > 0 ? 'chunk_partial' : 'chunk_complete';
            $this->logger->info('sync_queue chunk processed', [
                'event'          => $event,
                'source'         => 'reconciler',
                'correlation_id' => $chunkCorrelationId,
                'queue'          => 'sync_queue',
                'rows_processed' => $successCount,
                'rows_dlq'       => $dlqCount,
            ]);

            $this->pdo->commit();
            return TickOutcome::WORK_DONE;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return list<array{id: int|string, entry_id: int|string}> */
    private function claimChunk(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, entry_id FROM stardust_sync_queue'
            . ' ORDER BY id LIMIT ? FOR UPDATE SKIP LOCKED'
        );
        $stmt->bindValue(1, $this->chunkSize, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function writeDlq(
        string $chunkCorrelationId,
        int $entryId,
        string $reason,
        string $errorMessage,
    ): void {
        // Best-effort tenant/model resolution. When the entry_data row
        // is missing the source row is gone, so we record `0/0`; the
        // chunk_correlation_id still ties the row back to the events.
        $stmt = $this->pdo->prepare(
            'SELECT tenant_id, model_id FROM entry_data WHERE id = ?'
        );
        $stmt->execute([$entryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $tenantId = $row === false ? 0 : (int) $row['tenant_id'];
        $modelId  = $row === false ? 0 : (int) $row['model_id'];

        $this->dlqWriter->quarantine(new DlqEntry(
            source: 'sync_queue',
            entryId: $entryId,
            tenantId: $tenantId,
            modelId: $modelId,
            reason: $reason,
            errorMessage: $errorMessage,
            chunkCorrelationId: $chunkCorrelationId,
        ));
    }

    /** @param list<int> $queueIds */
    private function deleteQueueRows(array $queueIds): void
    {
        $placeholders = implode(',', array_fill(0, count($queueIds), '?'));
        $stmt = $this->pdo->prepare(
            "DELETE FROM stardust_sync_queue WHERE id IN ({$placeholders})"
        );
        $stmt->execute($queueIds);
    }
}
