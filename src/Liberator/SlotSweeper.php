<?php

declare(strict_types=1);

namespace StarDust\Liberator;

use InvalidArgumentException;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Throwable;
use StarDust\Support\RetryableLockFailure;

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
 *   3. `UPDATE stardust_slot_assignments SET sweep_cursor_id = :cursor`
 *      (guarded by `AND status = 'tombstoned'` so a racing operator
 *      cannot have us mutate a slot that has been resurrected). That
 *      is the chunk's last id normally, and the FIRST GAP's cursor once
 *      this pass has taken one — see the gap notes below. The same
 *      statement carries any `sweep_gap_count` owed since the last
 *      successful commit.
 *   4. On the final chunk (rows < chunkSize): same tx also flips
 *      `status='free', field_id=NULL` and bumps
 *      `stardust_schema_version.version`. Both sweep annotations are
 *      intentionally preserved here — operators inspect them
 *      post-mortem, and per ADR 0045 they are cleared by the *next*
 *      tombstone, not by the reclaim and not by the SlotReserver.
 *   5. COMMIT.
 *
 * Failure handling per ADR 0009 + blueprint AC#7/AC#8:
 *   - `SQLSTATE 40001` → rollback, emit `deadlock_retry`, sleep
 *     `interChunkDelayMicros`, retry the same chunk from the same
 *     cursor. After `deadlockRetryBudget` consecutive deadlocks on
 *     the same chunk, take the gap path: advance past **the chunk**
 *     (`max(last id in chunk, cursor + chunkSize)` — ADR 0046; the
 *     bare id arithmetic under-advances on a sparse page), count the
 *     gap, emit `sweep_gap_flagged`, continue. An **empty** chunk has
 *     nothing to skip, so it takes no gap — the pass returns and the
 *     slot waits for the next cycle. Without that the loop advances a
 *     cursor over rows that do not exist and never terminates.
 *   - **A sweep that took the gap path does not reclaim** (ADR 0046).
 *     ADR 0009 step 4 permits `tombstoned → free` only once the slot
 *     is confirmed 100% nullified, and a skipped chunk is not that.
 *     No `sweep_complete` fires, which is what AC#11 already specifies.
 *   - **While a gap is outstanding the durable cursor is pinned at the
 *     first one**, on every chunk rather than rewound at the end. The
 *     no-reclaim decision lives in a local, which does not survive the
 *     process; the cursor is what does. Rewinding only at the end left
 *     each intermediate commit past the skipped rows, so a daemon
 *     killed in that window restarted beyond them and reclaimed over
 *     residue.
 *   - **The gap annotation is not its own transaction.** It rides the
 *     next chunk's registry UPDATE, inside this retry budget. A
 *     separate `commitGap()` had no error handling and, being called
 *     from inside the retry `catch`, escaped `sweep()` when it failed —
 *     and `PollLoop` does not catch, so the daemon exited.
 *
 * Note on the UPDATE WHERE clause: the sweep is keyed on
 * `(page, slot_column) for id > sweep_cursor_id` with no tenant
 * predicate. The registry row carries no tenant once tombstoned
 * (`field_id = NULL`), and a slot column on a page is owned by exactly
 * one model via `ux_slot_assignments_page_column UNIQUE (page_id,
 * slot_column)`, so only one tenant ever held data in it and the
 * predicate is redundant. This was ratified in ADR 0029 (which refines
 * ADR 0009 on this point); liberator_daemon.md AC#3 is the normative
 * sweep shape.
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

        // ADR 0046: the lowest cursor at which this sweep abandoned a
        // chunk, or null if it has abandoned none. A sweep that skipped
        // anything has not "confirmed the slot is 100% nullified" (ADR
        // 0009 step 4), so it must not reclaim — and it rewinds to here
        // so the next pass re-walks from the first thing it missed
        // rather than from the beginning of the page.
        $firstGapCursor = null;

        // Gaps seen but not yet persisted; folded into the next commit.
        $pendingGapCount = 0;

        while (true) {
            $rowIds = $this->selectChunkRowIds($slot->tableName, $cursor);
            $rowCount = count($rowIds);
            $isLast = $rowCount < $this->chunkSize;
            $newCursor = $rowCount === 0 ? $cursor : (int) end($rowIds);

            // ADR 0046: while a gap is outstanding the durable cursor
            // is PINNED at the first one — on every chunk, not just the
            // last. Rewinding only at the end left every intermediate
            // commit sitting past the skipped rows, so a daemon killed
            // in that window restarted beyond them, saw no gap of its
            // own, and reclaimed over residue. The no-reclaim decision
            // lives in `$firstGapCursor`, which does not survive the
            // process; the cursor is what does.
            $reclaim = $isLast && $firstGapCursor === null;
            $storeCursor = $firstGapCursor ?? $newCursor;

            $start = microtime(true);
            try {
                $this->commitChunk($slot, $rowIds, $storeCursor, $reclaim, $pendingGapCount);
            } catch (PDOException $e) {
                if ($this->isRetryableLockFailure($e)) {
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
                        // ADR 0046: an EMPTY chunk has nothing to skip,
                        // so there is no gap to take. Abandon the pass
                        // and leave the slot tombstoned for the next
                        // cycle. Without this the loop advances a cursor
                        // over rows that do not exist, re-selects empty
                        // forever, and never terminates.
                        if ($rowIds === []) {
                            return;
                        }

                        // Skip the CHUNK, which is `LIMIT` rows.
                        // `$cursor + $chunkSize` is a span of *ids*, and
                        // the two coincide only where the page's
                        // entry_ids are dense from the cursor up. On a
                        // sparse page it under-advances, re-selects the
                        // same poisoned rows, and burns the budget again
                        // per chunkSize of id space crossed. `max()` is
                        // conservative: with a non-empty chunk it always
                        // picks $newCursor.
                        $gapEnd = max($newCursor, $cursor + $this->chunkSize);

                        // The annotation is NOT committed here. It rides
                        // the next chunk's registry UPDATE, which is
                        // already inside this retry budget. A separate
                        // transaction had no error handling of its own
                        // and, being called from inside this catch,
                        // escaped `sweep()` entirely when it failed —
                        // killing the daemon, since `PollLoop` does not
                        // catch. Deferring it removes the failure mode
                        // rather than handling it.
                        $pendingGapCount++;

                        $this->logger->warning('slot sweep gap flagged', [
                            'event'              => 'sweep_gap_flagged',
                            'source'             => 'liberator',
                            'correlation_id'     => $correlationId,
                            'slot_assignment_id' => $slot->slotAssignmentId,
                            // The chunk's own range, per blueprint AC 8 —
                            // not the cursor span, which named rows the
                            // sweep neither cleared nor skipped.
                            'start_id'           => $rowIds[0],
                            'end_id'             => $gapEnd,
                        ]);
                        $firstGapCursor ??= $cursor;
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
            $pendingGapCount = 0;

            $this->logger->info('slot sweep chunk committed', [
                'event'              => 'sweep_chunk',
                'source'             => 'liberator',
                'correlation_id'     => $correlationId,
                'slot_assignment_id' => $slot->slotAssignmentId,
                'rows_nullified'     => $rowCount,
                'chunk_elapsed_ms'   => $elapsedMs,
                'sweep_cursor_id'    => $storeCursor,
            ]);

            if ($isLast) {
                // ADR 0046: no reclaim, and therefore no `sweep_complete`,
                // when this sweep abandoned a chunk. Blueprint AC#11
                // defines the event as one per slot transitioned to
                // `free`, so staying silent here is the contract rather
                // than a hole in it — the operator's signal is the
                // `sweep_gap_flagged` that already fired, plus the
                // slot's age. Note `sweep_gap_count` is NOT a complete
                // stuck-slot signal: it rides the next successful chunk
                // commit, so a sweep that commits nothing leaves it at
                // zero. Operators query on `tombstoned_at`.
                if ($reclaim) {
                    $this->logger->info('slot sweep complete', [
                        'event'              => 'sweep_complete',
                        'source'             => 'liberator',
                        'correlation_id'     => $correlationId,
                        'slot_assignment_id' => $slot->slotAssignmentId,
                        'sweep_cursor_id'    => $storeCursor,
                    ]);
                }
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
        return array_values(
            array_map(static fn ($v) => (int) $v, $stmt->fetchAll(PDO::FETCH_COLUMN)),
        );
    }

    /**
     * @param list<int> $rowIds
     * @param int       $pendingGapCount gaps taken since the last successful
     *                                   commit, folded in here rather than
     *                                   written by a transaction of their own
     *                                   (ADR 0046)
     */
    private function commitChunk(
        TombstonedSlot $slot,
        array $rowIds,
        int $newCursor,
        bool $isLast,
        int $pendingGapCount = 0,
    ): void
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
            $gapClause = $pendingGapCount > 0
                ? ', sweep_gap_count = sweep_gap_count + ' . $pendingGapCount
                : '';
            $stmt = $this->pdo->prepare(
                'UPDATE stardust_slot_assignments SET sweep_cursor_id = ?' . $gapClause
                . " WHERE id = ? AND status = 'tombstoned'"
            );
            $stmt->execute([$newCursor, $slot->slotAssignmentId]);

            if ($isLast) {
                // ADR 0017 §4.6 invariant: every coordination-relevant
                // status transition bumps schema_version in the same tx.
                //
                // sweep_cursor_id and sweep_gap_count are intentionally
                // preserved across the reclaim, so an operator reading a
                // free or re-reserved slot still sees the annotations of
                // the sweep that produced it. ADR 0045 clears both in
                // the UPDATE that flips the NEXT tombstone — a sweep
                // therefore always starts at the beginning of the page,
                // and a recycled column can no longer resume from its
                // previous occupant's cursor.
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

    /**
     * Delegates to {@see RetryableLockFailure}, which is shared with
     * `ModelPurgeWorkSource` and the five Reconciler work sources.
     *
     * The 1205 half was added with ADR 0038, and it is not hypothetical.
     * A model purge deletes `entry_data` rows, which cascade into the
     * same `entry_slots_page_X` rows this sweeper is nullifying — and
     * severance tombstones every slot of the deleted model, so the
     * Liberator picks up precisely those slots. Measured on MySQL 8.0.13
     * in both directions: the loser gets **1205, never 1213**.
     *
     * Without this, that 1205 propagated past the retry budget and the
     * gap path, out of `sweep()`, and `PollLoop` deliberately does not
     * catch — so the Liberator daemon exited and crash-looped for the
     * duration of every model purge.
     *
     * A lock wait timeout is the more benign of the two: the transaction
     * rolls back whole, so the chunk is byte-for-byte re-executable from
     * the same cursor. It shares the existing budget and gap path rather
     * than getting its own.
     */
    private function isRetryableLockFailure(PDOException $e): bool
    {
        return RetryableLockFailure::matches($e);
    }
}
