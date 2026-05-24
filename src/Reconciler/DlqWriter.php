<?php

declare(strict_types=1);

namespace StarDust\Reconciler;

use DateTimeZone;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Inserts one `stardust_reconciler_dlq` row and emits the matching
 * `dlq_inserted` structured-log event (ADR 0018, ADR 0020).
 *
 * Caller owns the surrounding transaction; this class issues no
 * `beginTransaction()` / `commit()` so the DLQ INSERT is part of the
 * same atomic chunk as the survivors' UPSERTs and the queue DELETEs.
 *
 * The `dlq_inserted` event carries the same `chunk_correlation_id`
 * persisted in the column so operators can JOIN log records to DLQ
 * rows (Phase 5 exit criterion §5 line 283).
 */
final class DlqWriter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function quarantine(DlqEntry $entry): void
    {
        $now = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'INSERT INTO stardust_reconciler_dlq'
            . ' (source, entry_id, tenant_id, model_id, reason,'
            . '  error_message, failed_at, retry_count, chunk_correlation_id)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)'
        );
        $stmt->execute([
            $entry->source,
            $entry->entryId,
            $entry->tenantId,
            $entry->modelId,
            $entry->reason,
            $entry->errorMessage,
            $now,
            $entry->chunkCorrelationId,
        ]);

        $this->logger->warning('reconciler dlq inserted', [
            'event'                => 'dlq_inserted',
            'source'               => 'reconciler',
            'correlation_id'       => $entry->chunkCorrelationId,
            'dlq_source'           => $entry->source,
            'entry_id'             => $entry->entryId,
            'tenant_id'            => $entry->tenantId,
            'model_id'             => $entry->modelId,
            'reason'               => $entry->reason,
        ]);
    }
}
