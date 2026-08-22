<?php

declare(strict_types=1);

namespace StarDust\Rename;

use DateTimeZone;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Reconciler\ReconcilerWorkSource;
use StarDust\Reconciler\TickOutcome;
use Throwable;

/**
 * Fourth Reconciler work source: drains ADR 0036 rename backfills.
 *
 * Markedly smaller than {@see \StarDust\Retype\RetypeBackfillWorkSource}
 * because a rename touches no slot — there is no reserver, no sampler,
 * no promotion, and **no `CAPACITY_WAIT` path**, since a rename can
 * never be blocked on slot inventory. It rewrites `entry_data.fields`
 * and nothing else.
 */
final class RenameBackfillWorkSource implements ReconcilerWorkSource
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly RenameCheckpointRepository $repository,
        private readonly RenameBackfillExecutor $executor,
        private readonly int $chunkSize,
    ) {
    }

    public function tickOne(string $chunkCorrelationId): TickOutcome
    {
        $now = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $this->pdo->beginTransaction();

        try {
            $checkpoint = $this->repository->loadOneClaimable();
            if ($checkpoint === null) {
                $this->pdo->commit();
                return TickOutcome::IDLE;
            }

            $this->logger->info('rename chunk claimed', [
                'event'          => 'chunk_claimed',
                'source'         => 'reconciler',
                'correlation_id' => $chunkCorrelationId,
                'queue'          => 'rename_backfill',
                'field_id'       => $checkpoint->fieldId,
                'tenant_id'      => $checkpoint->tenantId,
                'cursor'         => $checkpoint->lastProcessedId,
            ]);

            $result = $this->executor->processChunk($checkpoint, $this->chunkSize);

            if ($result->isFinalChunk) {
                // These three must commit together. Clearing
                // previous_name is what retires the read/write fallback,
                // and the version bump is what makes cached snapshots
                // notice. A reader refreshing between them would lose
                // the fallback while un-migrated rows still existed.
                $this->repository->markCompleted($checkpoint->id, $result->newCursor, $now);
                $this->clearPreviousName($checkpoint->fieldId, $now);
                $this->bumpSchemaVersion($now);
            } else {
                $this->repository->advance($checkpoint->id, $result->newCursor, $now);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // Post-commit: events describe committed state only.
        $this->logger->info('rename chunk complete', [
            'event'          => 'chunk_complete',
            'source'         => 'reconciler',
            'correlation_id' => $chunkCorrelationId,
            'queue'          => 'rename_backfill',
            'field_id'       => $checkpoint->fieldId,
            'tenant_id'      => $checkpoint->tenantId,
            'rows_scanned'   => $result->rowsScanned,
            'rows_rewritten' => $result->rowsRewritten,
            'final_chunk'    => $result->isFinalChunk,
        ]);

        if ($result->isFinalChunk) {
            $this->logger->info('field rename complete', [
                'event'          => 'rename_complete',
                'source'         => 'registry',
                'correlation_id' => $chunkCorrelationId,
                'tenant_id'      => $checkpoint->tenantId,
                'model_id'       => $checkpoint->modelId,
                'field_id'       => $checkpoint->fieldId,
                'old_name'       => $checkpoint->previousName,
                'new_name'       => $checkpoint->currentName,
            ]);
        }

        return TickOutcome::WORK_DONE;
    }

    private function clearPreviousName(int $fieldId, string $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE stardust_fields SET previous_name = NULL, updated_at = ? WHERE id = ?'
        );
        $stmt->execute([$now, $fieldId]);
    }

    private function bumpSchemaVersion(string $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE stardust_schema_version SET version = version + 1, updated_at = ? WHERE id = 1'
        );
        $stmt->execute([$now]);
    }
}
