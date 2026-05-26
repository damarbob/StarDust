<?php

declare(strict_types=1);

namespace StarDust\Retype;

use DateTimeZone;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Reconciler\ReconcilerWorkSource;
use StarDust\Reconciler\TickOutcome;
use StarDust\Slot\SlotAssignment;
use StarDust\Slot\SlotReserver;
use StarDust\Watcher\CardinalitySampler;
use Throwable;

/**
 * Phase 6b Reconciler work source for retype + filterability-promotion
 * backfill (ADR 0016, ADR 0024).
 *
 * Each tick:
 *   1. Claim one `running` `backfill_checkpoints` row whose
 *      `job_name LIKE 'retype_field_%'` via `FOR UPDATE SKIP LOCKED`
 *      so concurrent Reconciler workers don't double-process the
 *      same checkpoint.
 *   2. Resolve the field's live `backfilling`/`ready` slot. If
 *      missing (the retype was deferred at initiation because no
 *      free slot of the target shape existed), call
 *      {@see SlotReserver::reserveForBackfillWithinTransaction()};
 *      on success the new slot is in `backfilling` and the chunk
 *      proceeds, on failure the tick returns CAPACITY_WAIT so the
 *      Reconciler sleeps and lets the Watcher provision before
 *      retrying.
 *   3. Resolve the page's `entry_slots_page_N` table name.
 *   4. Hand the chunk to {@see RetypeBackfillExecutor::processChunk()}.
 *   5. Advance the checkpoint cursor. If the executor reports
 *      `isFinalChunk` (rowsProcessed < chunkSize, partition
 *      exhausted): flip the slot `backfilling → ready`, mark the
 *      checkpoint `completed`, bump `stardust_schema_version` — all
 *      in the same transaction.
 *   6. Commit. Emit `chunk_claimed` / `chunk_complete` (or
 *      `capacity_wait`); emit one `coercion_null` per
 *      attempted-but-failed coercion the executor collected; on
 *      promotion, emit `promote_to_ready` and trigger
 *      {@see CardinalitySampler::sampleSlot()} which emits its own
 *      `cardinality_sampled` (and conditionally
 *      `low_cardinality_index`) on the registry source.
 *
 * The work source intentionally does NOT route per-row failures to
 * the DLQ. Coercion failures store NULL by design (ADR 0013: JSON
 * payload remains authoritative); only the `coercion_null` event is
 * required for operator audit. Unrecoverable failures (e.g.,
 * malformed `entry_data.fields` JSON for a specific row) likewise
 * just leave that row's slot column NULL — fall-back-to-JSON_EXTRACT
 * preserves read availability.
 */
final class RetypeBackfillWorkSource implements ReconcilerWorkSource
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly RetypeCheckpointRepository $repository,
        private readonly RetypeBackfillExecutor $executor,
        private readonly SlotReserver $slotReserver,
        private readonly CardinalitySampler $cardinalitySampler,
        private readonly int $chunkSize,
    ) {
    }

    public function tickOne(string $chunkCorrelationId): TickOutcome
    {
        $now = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $this->pdo->beginTransaction();

        $checkpoint = null;
        $newSlot = null;
        $reservedThisTick = false;
        $result = null;
        $tableName = null;
        $promoted = false;

        try {
            $checkpoint = $this->repository->loadOneClaimable();
            if ($checkpoint === null) {
                $this->pdo->commit();
                return TickOutcome::IDLE;
            }

            $newSlot = $this->loadLiveSlot($checkpoint->fieldId);
            if ($newSlot === null) {
                // Deferred-assignment path: the initiator could not
                // reserve a slot at initiation time. Try now.
                $newSlot = $this->slotReserver->reserveForBackfillWithinTransaction(
                    $checkpoint->fieldId,
                    requireIndexed: $checkpoint->targetIsFilterable,
                );
                if ($newSlot === null) {
                    $this->pdo->rollBack();
                    $this->logger->warning('retype capacity wait', [
                        'event'          => 'capacity_wait',
                        'source'         => 'reconciler',
                        'correlation_id' => $chunkCorrelationId,
                        'queue'          => 'retype_backfill',
                        'field_id'       => $checkpoint->fieldId,
                    ]);
                    return TickOutcome::CAPACITY_WAIT;
                }
                $reservedThisTick = true;
            }

            $tableName = $this->resolvePageTableName($newSlot->pageId);

            $this->logger->info('retype chunk claimed', [
                'event'          => 'chunk_claimed',
                'source'         => 'reconciler',
                'correlation_id' => $chunkCorrelationId,
                'queue'          => 'retype_backfill',
                'field_id'       => $checkpoint->fieldId,
                'tenant_id'      => $checkpoint->tenantId,
                'cursor'         => $checkpoint->lastProcessedId,
            ]);

            $result = $this->executor->processChunk(
                checkpoint: $checkpoint,
                newSlot: $newSlot,
                fieldName: $checkpoint->fieldName,
                oldDeclaredType: $checkpoint->sourceDeclaredType,
                newDeclaredType: $checkpoint->targetDeclaredType,
                pageTableName: $tableName,
                chunkSize: $this->chunkSize,
            );

            if ($result->isFinalChunk) {
                $this->repository->markCompleted($checkpoint->id, $result->newCursor, $now);
                $this->promoteSlotToReady($newSlot->slotAssignmentId, $now);
                $this->bumpSchemaVersion($now);
                $promoted = true;
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

        // Post-commit event emission per the registry write-then-log
        // discipline used elsewhere in the codebase: emit events that
        // describe committed state, never rolled-back state.
        if ($reservedThisTick && $newSlot !== null) {
            $this->slotReserver->emitSlotReservedEvent(
                $checkpoint->fieldId,
                $newSlot,
                'backfilling',
            );
        }

        foreach ($result->nullEvents as $nullEvent) {
            $this->emitCoercionNull($nullEvent, $chunkCorrelationId);
        }

        // Retype backfill has no DLQ path — coercion failures store
        // NULL with a per-row `coercion_null` event, so every chunk
        // that completes is a `chunk_complete`. The `coercion_nulls`
        // count and `final_chunk` flag convey what the SyncQueueWorkSource
        // expresses via `chunk_partial`.
        $this->logger->info('retype chunk processed', [
            'event'          => 'chunk_complete',
            'source'         => 'reconciler',
            'correlation_id' => $chunkCorrelationId,
            'queue'          => 'retype_backfill',
            'field_id'       => $checkpoint->fieldId,
            'rows_processed' => $result->rowsProcessed,
            'coercion_nulls' => count($result->nullEvents),
            'final_chunk'    => $promoted,
        ]);

        if ($promoted && $newSlot !== null) {
            $this->logger->info('slot promoted to ready', [
                'event'              => 'promote_to_ready',
                'source'             => 'registry',
                'correlation_id'     => $chunkCorrelationId,
                'tenant_id'          => $checkpoint->tenantId,
                'field_id'           => $checkpoint->fieldId,
                'slot_assignment_id' => $newSlot->slotAssignmentId,
                'declared_type'      => $checkpoint->targetDeclaredType,
                'is_filterable'      => $checkpoint->targetIsFilterable,
            ]);

            // Triggers `cardinality_sampled` (always) and
            // `low_cardinality_index` (conditionally) per ADR 0019.
            $this->cardinalitySampler->sampleSlot($newSlot->slotAssignmentId);
        }

        return TickOutcome::WORK_DONE;
    }

    private function loadLiveSlot(int $fieldId): ?SlotAssignment
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.page_id, a.slot_column, a.slot_type'
            . ' FROM stardust_slot_assignments a'
            . " WHERE a.field_id = ? AND a.status IN ('backfilling','ready')"
            . ' LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$fieldId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return new SlotAssignment(
            pageId: (int) $row['page_id'],
            slotColumn: (string) $row['slot_column'],
            slotAssignmentId: (int) $row['id'],
            slotType: (string) $row['slot_type'],
        );
    }

    private function resolvePageTableName(int $pageId): string
    {
        $stmt = $this->pdo->prepare('SELECT table_name FROM stardust_pages WHERE id = ?');
        $stmt->execute([$pageId]);
        $name = $stmt->fetchColumn();
        if ($name === false) {
            throw new \RuntimeException("Page {$pageId} not found while ticking retype work source.");
        }
        return (string) $name;
    }

    private function promoteSlotToReady(int $slotAssignmentId, string $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE stardust_slot_assignments'
            . " SET status = 'ready', updated_at = ?"
            . " WHERE id = ? AND status = 'backfilling'"
        );
        $stmt->execute([$now, $slotAssignmentId]);
    }

    private function bumpSchemaVersion(string $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE stardust_schema_version'
            . ' SET version = version + 1, updated_at = ?'
            . ' WHERE id = 1'
        );
        $stmt->execute([$now]);
    }

    private function emitCoercionNull(CoercionNullEvent $event, string $correlationId): void
    {
        $this->logger->warning('coercion produced NULL', [
            'event'              => 'coercion_null',
            'source'             => 'reconciler',
            'correlation_id'     => $correlationId,
            'tenant_id'          => $event->tenantId,
            'field_id'           => $event->fieldId,
            'slot_assignment_id' => $event->slotAssignmentId,
            'entry_id'           => $event->entryId,
            'source_type'        => $event->sourceType,
            'target_type'        => $event->targetType,
            'reason'             => $event->reason,
        ]);
    }
}
