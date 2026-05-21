<?php

declare(strict_types=1);

namespace StarDust\Write;

use DateTimeZone;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
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
 *   3. If `PayloadSplitter` flagged one or more fields whose live slot
 *      is missing (none assigned, all tombstoned, capacity exhausted),
 *      INSERT one row into `stardust_sync_queue` so the Phase 5
 *      Reconciler will backfill once a slot is available — the ADR
 *      0007 exhaustion fallback.
 *
 * All three writes commit together; any failure rolls everything back.
 * The caller receives an {@see EntryWriteResult}; no exception is
 * thrown for the capacity-exhaustion case (per ADR 0007).
 *
 * Structured-log events emitted on the consumer-supplied logger
 * (closed vocabulary per ADR 0020):
 *   - `entry_written`        (source: `api`) — every successful write
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
            $this->upsertSlotRow($tableName, $entryId, $payload->tenantId, $columnsToValues);
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

    /**
     * @param array<string, mixed> $columnsToValues
     */
    private function upsertSlotRow(
        string $tableName,
        int $entryId,
        int $tenantId,
        array $columnsToValues,
    ): void {
        // Column names come from `stardust_slot_assignments.slot_column`
        // which is populated only by `PageProvisioner` — the universe
        // of legal values is `i_{str|int|num|dt}_NN`. Interpolating
        // them into the SQL string is safe; parameter binding still
        // covers every user-supplied value.
        $columns = array_keys($columnsToValues);
        $cols = array_merge(['entry_id', 'tenant_id'], $columns);

        $placeholders = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';

        $assignments = [];
        foreach ($columns as $c) {
            $assignments[] = "{$c} = VALUES({$c})";
        }
        // Also keep tenant_id in sync on an idempotent re-submission
        // (defensive — the partitioned read path relies on it).
        $assignments[] = 'tenant_id = VALUES(tenant_id)';

        $sql = "INSERT INTO {$tableName} (" . implode(',', $cols) . ') VALUES '
            . $placeholders
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $assignments);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$entryId, $tenantId], array_values($columnsToValues)));
    }
}
