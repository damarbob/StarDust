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
use Closure;
use PDOException;
use StarDust\Support\RetryableLockFailure;

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
    /** @var Closure(int): void */
    private readonly Closure $sleepFn;

    /**
     * @param callable(int):void|null $sleepFn injected for tests; defaults to `usleep`
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly DeleteCheckpointRepository $repository,
        private readonly DeletePurgeExecutor $executor,
        private readonly int $chunkSize,
        private readonly int $lockRetryBudget = 3,
        private readonly int $retryDelayMicros = 0,
        ?callable $sleepFn = null,
    ) {
        $this->sleepFn = $sleepFn !== null
            ? Closure::fromCallable($sleepFn)
            : static function (int $micros): void {
                if ($micros > 0) {
                    usleep($micros);
                }
            };
    }

    /**
     * One chunk per tick, with a bounded in-tick retry on InnoDB lock
     * failures.
     *
     * The budget wraps the whole claim-plus-chunk transaction rather
     * than living in the executor, which does not own the transaction —
     * the same placement `ModelPurgeWorkSource` uses.
     *
     * **On exhaustion this returns `LOCK_WAIT` rather than rethrowing.**
     * A lock failure rolls the transaction back whole, and this source's
     * cursor lives on a row that transaction owns, so the chunk is
     * byte-for-byte re-executable and the next tick retries the
     * identical work. Letting the `PDOException` escape instead would
     * reach `PollLoop`, which deliberately does not catch — killing the
     * daemon over a condition that resolves itself when the contending
     * sweep finishes.
     *
     * `chunk_claimed` is emitted per *attempt*, correlated by
     * `chunk_correlation_id`; it is a claim event, not a commit one.
     */
    public function tickOne(string $chunkCorrelationId): TickOutcome
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->attemptOne($chunkCorrelationId);
            } catch (PDOException $e) {
                if (! RetryableLockFailure::matches($e)) {
                    throw $e;
                }

                if ($attempt >= $this->lockRetryBudget) {
                    $this->logger->warning('delete_purge lock wait', [
                        'event'          => 'lock_wait',
                        'source'         => 'reconciler',
                        'correlation_id' => $chunkCorrelationId,
                        'queue'          => 'delete_purge',
                        'attempts'       => $attempt,
                        'errno'          => RetryableLockFailure::errnoOf($e),
                    ]);

                    return TickOutcome::LOCK_WAIT;
                }

                $this->logger->warning('delete_purge lock retry', [
                    'event'          => 'deadlock_retry',
                    'source'         => 'reconciler',
                    'correlation_id' => $chunkCorrelationId,
                    'queue'          => 'delete_purge',
                    'attempt'        => $attempt,
                    'errno'          => RetryableLockFailure::errnoOf($e),
                ]);

                ($this->sleepFn)($this->retryDelayMicros);
            }
        }
    }

    private function attemptOne(string $chunkCorrelationId): TickOutcome
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
