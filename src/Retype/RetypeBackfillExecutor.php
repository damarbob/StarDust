<?php

declare(strict_types=1);

namespace StarDust\Retype;

use JsonException;
use PDO;
use StarDust\Slot\SlotAssignment;
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
 *   3. Calls {@see SlotRowUpserter::upsert()} per entry to write the
 *      coerced value (or NULL on `nullCoerced` / `notAttempted`) into
 *      the new slot column. The UPSERT is idempotent on `entry_id` so
 *      a resume after a mid-chunk crash never double-writes.
 *   4. Returns a {@see ChunkResult} carrying the new cursor, per-row
 *      `coercion_null` events for the work source to emit
 *      post-commit, and `isFinalChunk` (true when rowsProcessed <
 *      chunkSize, i.e. the partition is exhausted).
 */
final class RetypeBackfillExecutor
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SlotRowUpserter $slotRowUpserter,
    ) {
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

            $this->slotRowUpserter->upsert(
                tableName: $pageTableName,
                entryId: $entryId,
                tenantId: $checkpoint->tenantId,
                columnsToValues: [$newSlot->slotColumn => $coercedValue],
            );

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

        return new ChunkResult(
            rowsProcessed: $rowsProcessed,
            newCursor: $newCursor,
            nullEvents: $nullEvents,
            isFinalChunk: $rowsProcessed < $chunkSize,
        );
    }
}
