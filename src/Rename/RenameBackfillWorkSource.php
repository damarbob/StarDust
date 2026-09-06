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
use Closure;
use PDOException;
use StarDust\Support\RetryableLockFailure;

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
    /** @var Closure(int): void */
    private readonly Closure $sleepFn;

    /**
     * @param callable(int):void|null $sleepFn injected for tests; defaults to `usleep`
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly RenameCheckpointRepository $repository,
        private readonly RenameBackfillExecutor $executor,
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
                    $this->logger->warning('rename_backfill lock wait', [
                        'event'          => 'lock_wait',
                        'source'         => 'reconciler',
                        'correlation_id' => $chunkCorrelationId,
                        'queue'          => 'rename_backfill',
                        'attempts'       => $attempt,
                        'errno'          => RetryableLockFailure::errnoOf($e),
                    ]);

                    return TickOutcome::LOCK_WAIT;
                }

                $this->logger->warning('rename_backfill lock retry', [
                    'event'          => 'deadlock_retry',
                    'source'         => 'reconciler',
                    'correlation_id' => $chunkCorrelationId,
                    'queue'          => 'rename_backfill',
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
            // The lifecycle id, not the chunk's: this event closes the
            // operation `rename_started` opened, possibly hours and
            // certainly many chunks ago, and ADR 0020 requires the two
            // join. The chunk id rides alongside under its own key so the
            // tick that finished the drain is still findable — the same
            // name `stardust_reconciler_dlq` uses for the same purpose.
            // The `??` covers a checkpoint opened before the column
            // existed, which keeps the previous behaviour rather than
            // emitting null.
            $this->logger->info('field rename complete', [
                'event'                => 'rename_complete',
                'source'               => 'registry',
                'correlation_id'       => $checkpoint->correlationId ?? $chunkCorrelationId,
                'chunk_correlation_id' => $chunkCorrelationId,
                'tenant_id'            => $checkpoint->tenantId,
                'model_id'             => $checkpoint->modelId,
                'field_id'             => $checkpoint->fieldId,
                'old_name'             => $checkpoint->previousName,
                'new_name'             => $checkpoint->currentName,
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
