<?php

declare(strict_types=1);

namespace StarDust\Write;

use DateTimeZone;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Exception\EntryNotFoundException;
use Throwable;

/**
 * Phase 3 single-entry write path.
 *
 * `write(EntryPayload)` runs the canonical sequence in one transaction:
 *
 *   1. INSERT into `entry_data` (the system of record per ADR 0013) —
 *      the complete `fields` JSON lands here in every case, regardless
 *      of slot availability.
 *   2. For each page with at least one live-slot write planned by
 *      {@see PayloadSplitter}, INSERT … ON DUPLICATE KEY UPDATE into
 *      `entry_slots_page_N`. UPSERT semantics (Architecture Blueprint
 *      §5) make replays safe and satisfy implementation_phases.md §3.
 *   3. If `PayloadSplitter` flagged one or more *filterable* fields
 *      whose live slot is missing (none assigned, all tombstoned,
 *      capacity exhausted), INSERT one row into `stardust_sync_queue`
 *      so the Phase 5 Reconciler will backfill once a slot is
 *      available — the ADR 0007 exhaustion fallback. Non-filterable
 *      fields are JSON-only (ADR 0034): holding no slot is their
 *      steady state, so they never enqueue.
 *
 * Step 2 can plan zero pages. An entry whose payload touches only
 * non-filterable fields writes no `entry_slots_page_N` row at all —
 * normal under ADR 0034, and harmless: the read path LEFT-JOINs pages
 * and the Liberator's sweep nullifies by `entry_id` without requiring
 * the row to exist.
 *
 * All three writes commit together; any failure rolls everything back.
 * The caller receives an {@see EntryWriteResult}; no exception is
 * thrown for the capacity-exhaustion case (per ADR 0007).
 *
 * `update()` is the full-replace counterpart — see its own docblock;
 * it reuses steps 2 and 3 verbatim and differs only in replacing the
 * `entry_data` INSERT with an UPDATE, and in clearing the slot columns
 * of fields the new payload omits.
 *
 * Structured-log events emitted on the consumer-supplied logger
 * (closed vocabulary per ADR 0020):
 *   - `entry_written`        (source: `api`) — every successful write
 *   - `entry_updated`        (source: `api`) — every successful update
 *   - `exhaustion_fallback`  (source: `api`) — only when at least one
 *                              field was enqueued for backfill
 *
 * Slot family mapping (`string → str`, `int → int`, `numeric → num`,
 * `datetime → dt`) lives in {@see PayloadSplitter} via the declared
 * type carried on the {@see LiveSlotEntry} — `EntryWriter` is
 * type-agnostic by design.
 */
final class EntryWriter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly SlotRowUpserter $slotRowUpserter,
    ) {
    }

    /**
     * Public single-entry write. Opens and commits its own transaction.
     */
    public function write(EntryPayload $payload): EntryWriteResult
    {
        TenantId::assertValid($payload->tenantId);

        $this->pdo->beginTransaction();
        try {
            $result = $this->writeWithinTransaction($payload);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->emitWriteEvents($payload, $result);
        return $result;
    }

    /**
     * Bulk-path entry point. Performs the same write as
     * {@see self::write()} but assumes the caller already opened a
     * transaction and will commit (or roll back) the surrounding
     * chunk. Does NOT emit structured-log events — the bulk caller
     * batches them at chunk-commit time per ADR 0020.
     *
     * Returns the result so the bulk caller can stitch the per-chunk
     * manifest from each entity's outcome.
     */
    public function writeWithinTransaction(EntryPayload $payload): EntryWriteResult
    {
        TenantId::assertValid($payload->tenantId);

        $now = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $jsonFields = json_encode(
            $payload->fields,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        // PayloadSplitter is pure; resolving the live-slot map and
        // building the plan first means an UncoercibleSlotValueException
        // surfaces before we INSERT the entry row.
        $map = LiveSlotMap::loadFor($this->pdo, $payload->modelId);
        $plan = PayloadSplitter::split($map, $payload->fields);

        // Page-id → physical table name. Resolved once before INSERTs;
        // the registry never renames a provisioned page (ADR 0012).
        $pageTableNames = $this->resolvePageTableNames(array_keys($plan->slotWrites));

        $insertEntry = $this->pdo->prepare(
            'INSERT INTO entry_data (tenant_id, model_id, created_at, updated_at, fields)'
            . ' VALUES (?, ?, ?, ?, ?)'
        );
        $insertEntry->execute([
            $payload->tenantId,
            $payload->modelId,
            $now,
            $now,
            $jsonFields,
        ]);
        $entryId = (int) $this->pdo->lastInsertId();

        $slotsWritten = [];
        foreach ($plan->slotWrites as $pageId => $columnsToValues) {
            $tableName = $pageTableNames[$pageId];
            $this->slotRowUpserter->upsert($tableName, $entryId, $payload->tenantId, $columnsToValues);
            foreach ($columnsToValues as $slotColumn => $_value) {
                $slotsWritten[] = ['pageId' => $pageId, 'slotColumn' => $slotColumn];
            }
        }

        $enqueued = $plan->hasMissingSlotFields();
        if ($enqueued) {
            $enqueue = $this->pdo->prepare(
                'INSERT INTO stardust_sync_queue (entry_id, created_at) VALUES (?, ?)'
            );
            $enqueue->execute([$entryId, $now]);
        }

        return new EntryWriteResult(
            entryId: $entryId,
            enqueuedForBackfill: $enqueued,
            slotsWritten: $slotsWritten,
        );
    }

    /**
     * Full-replace update of an existing entry. Opens and commits its
     * own transaction.
     *
     * `$fields` becomes the entry's **complete** `fields` JSON — this is
     * PUT, not PATCH. The consequence that matters is step 3 below:
     * a field the caller omits must have its slot column actively
     * cleared, or the read path would keep serving an indexed value the
     * payload no longer contains.
     *
     *   1. `SELECT … FOR UPDATE` the row, scoped to the tenant and to
     *      `deleted_at IS NULL`. Resolves `model_id` (which is immutable
     *      — an update can never move an entry between models) and locks
     *      the row against a concurrent delete.
     *   2. UPDATE `entry_data` with the new payload and `updated_at`.
     *   3. Per-page UPSERT, over the union of the fields present in the
     *      payload and the model's other live slots, the latter written
     *      as NULL.
     *   4. The ADR 0007 exhaustion enqueue, exactly as the write path
     *      does it — an update that introduces a filterable field with
     *      no live slot queues for backfill rather than failing.
     *
     * There is deliberately no `updateWithinTransaction()` sibling: no
     * bulk-update path exists yet, and this repo does not grow surface
     * area ahead of a caller.
     *
     * @param array<string, mixed> $fields
     *
     * @throws EntryNotFoundException when the row does not exist, belongs
     *                                to another tenant, or is soft-deleted
     */
    public function update(int $tenantId, int $entryId, array $fields): EntryWriteResult
    {
        TenantId::assertValid($tenantId);

        $this->pdo->beginTransaction();
        try {
            $modelId = $this->lockEntryForUpdate($tenantId, $entryId);
            $result = $this->applyUpdate($tenantId, $entryId, $modelId, $fields);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->emitUpdateEvents($tenantId, $modelId, $result);
        return $result;
    }

    /**
     * @throws EntryNotFoundException
     */
    private function lockEntryForUpdate(int $tenantId, int $entryId): int
    {
        $select = $this->pdo->prepare(
            'SELECT model_id FROM entry_data'
            . ' WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL'
            . ' FOR UPDATE'
        );
        $select->execute([$entryId, $tenantId]);
        $row = $select->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new EntryNotFoundException(
                "EntryWriter: entry {$entryId} not found for tenant {$tenantId}."
            );
        }

        return (int) $row['model_id'];
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function applyUpdate(int $tenantId, int $entryId, int $modelId, array $fields): EntryWriteResult
    {
        $now = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $jsonFields = json_encode(
            $fields,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        // Same pure planning as the write path, and for the same
        // reason: an UncoercibleSlotValueException must surface before
        // entry_data is touched.
        $map = LiveSlotMap::loadFor($this->pdo, $modelId);
        $plan = PayloadSplitter::split($map, $fields);
        $slotWrites = $this->withClearedSlots($map, $fields, $plan->slotWrites);

        $pageTableNames = $this->resolvePageTableNames(array_keys($slotWrites));

        $updateEntry = $this->pdo->prepare(
            'UPDATE entry_data SET fields = ?, updated_at = ?'
            . ' WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL'
        );
        $updateEntry->execute([$jsonFields, $now, $entryId, $tenantId]);

        $slotsWritten = [];
        foreach ($slotWrites as $pageId => $columnsToValues) {
            $tableName = $pageTableNames[$pageId];
            $this->slotRowUpserter->upsert($tableName, $entryId, $tenantId, $columnsToValues);
            foreach ($columnsToValues as $slotColumn => $_value) {
                $slotsWritten[] = ['pageId' => $pageId, 'slotColumn' => $slotColumn];
            }
        }

        $enqueued = $plan->hasMissingSlotFields();
        if ($enqueued) {
            $enqueue = $this->pdo->prepare(
                'INSERT INTO stardust_sync_queue (entry_id, created_at) VALUES (?, ?)'
            );
            $enqueue->execute([$entryId, $now]);
        }

        return new EntryWriteResult(
            entryId: $entryId,
            enqueuedForBackfill: $enqueued,
            slotsWritten: $slotsWritten,
        );
    }

    /**
     * The PUT invariant: every live slot of the model whose field is
     * absent from the new payload is written as NULL.
     *
     * `PayloadSplitter` only plans writes for fields *present* in the
     * payload, so without this an omitted field would keep its old
     * indexed value while vanishing from `entry_data.fields` — the two
     * would disagree, and a filter would still match on a value the
     * entry no longer has.
     *
     * `array_key_exists` rather than `isset`, because an explicit
     * `['name' => null]` is a caller setting the field to NULL and is
     * already planned by the splitter; only genuinely absent keys are
     * cleared here.
     *
     * @param array<string, mixed>            $fields
     * @param array<int, array<string, mixed>> $slotWrites
     * @return array<int, array<string, mixed>>
     */
    private function withClearedSlots(LiveSlotMap $map, array $fields, array $slotWrites): array
    {
        foreach ($map->all() as $fieldName => $entry) {
            if (array_key_exists($fieldName, $fields)) {
                continue;
            }
            $slotWrites[$entry->pageId][$entry->slotColumn] = null;
        }

        return $slotWrites;
    }

    /**
     * `slots_written` counts every slot column the update touched,
     * including those cleared to NULL — for an update, clearing is a
     * write.
     */
    private function emitUpdateEvents(int $tenantId, int $modelId, EntryWriteResult $result): void
    {
        $this->logger->info('entry updated', [
            'event'         => 'entry_updated',
            'source'        => 'api',
            'tenant_id'     => $tenantId,
            'entry_id'      => $result->entryId,
            'model_id'      => $modelId,
            'slots_written' => count($result->slotsWritten),
            'enqueued'      => $result->enqueuedForBackfill,
        ]);

        if ($result->enqueuedForBackfill) {
            $this->logger->info('exhaustion fallback engaged', [
                'event'     => 'exhaustion_fallback',
                'source'    => 'api',
                'tenant_id' => $tenantId,
                'entry_id'  => $result->entryId,
                'model_id'  => $modelId,
            ]);
        }
    }

    public function emitWriteEvents(EntryPayload $payload, EntryWriteResult $result): void
    {
        $this->logger->info('entry written', [
            'event'         => 'entry_written',
            'source'        => 'api',
            'tenant_id'     => $payload->tenantId,
            'entry_id'      => $result->entryId,
            'model_id'      => $payload->modelId,
            'slots_written' => count($result->slotsWritten),
            'enqueued'      => $result->enqueuedForBackfill,
        ]);

        if ($result->enqueuedForBackfill) {
            $this->logger->info('exhaustion fallback engaged', [
                'event'          => 'exhaustion_fallback',
                'source'         => 'api',
                'tenant_id'      => $payload->tenantId,
                'entry_id'       => $result->entryId,
                'model_id'       => $payload->modelId,
            ]);
        }
    }

    /**
     * @param list<int> $pageIds
     * @return array<int, string> pageId → table_name
     */
    private function resolvePageTableNames(array $pageIds): array
    {
        if ($pageIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, table_name FROM stardust_pages WHERE id IN ({$placeholders})"
        );
        $stmt->execute($pageIds);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['id']] = (string) $row['table_name'];
        }
        return $out;
    }

}
