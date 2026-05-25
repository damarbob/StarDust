<?php

declare(strict_types=1);

namespace StarDust\Liberator;

use InvalidArgumentException;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Per-slot sweep loop. Owns ADR 0009's `sweep` and `reclaim` phases:
 * chunked nullification of `entry_slots_page_X.<slotColumn>`, in-tx
 * `sweep_cursor_id` checkpointing, bounded deadlock retry, and the
 * final `tombstoned → free` transition (with `stardust_schema_version`
 * bump per ADR 0017 §4.6).
 *
 * Chunk shape (one transaction per chunk):
 *   1. `SELECT id FROM <table> WHERE id > :cursor ORDER BY id LIMIT N`
 *      — bounds the chunk and gives a deterministic `newCursor`.
 *   2. `UPDATE <table> SET <slotColumn> = NULL WHERE id IN (...)`.
 *   3. `UPDATE stardust_slot_assignments SET sweep_cursor_id = :newCursor`
 *      (guarded by `AND status = 'tombstoned'` so a racing operator
 *      cannot have us mutate a slot that has been resurrected).
 *   4. On the final chunk (rows < chunkSize): same tx also flips
 *      `status='free', field_id=NULL` and bumps
 *      `stardust_schema_version.version`. `sweep_gap_count` is
 *      intentionally preserved (operators inspect it post-mortem; the
 *      SlotReserver is the right place to reset it on the next
 *      `free → assigned` transition).
 *   5. COMMIT.
 *
 * Failure handling per ADR 0009 + blueprint AC#7/AC#8:
 *   - `SQLSTATE 40001` → rollback, emit `deadlock_retry`, sleep
 *     `interChunkDelayMicros`, retry the same chunk from the same
 *     cursor. After `deadlockRetryBudget` consecutive deadlocks on
 *     the same chunk, take the gap path: advance the cursor by the
 *     chunk size, increment `sweep_gap_count`, emit
 *     `sweep_gap_flagged`, continue.
 *
 * Note on the UPDATE WHERE clause: AC#3 specifies `(page, slot_column)
 * for id > sweep_cursor_id` with no tenant predicate; the blueprint
 * mermaid sketch shows `WHERE tenant_id = ?` but the registry row
 * carries no tenant once tombstoned and a slot column on a page is
 * owned by exactly one model by `ux_slot_assignments_page_column`. AC#3
 * is the resolution.
 *
 * SRP: this class only sweeps one slot; batch iteration and
 * `sweep_started` emission live on {@see Liberator}.
 */
final class SlotSweeper
{
    private const SLOT_COLUMN_PATTERN = '/^i_(str|int|num|dt)_(0[1-9]|[12]\d)$/';

    /** @var callable(int):void */
    private $sleepFn;

    /**
     * @param callable(int):void|null $sleepFn Injected for tests;
     *                                         defaults to `usleep`.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly LoggerInterface $logger,
        private readonly int $chunkSize,
        private readonly int $interChunkDelayMicros,
        private readonly int $deadlockRetryBudget,
        ?callable $sleepFn = null,
    ) {
        if ($this->chunkSize < 1) {
            throw new InvalidArgumentException('SlotSweeper chunkSize must be >= 1.');
        }
        if ($this->deadlockRetryBudget < 1) {
            throw new InvalidArgumentException('SlotSweeper deadlockRetryBudget must be >= 1.');
        }
        $this->sleepFn = $sleepFn ?? static fn (int $micros) => usleep($micros);
    }

    public function sweep(TombstonedSlot $slot, string $correlationId): void
    {
        // Validate the dynamic identifier before it ever reaches SQL.
        // Belt-and-braces on top of the repository's table-name check.
        if (preg_match(self::SLOT_COLUMN_PATTERN, $slot->slotColumn) !== 1) {
            throw new InvalidArgumentException(
                "SlotSweeper: '{$slot->slotColumn}' is not a recognised i_{str|int|num|dt}_NN identifier."
            );
        }

        $cursor = $slot->sweepCursorId ?? 0;
        $retryCount = 0;

        while (true) {
            $rowIds = $this->selectChunkRowIds($slot->tableName, $cursor);
            $rowCount = count($rowIds);
            $isLast = $rowCount < $this->chunkSize;
            $newCursor = $rowCount === 0 ? $cursor : (int) end($rowIds);

            $start = microtime(true);
            try {
                $this->commitChunk($slot, $rowIds, $newCursor, $isLast);
            } catch (PDOException $e) {
                if ($this->isDeadlock($e)) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    $retryCount++;

                    $this->logger->warning('slot sweep deadlock retry', [
                        'event'              => 'deadlock_retry',
                        'source'             => 'liberator',
                        'correlation_id'     => $correlationId,
                        'slot_assignment_id' => $slot->slotAssignmentId,
                        'attempt'            => $retryCount,
                        'cursor'             => $cursor,
                    ]);

                    if ($retryCount >= $this->deadlockRetryBudget) {
                        $gapEnd = $cursor + $this->chunkSize;
                        $this->commitGap($slot, $gapEnd);
                        $this->logger->warning('slot sweep gap flagged', [
                            'event'              => 'sweep_gap_flagged',
                            'source'             => 'liberator',
                            'correlation_id'     => $correlationId,
                            'slot_assignment_id' => $slot->slotAssignmentId,
                            'start_id'           => $cursor,
                            'end_id'             => $gapEnd,
                        ]);
                        $cursor = $gapEnd;
                        $retryCount = 0;
                        ($this->sleepFn)($this->interChunkDelayMicros);
                        continue;
                    }

                    ($this->sleepFn)($this->interChunkDelayMicros);
                    continue;
                }
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }

            $elapsedMs = (int) round((microtime(true) - $start) * 1000);
            $retryCount = 0;

            $this->logger->info('slot sweep chunk committed', [
                'event'              => 'sweep_chunk',
                'source'             => 'liberator',
                'correlation_id'     => $correlationId,
                'slot_assignment_id' => $slot->slotAssignmentId,
                'rows_nullified'     => $rowCount,
                'chunk_elapsed_ms'   => $elapsedMs,
                'sweep_cursor_id'    => $newCursor,
            ]);

            if ($isLast) {
                $this->logger->info('slot sweep complete', [
                    'event'              => 'sweep_complete',
                    'source'             => 'liberator',
                    'correlation_id'     => $correlationId,
                    'slot_assignment_id' => $slot->slotAssignmentId,
                    'sweep_cursor_id'    => $newCursor,
                ]);
                return;
            }

            ($this->sleepFn)($this->interChunkDelayMicros);
            $cursor = $newCursor;
        }
    }

    /** @return list<int> */
    private function selectChunkRowIds(string $tableName, int $cursor): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT entry_id FROM {$tableName} WHERE entry_id > ? ORDER BY entry_id LIMIT ?"
        );
        $stmt->bindValue(1, $cursor, PDO::PARAM_INT);
        $stmt->bindValue(2, $this->chunkSize, PDO::PARAM_INT);
        $stmt->execute();
        return array_map(static fn ($v) => (int) $v, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param list<int> $rowIds */
    private function commitChunk(TombstonedSlot $slot, array $rowIds, int $newCursor, bool $isLast): void
    {
        $this->pdo->beginTransaction();
        try {
            if ($rowIds !== []) {
                $placeholders = implode(',', array_fill(0, count($rowIds), '?'));
                $sql = "UPDATE {$slot->tableName} SET {$slot->slotColumn} = NULL WHERE entry_id IN ({$placeholders})";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($rowIds);
            }

            // Checkpoint the cursor in the same tx as the data DML.
            // The `AND status = 'tombstoned'` guard handles the (rare)
            // race where an operator resurrected the slot mid-sweep —
            // 0 rows affected means the next iteration will see the
            // updated state and the orchestrator's batch is stale.
            $stmt = $this->pdo->prepare(
                'UPDATE stardust_slot_assignments SET sweep_cursor_id = ?'
                . " WHERE id = ? AND status = 'tombstoned'"
            );
            $stmt->execute([$newCursor, $slot->slotAssignmentId]);

            if ($isLast) {
                // ADR 0017 §4.6 invariant: every coordination-relevant
                // status transition bumps schema_version in the same tx.
                // sweep_gap_count is intentionally preserved across the
                // reclaim — operators inspect it post-mortem; the
                // SlotReserver is the right place to reset it on the
                // next free → assigned transition.
                $stmt = $this->pdo->prepare(
                    "UPDATE stardust_slot_assignments SET status = 'free', field_id = NULL"
                    . " WHERE id = ? AND status = 'tombstoned'"
                );
                $stmt->execute([$slot->slotAssignmentId]);

                $this->pdo->exec(
                    'UPDATE stardust_schema_version SET version = version + 1, updated_at = UTC_TIMESTAMP() WHERE id = 1'
                );
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function commitGap(TombstonedSlot $slot, int $newCursor): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE stardust_slot_assignments'
                . ' SET sweep_cursor_id = ?, sweep_gap_count = sweep_gap_count + 1'
                . " WHERE id = ? AND status = 'tombstoned'"
            );
            $stmt->execute([$newCursor, $slot->slotAssignmentId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function isDeadlock(PDOException $e): bool
    {
        $info = $e->errorInfo;
        if (is_array($info) && isset($info[0]) && $info[0] === '40001') {
            return true;
        }
        if (is_array($info) && isset($info[1]) && (int) $info[1] === 1213) {
            return true;
        }
        return false;
    }
}
