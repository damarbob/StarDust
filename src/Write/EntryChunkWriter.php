<?php

declare(strict_types=1);

namespace StarDust\Write;

use DateTimeZone;
use InvalidArgumentException;
use PDO;
use Psr\Clock\ClockInterface;
use RuntimeException;
use StarDust\Exception\ModelDeletionInProgressException;
use StarDust\Support\MultiRowBatches;

/**
 * Writes a list of entries inside the caller's transaction in a
 * number of statements that does not grow with the entry count.
 *
 * This is the one write implementation: {@see EntryWriter::writeWithinTransaction()}
 * is `writeChunk([$payload])[0]`, and {@see BulkIngestor} and the
 * Reconciler's import drain hand it a whole chunk. A per-entry loop over
 * the single-entry sequence cost about a dozen round trips per entry
 * (a registry read, a page-name read, the `entry_data` INSERT, one slot
 * UPSERT per page, the optional queue INSERT), so a 500-entry chunk was
 * thousands of statements. Here the same chunk is:
 *
 *   1. One {@see LiveSlotMap::loadFor()} per *distinct* model, then pure
 *      planning per payload (canonicalise, encode, split) with no SQL
 *      write. Every per-payload failure (an invalid tenant, a deleting
 *      model, an uncoercible value) therefore surfaces before anything
 *      is written, in payload order, so the first failing payload raises
 *      exactly the exception the per-entry loop raised for it. The net
 *      state is unchanged either way: the caller rolls the chunk back.
 *   2. One `stardust_pages` read over the union of planned page ids.
 *   3. Multi-row `INSERT INTO entry_data`, one statement per sub-batch.
 *   4. One {@see SlotRowUpserter::upsertMany()} per (page table, tenant)
 *      per sub-batch.
 *   5. One multi-row `stardust_sync_queue` INSERT per sub-batch for the
 *      entries that planned an ADR 0007 exhaustion enqueue.
 *
 * Emits no events and opens no transaction; the caller owns both.
 *
 * **Loading the map once per model is equivalent to loading it per
 * entry** under the default REPEATABLE READ: every non-locking read in a
 * transaction sees the snapshot taken by its first read, so the
 * per-entry loop was already reading one registry state per chunk.
 * Verified on MySQL 8.0.13 and MariaDB 10.11 / 11 with a registry change
 * committed by a second session between two in-transaction reads.
 *
 * **Entry ids are derived, not read back.** A multi-row INSERT with a
 * known row count is a "simple insert": InnoDB reserves its whole id
 * block up front, so the rows take `LAST_INSERT_ID() + i ×
 * @@auto_increment_increment` in VALUES order. That holds under MySQL's
 * default interleaved lock mode (2) as well as MariaDB's consecutive
 * mode (1). Measured with three processes inserting concurrently
 * against 1 500 multi-row batches per engine, no batch was ever
 * non-consecutive. The increment is read from the session once per
 * writer rather than assumed to be 1.
 *
 * **Statement sizing.** Sub-batches are cut by a placeholder budget
 * (below MySQL's 65 535 prepared-statement limit) and a byte budget
 * derived from `@@max_allowed_packet` (4 MB on some MySQL
 * configurations, 16 MB by default on MariaDB), so a chunk of large
 * payloads splits into more statements instead of failing. Values are
 * costed at twice their byte length because an emulated prepare sends
 * them escaped inline. Both budgets are constructor-overridable so tests
 * can force splitting. A single row always goes out alone, whatever its
 * size, exactly as the per-entry loop sent it.
 *
 * **One clock read per chunk**, so every entry in a chunk carries the
 * same `created_at` / `updated_at` / queue `created_at`. The per-entry
 * loop read the clock per entry; the stored precision is one second, so
 * the difference is at most a boundary tick inside one transaction.
 */
final class EntryChunkWriter
{
    public const DEFAULT_MAX_PLACEHOLDERS = MultiRowBatches::DEFAULT_MAX_PLACEHOLDERS;

    private const ENTRY_COLUMNS = 5;
    private const QUEUE_COLUMNS = 3;

    private ?int $autoIncrementIncrement = null;
    private ?int $resolvedMaxStatementBytes;

    /**
     * @param ?int $maxStatementBytes Byte budget per statement. Null derives it
     *     as half of the session's `@@max_allowed_packet`, read once, on the
     *     first chunk of more than one entry.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock,
        private readonly SlotRowUpserter $slotRowUpserter,
        private readonly int $maxPlaceholders = self::DEFAULT_MAX_PLACEHOLDERS,
        ?int $maxStatementBytes = null,
    ) {
        if ($maxPlaceholders < 1) {
            throw new InvalidArgumentException('EntryChunkWriter: maxPlaceholders must be >= 1.');
        }
        if ($maxStatementBytes !== null && $maxStatementBytes < 1) {
            throw new InvalidArgumentException('EntryChunkWriter: maxStatementBytes must be >= 1.');
        }
        $this->resolvedMaxStatementBytes = $maxStatementBytes;
    }

    /**
     * `$correlationId` is the id stamped on any ADR 0007 queue row the
     * chunk enqueues; null falls back to each payload's own, which is
     * exactly what the single-entry path did per entry.
     *
     * @param list<EntryPayload> $payloads
     * @return list<EntryWriteResult> one per payload, in payload order
     */
    public function writeChunk(array $payloads, ?string $correlationId = null): array
    {
        if ($payloads === []) {
            return [];
        }

        // 1. Plan. No SQL write happens until every payload has planned.
        /** @var array<int, LiveSlotMap> $maps */
        $maps = [];
        /** @var list<array{payload: EntryPayload, json: string, plan: SplitPlan}> $planned */
        $planned = [];
        $pageIds = [];

        foreach ($payloads as $payload) {
            TenantId::assertValid($payload->tenantId);

            // The map load precedes the json_encode because ADR 0036
            // canonicalisation must apply to the payload we persist, not
            // just to the slot plan: a client still sending a pre-rename
            // field name would otherwise store the stale key verbatim.
            $map = $maps[$payload->modelId] ??= LiveSlotMap::loadFor($this->pdo, $payload->modelId);

            // ADR 0038, and note this deliberately INVERTS the ADR 0037
            // rule one line below. A deleted *field's* key is stripped
            // rather than rejected, because a rejected write loses data
            // while a strip converges. For a model there is no residual
            // valid entry to preserve: this row would land either behind
            // the purge cursor, making the acceptance a lie, or ahead of
            // it, becoming a permanent orphan with a dangling `model_id`.
            if ($map->isModelDeleting()) {
                throw new ModelDeletionInProgressException(
                    "Model {$payload->modelId} is being deleted; no entry can be written to it."
                    . ' Run a reconciler to finish the purge.'
                );
            }

            $fields = $map->canonicalise($payload->fields);

            $json = json_encode(
                $fields,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );

            // Pure; an UncoercibleSlotValueException surfaces here,
            // before any row of the chunk is written.
            $plan = PayloadSplitter::split($map, $fields);
            foreach (array_keys($plan->slotWrites) as $pageId) {
                $pageIds[$pageId] = true;
            }

            $planned[] = ['payload' => $payload, 'json' => $json, 'plan' => $plan];
        }

        // 2. Page-id → physical table name, once for the whole chunk; the
        // registry never renames a provisioned page (ADR 0012).
        $pageTableNames = $this->resolvePageTableNames(array_keys($pageIds));

        $now = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        // 3. entry_data.
        $entryIds = $this->insertEntries($planned, $now);

        // 4. Slot rows, grouped per (page, tenant) so each group is one
        // multi-row UPSERT with a uniform column set.
        /** @var array<string, array{table: string, tenant: int, columns: array<string, true>, rows: array<int, array<string, mixed>>}> $groups */
        $groups = [];
        $slotsWritten = [];
        foreach ($planned as $i => $item) {
            $slotsWritten[$i] = [];
            foreach ($item['plan']->slotWrites as $pageId => $columnsToValues) {
                $key = $pageId . ':' . $item['payload']->tenantId;
                $groups[$key] ??= [
                    'table'   => $pageTableNames[$pageId],
                    'tenant'  => $item['payload']->tenantId,
                    'columns' => [],
                    'rows'    => [],
                ];
                foreach ($columnsToValues as $slotColumn => $_value) {
                    $groups[$key]['columns'][$slotColumn] = true;
                    $slotsWritten[$i][] = ['pageId' => $pageId, 'slotColumn' => $slotColumn];
                }
                $groups[$key]['rows'][$entryIds[$i]] = $columnsToValues;
            }
        }

        foreach ($groups as $group) {
            $this->upsertGroup($group['table'], $group['tenant'], array_keys($group['columns']), $group['rows']);
        }

        // 5. ADR 0007 exhaustion enqueue for exactly the entries that
        // planned one. The bulk caller passes its *ingest* id, because a
        // bulk load is the operation its consumer knows about.
        $queueRows = [];
        foreach ($planned as $i => $item) {
            if ($item['plan']->hasMissingSlotFields()) {
                $queueRows[] = [$entryIds[$i], $now, $correlationId ?? $item['payload']->correlationId];
            }
        }
        $this->insertQueueRows($queueRows);

        $results = [];
        foreach ($planned as $i => $item) {
            $results[] = new EntryWriteResult(
                entryId: $entryIds[$i],
                enqueuedForBackfill: $item['plan']->hasMissingSlotFields(),
                slotsWritten: $slotsWritten[$i],
            );
        }

        return $results;
    }

    /**
     * @param list<array{payload: EntryPayload, json: string, plan: SplitPlan}> $planned
     * @return list<int> entry id per planned index
     */
    private function insertEntries(array $planned, string $now): array
    {
        $costs = [];
        foreach ($planned as $item) {
            // Two ints, two 19-char timestamps, the JSON document.
            $costs[] = MultiRowBatches::costOf([0, 0, $now, $now, $item['json']]);
        }

        $entryIds = [];
        foreach ($this->batches($costs, self::ENTRY_COLUMNS) as [$offset, $length]) {
            $params = [];
            for ($i = $offset; $i < $offset + $length; $i++) {
                $payload = $planned[$i]['payload'];
                $params[] = $payload->tenantId;
                $params[] = $payload->modelId;
                $params[] = $now;
                $params[] = $now;
                $params[] = $planned[$i]['json'];
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO entry_data (tenant_id, model_id, created_at, updated_at, fields) VALUES '
                . implode(',', array_fill(0, $length, '(?, ?, ?, ?, ?)'))
            );
            $stmt->execute($params);

            // First id of the statement's block; the rest follow at the
            // session increment, see the class docblock.
            $first = (int) $this->pdo->lastInsertId();
            $step = $length > 1 ? $this->autoIncrementIncrement() : 1;
            for ($j = 0; $j < $length; $j++) {
                $entryIds[] = $first + $j * $step;
            }
        }

        return $entryIds;
    }

    /**
     * Entries that share a page but planned different columns are
     * padded with NULL to one column set. **That is safe here and only
     * here**: every entry id in the group was minted by this chunk's own
     * `entry_data` INSERT a moment ago, inside the same transaction, so
     * no slot row can exist for it yet and the UPSERT always takes its
     * INSERT branch, where a NULL is simply an unwritten column. On the
     * UPDATE branch the same NULL would overwrite a stored value, which
     * is why this padding must never be copied onto a path that writes
     * slot rows for existing entries.
     *
     * @param list<string>                      $columns
     * @param array<int, array<string, mixed>> $rows entry_id → (slot_column → value)
     */
    private function upsertGroup(string $table, int $tenantId, array $columns, array $rows): void
    {
        $aligned = [];
        $costs = [];
        foreach ($rows as $entryId => $columnsToValues) {
            $values = [];
            foreach ($columns as $c) {
                $values[] = $columnsToValues[$c] ?? null;
            }
            $aligned[] = [$entryId, $values];
            $costs[] = MultiRowBatches::costOf([0, 0, ...$values]);
        }

        foreach ($this->batches($costs, count($columns) + 2) as [$offset, $length]) {
            $batch = [];
            foreach (array_slice($aligned, $offset, $length) as [$entryId, $values]) {
                $batch[$entryId] = $values;
            }
            $this->slotRowUpserter->upsertMany($table, $tenantId, $columns, $batch);
        }
    }

    /**
     * @param list<array{int, string, ?string}> $rows (entry_id, created_at, origin_correlation_id)
     */
    private function insertQueueRows(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $costs = array_map(static fn (array $row): int => MultiRowBatches::costOf($row), $rows);

        foreach ($this->batches($costs, self::QUEUE_COLUMNS) as [$offset, $length]) {
            $params = [];
            foreach (array_slice($rows, $offset, $length) as $row) {
                array_push($params, ...$row);
            }
            $stmt = $this->pdo->prepare(
                'INSERT INTO stardust_sync_queue (entry_id, created_at, origin_correlation_id) VALUES '
                . implode(',', array_fill(0, $length, '(?, ?, ?)'))
            );
            $stmt->execute($params);
        }
    }

    /**
     * Order is preserved, which the id derivation in
     * {@see self::insertEntries()} depends on.
     *
     * @param list<int> $costs estimated bytes per row
     * @return list<array{int, int}>
     */
    private function batches(array $costs, int $placeholdersPerRow): array
    {
        return MultiRowBatches::ranges(
            $costs,
            $placeholdersPerRow,
            $this->maxPlaceholders,
            fn (): int => $this->maxStatementBytes(),
        );
    }

    private function autoIncrementIncrement(): int
    {
        if ($this->autoIncrementIncrement === null) {
            $this->readSessionLimits();
        }
        return (int) $this->autoIncrementIncrement;
    }

    private function maxStatementBytes(): int
    {
        if ($this->resolvedMaxStatementBytes === null) {
            $this->readSessionLimits();
        }
        return (int) $this->resolvedMaxStatementBytes;
    }

    /**
     * One read for both session limits, once per writer. Deferred until
     * a chunk of more than one entry needs them, so the single-entry
     * path issues no statement it did not issue before.
     */
    private function readSessionLimits(): void
    {
        $stmt = $this->pdo->prepare('SELECT @@auto_increment_increment, @@max_allowed_packet');
        $stmt->execute();
        $row = $stmt->fetchAll(PDO::FETCH_NUM)[0] ?? null;
        if (!is_array($row)) {
            throw new RuntimeException('EntryChunkWriter: could not read session limits.');
        }

        $this->autoIncrementIncrement ??= max(1, (int) $row[0]);
        $this->resolvedMaxStatementBytes ??= max(1, intdiv((int) $row[1], 2));
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
