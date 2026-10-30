<?php

declare(strict_types=1);

namespace StarDust\Retype;

use InvalidArgumentException;
use JsonException;
use PDO;
use RuntimeException;
use StarDust\Slot\SlotAssignment;
use StarDust\Support\MultiRowBatches;
use StarDust\Write\SlotRowUpserter;

/**
 * Per-chunk worker for the retype-backfill pipeline.
 *
 * Caller (the {@see RetypeBackfillWorkSource}) owns the surrounding
 * transaction. One {@see self::processChunk()} call:
 *
 *   1. `SELECT id, fields FROM entry_data WHERE tenant_id=? AND
 *      model_id=? AND id > :cursor ORDER BY id LIMIT N` — the
 *      `(tenant_id, model_id)` index satisfies the predicate; ORDER
 *      BY id rides the primary key for a stable cursor.
 *   2. For each row, `json_decode`s `fields`, looks up the retyping
 *      field by name, and runs {@see RetypeCoercionEngine::attempt()}
 *      against the (old, new) declared_type pair.
 *   3. Writes the coerced values (NULL on `nullCoerced` /
 *      `notAttempted`) into the new slot column with
 *      {@see SlotRowUpserter::upsertMany()}, one statement for the whole
 *      chunk. A row-at-a-time upsert cost three round trips per row
 *      (prepare, execute, close), which made the drain take about two
 *      milliseconds per row on a networked database. The UPSERT is
 *      idempotent on `entry_id` so a resume after a mid-chunk crash never
 *      double-writes.
 *   4. Returns a {@see ChunkResult} carrying the new cursor, per-row
 *      `coercion_null` events for the work source to emit
 *      post-commit, and `isFinalChunk` (true when rowsProcessed <
 *      chunkSize, i.e. the partition is exhausted).
 *
 * **One column, one tenant, one page table per checkpoint**, so every row
 * of the multi-row UPSERT has the same column set and nothing is padded
 * with NULL. That is what makes `upsertMany()` safe on the UPDATE branch:
 * these entries already have page rows, and a padded NULL would overwrite
 * a stored value in a column this backfill does not own.
 *
 * **Statement sizing.** A chunk is cut by {@see MultiRowBatches} into
 * sub-statements that stay under the placeholder cap and half of
 * `@@max_allowed_packet`, read once and only when a chunk has more than one
 * row. Without the byte budget a string retype over large values would send
 * a single statement bigger than the packet where the row-at-a-time version
 * never did. Both budgets are constructor-overridable so tests can force
 * splitting.
 */
final class RetypeBackfillExecutor
{
    /** entry_id, tenant_id and the one slot column. */
    private const PLACEHOLDERS_PER_ROW = 3;

    private ?int $resolvedMaxStatementBytes;

    /**
     * @param ?int $maxStatementBytes Byte budget per statement. Null derives it
     *     as half of the session's `@@max_allowed_packet`.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly SlotRowUpserter $slotRowUpserter,
        private readonly int $maxPlaceholders = MultiRowBatches::DEFAULT_MAX_PLACEHOLDERS,
        ?int $maxStatementBytes = null,
    ) {
        if ($maxPlaceholders < 1) {
            throw new InvalidArgumentException('RetypeBackfillExecutor: maxPlaceholders must be >= 1.');
        }
        if ($maxStatementBytes !== null && $maxStatementBytes < 1) {
            throw new InvalidArgumentException('RetypeBackfillExecutor: maxStatementBytes must be >= 1.');
        }
        $this->resolvedMaxStatementBytes = $maxStatementBytes;
    }

    public function processChunk(
        RetypeCheckpoint $checkpoint,
        SlotAssignment $newSlot,
        string $fieldName,
        string $oldDeclaredType,
        string $newDeclaredType,
        string $pageTableName,
        int $chunkSize,
    ): ChunkResult {
        $select = $this->pdo->prepare(
            'SELECT id, fields FROM entry_data'
            . ' WHERE tenant_id = ? AND model_id = ? AND id > ?'
            . ' ORDER BY id LIMIT ?'
        );
        $select->bindValue(1, $checkpoint->tenantId, PDO::PARAM_INT);
        $select->bindValue(2, $checkpoint->modelId, PDO::PARAM_INT);
        $select->bindValue(3, $checkpoint->lastProcessedId, PDO::PARAM_INT);
        $select->bindValue(4, $chunkSize, PDO::PARAM_INT);
        $select->execute();
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);

        $rowsProcessed = 0;
        $newCursor = $checkpoint->lastProcessedId;
        $nullEvents = [];
        /** @var list<array{int, mixed}> $pending (entry id, coerced value) in id order */
        $pending = [];
        /** @var list<int> $costs */
        $costs = [];

        foreach ($rows as $row) {
            $entryId = (int) $row['id'];
            $newCursor = $entryId;
            $rowsProcessed++;

            $valuePresent = false;
            $rawValue = null;
            try {
                $fields = json_decode((string) $row['fields'], true, flags: JSON_THROW_ON_ERROR);
                if (is_array($fields) && array_key_exists($fieldName, $fields)) {
                    $valuePresent = true;
                    $rawValue = $fields[$fieldName];
                }
            } catch (JsonException) {
                // Malformed JSON on entry_data is unrecoverable here.
                // The slot stays NULL (write below); no event is
                // emitted because no coercion was attempted.
                $valuePresent = false;
            }

            $outcome = RetypeCoercionEngine::attempt(
                value: $rawValue,
                valuePresent: $valuePresent,
                from: $oldDeclaredType,
                to: $newDeclaredType,
            );

            $coercedValue = $outcome->isCoerced() ? $outcome->value() : null;

            $pending[] = [$entryId, $coercedValue];
            $costs[] = MultiRowBatches::costOf([0, 0, $coercedValue]);

            if ($outcome->isNullCoerced()) {
                $nullEvents[] = new CoercionNullEvent(
                    tenantId: $checkpoint->tenantId,
                    fieldId: $checkpoint->fieldId,
                    slotAssignmentId: $newSlot->slotAssignmentId,
                    entryId: $entryId,
                    sourceType: $oldDeclaredType,
                    targetType: $newDeclaredType,
                    reason: (string) $outcome->reason(),
                );
            }
        }

        $this->upsertPending($pageTableName, $checkpoint->tenantId, $newSlot->slotColumn, $pending, $costs);

        return new ChunkResult(
            rowsProcessed: $rowsProcessed,
            newCursor: $newCursor,
            nullEvents: $nullEvents,
            isFinalChunk: $rowsProcessed < $chunkSize,
        );
    }

    /**
     * @param list<array{int, mixed}> $pending
     * @param list<int>               $costs
     */
    private function upsertPending(
        string $tableName,
        int $tenantId,
        string $slotColumn,
        array $pending,
        array $costs,
    ): void {
        $ranges = MultiRowBatches::ranges(
            $costs,
            self::PLACEHOLDERS_PER_ROW,
            $this->maxPlaceholders,
            fn (): int => $this->maxStatementBytes(),
        );

        foreach ($ranges as [$offset, $length]) {
            $rows = [];
            foreach (array_slice($pending, $offset, $length) as [$entryId, $value]) {
                $rows[$entryId] = [$value];
            }
            $this->slotRowUpserter->upsertMany($tableName, $tenantId, [$slotColumn], $rows);
        }
    }

    private function maxStatementBytes(): int
    {
        if ($this->resolvedMaxStatementBytes === null) {
            $stmt = $this->pdo->prepare('SELECT @@max_allowed_packet');
            $stmt->execute();
            $packet = $stmt->fetchColumn();
            if ($packet === false) {
                throw new RuntimeException('RetypeBackfillExecutor: could not read max_allowed_packet.');
            }
            $this->resolvedMaxStatementBytes = max(1, intdiv((int) $packet, 2));
        }

        return $this->resolvedMaxStatementBytes;
    }
}
