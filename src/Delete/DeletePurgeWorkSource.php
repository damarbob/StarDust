<?php

declare(strict_types=1);

namespace StarDust\Delete;

use DateTimeZone;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Reconciler\ReconcilerWorkSource;
use StarDust\Reconciler\TickOutcome;
use Throwable;

/**
 * Fifth Reconciler work source: drains ADR 0037 field deletions.
 *
 * The smallest of the five. A deletion touches no slot on this path —
 * the initiator already tombstoned it — so there is no reserver, no
 * sampler, no promotion, and **no `CAPACITY_WAIT`**: a purge can never
 * be blocked on slot inventory. It rewrites `entry_data.fields`, then
 * removes the registry row.
 */
final class DeletePurgeWorkSource implements ReconcilerWorkSource
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly DeleteCheckpointRepository $repository,
        private readonly DeletePurgeExecutor $executor,
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

            $this->logger->info('delete chunk claimed', [
                'event'          => 'chunk_claimed',
                'source'         => 'reconciler',
                'correlation_id' => $chunkCorrelationId,
                'queue'          => 'delete_purge',
                'field_id'       => $checkpoint->fieldId,
                'tenant_id'      => $checkpoint->tenantId,
                'cursor'         => $checkpoint->lastProcessedId,
            ]);

            $result = $this->executor->processChunk($checkpoint, $this->chunkSize);

            if ($result->isFinalChunk) {
                // These three must commit together. The field row is
                // the last thing anything could recover the purge's
                // coordinates from, so dropping it while the checkpoint
                // survived would strand a `running` row whose INNER
                // JOIN can no longer resolve — the exact orphan this
                // feature exists to eliminate. The version bump is what
                // makes cached snapshots notice the row is gone rather
                // than merely severed.
                $this->deleteFieldRow($checkpoint->fieldId);
                $this->repository->delete($checkpoint->id);
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
        $this->logger->info('delete chunk complete', [
            'event'          => 'chunk_complete',
            'source'         => 'reconciler',
            'correlation_id' => $chunkCorrelationId,
            'queue'          => 'delete_purge',
            'field_id'       => $checkpoint->fieldId,
            'tenant_id'      => $checkpoint->tenantId,
            'rows_scanned'   => $result->rowsScanned,
            'rows_purged'    => $result->rowsPurged,
            'final_chunk'    => $result->isFinalChunk,
        ]);

        if ($result->isFinalChunk) {
            $this->logger->info('field deletion complete', [
                'event'          => 'delete_complete',
                'source'         => 'registry',
                'correlation_id' => $chunkCorrelationId,
                'tenant_id'      => $checkpoint->tenantId,
                'model_id'       => $checkpoint->modelId,
                'field_id'       => $checkpoint->fieldId,
                'field_name'     => $checkpoint->fieldName,
            ]);
        }

        return TickOutcome::WORK_DONE;
    }

    /**
     * The hard delete the whole lifecycle has been working towards.
     *
     * Succeeds because `fk_slot_assignments_field` was released by the
     * initiator's two-step tombstone — the slot row survives as
     * sweepable inventory with `field_id` already null, and the
     * Liberator reclaims it without ever joining `stardust_fields`.
     */
    private function deleteFieldRow(int $fieldId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM stardust_fields WHERE id = ?');
        $stmt->execute([$fieldId]);
    }

    private function bumpSchemaVersion(string $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE stardust_schema_version SET version = version + 1, updated_at = ? WHERE id = 1'
        );
        $stmt->execute([$now]);
    }
}
