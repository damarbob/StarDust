<?php

declare(strict_types=1);

namespace StarDust\Delete;

use Closure;
use DateTimeZone;
use PDO;
use PDOException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Reconciler\ReconcilerWorkSource;
use StarDust\Reconciler\TickOutcome;
use StarDust\Support\RetryableLockFailure;
use Throwable;

/**
 * Sixth Reconciler work source: drains ADR 0038 model deletions.
 *
 * The most destructive thing in the engine. It deletes `entry_data` rows
 * outright — cascading into every extension page the model touches — and
 * its final chunk drops the `stardust_models` row, taking every field
 * with it through `fk_fields_model`.
 *
 * ## No `CAPACITY_WAIT`
 *
 * The purge touches no slot inventory — the initiator already tombstoned
 * everything — so it can never be blocked on capacity. `WORK_DONE` or
 * `IDLE` only.
 *
 * ## No dead-letter path, by design
 *
 * ADR 0018's quarantine means "commit the survivors and set this one
 * aside". Setting aside a model-purge chunk means leaving `entry_data`
 * rows with a dangling `model_id` — the exact orphan this feature exists
 * to eliminate. So a chunk commits whole or rolls back whole, and there
 * is deliberately nowhere for a poison row to go.
 *
 * That is precisely why the final chunk re-asserts severance before the
 * model DELETE (see {@see self::reassertSeverance()}), and why lock
 * failures are retried rather than skipped.
 */
final class ModelPurgeWorkSource implements ReconcilerWorkSource
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
        private readonly ModelDeleteCheckpointRepository $repository,
        private readonly ModelPurgeExecutor $executor,
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
     * One chunk per tick, with a bounded retry on lock failures.
     *
     * The budget wraps the whole claim-plus-chunk transaction rather than
     * living in the executor, which does not own the transaction.
     */
    public function tickOne(string $chunkCorrelationId): TickOutcome
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->attemptOne($chunkCorrelationId);
            } catch (PDOException $e) {
                if (! self::isRetryableLockFailure($e) || $attempt >= $this->lockRetryBudget) {
                    throw $e;
                }

                $this->logger->warning('model purge lock retry', [
                    'event'          => 'deadlock_retry',
                    'source'         => 'reconciler',
                    'correlation_id' => $chunkCorrelationId,
                    'queue'          => 'model_delete_purge',
                    'attempt'        => $attempt,
                    'errno'          => is_array($e->errorInfo) ? ($e->errorInfo[1] ?? null) : null,
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

            $this->logger->info('model purge chunk claimed', [
                'event'          => 'chunk_claimed',
                'source'         => 'reconciler',
                'correlation_id' => $chunkCorrelationId,
                'queue'          => 'model_delete_purge',
                'model_id'       => $checkpoint->modelId,
                'tenant_id'      => $checkpoint->tenantId,
                'cursor'         => $checkpoint->lastProcessedId,
            ]);

            $result = $this->executor->processChunk($checkpoint, $this->chunkSize);
            $fieldsDropped = 0;
            $finalising = $result->isFinalChunk;

            if ($finalising) {
                // `count($ids) < $chunkSize` is a hypothesis; this is the
                // proof. A write transaction that opened before severance
                // committed carries a pre-severance snapshot for its whole
                // life and can commit rows afterwards. `entry_data.id` is
                // auto-increment so those land ahead of the cursor and the
                // purge normally catches them — unless they commit after
                // what we thought was the last chunk. One indexed probe
                // turns a silent permanent orphan into an extra tick.
                if ($this->entriesRemain($checkpoint)) {
                    $finalising = false;
                } else {
                    $fieldsDropped = $this->reassertSeverance($checkpoint->modelId, $now);
                    $this->repository->delete($checkpoint->id);
                    $this->deleteModelRow($checkpoint->modelId);
                    $this->bumpSchemaVersion($now);
                }
            }

            if (! $finalising) {
                $this->repository->advance($checkpoint->id, $result->newCursor, $now);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->logger->info('model purge chunk complete', [
            'event'             => 'chunk_complete',
            'source'            => 'reconciler',
            'correlation_id'    => $chunkCorrelationId,
            'queue'             => 'model_delete_purge',
            'model_id'          => $checkpoint->modelId,
            'tenant_id'         => $checkpoint->tenantId,
            'rows_scanned'      => $result->rowsScanned,
            'rows_deleted'      => $result->rowsDeleted,
            'sync_rows_deleted' => $result->syncRowsDeleted,
            'final_chunk'       => $finalising,
            'fields_dropped'    => $fieldsDropped,
        ]);

        if ($finalising) {
            // The lifecycle id, not the chunk's — this closes the
            // operation `model_delete_started` opened. See the equivalent
            // note in RenameBackfillWorkSource for the full rationale and
            // for why the chunk id stays under its own key.
            $this->logger->info('model deletion complete', [
                'event'                => 'model_delete_complete',
                'source'               => 'registry',
                'correlation_id'       => $checkpoint->correlationId ?? $chunkCorrelationId,
                'chunk_correlation_id' => $chunkCorrelationId,
                'tenant_id'            => $checkpoint->tenantId,
                'model_id'             => $checkpoint->modelId,
                'fields_dropped'       => $fieldsDropped,
            ]);
        }

        return TickOutcome::WORK_DONE;
    }

    /**
     * Delegates to {@see RetryableLockFailure}; what is local to this
     * work source is what happens on exhaustion, not what counts as a
     * lock failure.
     *
     * Measured on MySQL 8.0.13: the purge and the Liberator contend over
     * the same `entry_slots_page_X` rows — the purge deleting them by
     * cascade, the Liberator nullifying a tombstoned column across them —
     * and in **both directions** the loser gets 1205, never 1213.
     *
     * **There is deliberately no gap path**, unlike the Liberator's
     * equivalent loop. Skipping a chunk would leave `entry_data` rows with
     * a dangling `model_id` forever, and because `entry_data` has no
     * foreign key to the registry the final `DELETE FROM stardust_models`
     * would still succeed — so nothing would ever notice. On budget
     * exhaustion this rethrows with the cursor untouched; the next tick
     * re-claims and retries the identical chunk, which is safe because a
     * lock failure rolls the transaction back whole.
     *
     * **That rethrow is why this source did not join the five that
     * return `TickOutcome::LOCK_WAIT`.** Their argument is that a
     * rolled-back chunk is re-claimable with nothing skipped, which is
     * true here too — but a soft outcome would make an unrecoverable
     * drain look like ordinary back-pressure, and this is the one drain
     * that destroys rows.
     */
    private static function isRetryableLockFailure(PDOException $e): bool
    {
        return RetryableLockFailure::matches($e);
    }

    private function entriesRemain(ModelDeleteCheckpoint $checkpoint): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM entry_data WHERE tenant_id = ? AND model_id = ? LIMIT 1'
        );
        $stmt->execute([$checkpoint->tenantId, $checkpoint->modelId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Idempotent severance sweep, run immediately before the model
     * DELETE. Returns the model's surviving field count for the event.
     *
     * A deliberate departure from ADR 0037's final chunk, which simply
     * trusts that nothing re-slotted the field. It can afford to: if it
     * is wrong, one row survives. Here, if it is wrong, the transaction
     * throws errno 1451 **after every earlier chunk has already committed
     * its deletes** — the entries gone, the chunk fetch now returning
     * nothing so every subsequent tick believes it is the final chunk,
     * and the work source rethrowing forever with no dead-letter route
     * out. That is a poison pill hot-looping on destroyed data.
     *
     * Bounded by field count — tens of rows — and a no-op in the healthy
     * case.
     */
    private function reassertSeverance(int $modelId, string $now): int
    {
        // Any slot still referencing one of this model's fields. NOTE: no
        // status predicate. A `tombstoned` row that still carries a
        // `field_id` re-breaks the cascade with the same errno 1451 —
        // measured — because `fk_slot_assignments_field` cares about the
        // column, not the status. The `field_id IS NULL` convention for
        // non-live rows is enforced by application code, not the schema.
        $stmt = $this->pdo->prepare(
            'SELECT sa.id FROM stardust_slot_assignments sa'
            . ' JOIN stardust_fields f ON f.id = sa.field_id'
            . ' WHERE f.model_id = ?'
            . ' FOR UPDATE'
        );
        $stmt->execute([$modelId]);

        $slotIds = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $slotIds[] = (int) $id;
        }

        if ($slotIds !== []) {
            $placeholders = implode(',', array_fill(0, count($slotIds), '?'));

            // Two steps, field_id first — the LiveSlotTombstoner order,
            // for the same two reasons: it releases the RESTRICT foreign
            // key inside this transaction, and it clears the functional
            // partial unique index before any status flip.
            $clear = $this->pdo->prepare(
                "UPDATE stardust_slot_assignments SET field_id = NULL, updated_at = ?"
                . " WHERE id IN ({$placeholders})"
            );
            $clear->execute(array_merge([$now], $slotIds));

            // COALESCE so an already-tombstoned row keeps its original
            // stamp — resetting it would push the slot back in the
            // Liberator's `ORDER BY tombstoned_at` reclaim order.
            //
            // ADR 0045: clear both sweep annotations, exactly as
            // `LiveSlotTombstoner` does — this is the engine's second
            // tombstone site, and a reset in only one of them recycles
            // stale cursors through the other. The status guard below is
            // what makes it safe: an already-`tombstoned` slot, which
            // the Liberator may be mid-sweep on, matches nothing here
            // and keeps the cursor that is correct for its own sweep.
            $tombstone = $this->pdo->prepare(
                'UPDATE stardust_slot_assignments'
                . " SET status = 'tombstoned',"
                . '     tombstoned_at = COALESCE(tombstoned_at, ?),'
                . '     updated_at = ?,'
                . '     sweep_cursor_id = NULL,'
                . '     sweep_gap_count = 0'
                . " WHERE id IN ({$placeholders})"
                . "   AND status IN ('assigned','backfilling','ready')"
            );
            $tombstone->execute(array_merge([$now, $now], $slotIds));
        }

        // Any field row that lost its marker or reacquired filterability.
        $refresh = $this->pdo->prepare(
            'UPDATE stardust_fields'
            . ' SET deleted_at = COALESCE(deleted_at, ?), is_filterable = 0, updated_at = ?'
            . ' WHERE model_id = ? AND (deleted_at IS NULL OR is_filterable = 1)'
        );
        $refresh->execute([$now, $now, $modelId]);

        // Any sibling checkpoint that appeared during the drain window.
        // Defence in depth: the cascade below would orphan it permanently,
        // because both sibling repositories recover the field id by INNER
        // JOIN on a substring of `job_name`.
        $fields = $this->pdo->prepare('SELECT id FROM stardust_fields WHERE model_id = ?');
        $fields->execute([$modelId]);

        $fieldIds = [];
        foreach ($fields->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $fieldIds[] = (int) $id;
        }
        $this->repository->deleteLifecycleRowsForFields($fieldIds);

        return count($fieldIds);
    }

    /**
     * The hard delete the whole lifecycle has been working towards.
     *
     * `fk_fields_model` is `ON DELETE CASCADE`, so this takes every field
     * row with it. It succeeds because no slot row holds a `field_id`
     * belonging to the model any more — the initiator's tombstone loop
     * released them all, and `reassertSeverance()` above just proved it.
     *
     * The orphaned tombstones remain fully sweepable: the Liberator keys
     * on `status` + `page_id` + `slot_column` and never joins
     * `stardust_fields`.
     */
    private function deleteModelRow(int $modelId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM stardust_models WHERE id = ?');
        $stmt->execute([$modelId]);
    }

    private function bumpSchemaVersion(string $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE stardust_schema_version SET version = version + 1, updated_at = ? WHERE id = 1'
        );
        $stmt->execute([$now]);
    }
}
