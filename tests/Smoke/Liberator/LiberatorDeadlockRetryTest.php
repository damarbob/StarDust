<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Liberator;

use PDO;
use PDOException;
use PDOStatement;
use Psr\Log\NullLogger;
use ReflectionClass;
use StarDust\Clock\SystemClock;
use StarDust\Liberator\SlotSweeper;
use StarDust\Liberator\TombstonedSlot;
use StarDust\Logging\StdoutNdjsonLogger;
use StarDust\Tests\Smoke\Phase6aTestCase;

/**
 * Phase 6a exit-criterion #5: deadlock-bounded retry policy.
 *   - `SQLSTATE 40001` → rollback, retry the same chunk from the same
 *     cursor.
 *   - After {@see SlotSweeper::deadlockRetryBudget} consecutive
 *     deadlocks, skip ahead by `chunkSize`, increment
 *     `sweep_gap_count`, emit `sweep_gap_flagged`, continue.
 *
 * Deadlocks are simulated via a `PDO` subclass that injects 40001 on
 * the slot-column UPDATE statement N times before yielding to the
 * underlying connection. Real-world deadlocks would race with
 * concurrent writes on the same partition; the engine-level retry
 * surface is identical either way.
 */
final class LiberatorDeadlockRetryTest extends Phase6aTestCase
{
    public function testDeadlockRetryThenSuccess(): void
    {
        [$modelId, $fieldId, $pageId, $_fieldName] = $this->setupModelWithReservedField(1, 'string');
        $tableName = $this->pageTableNameFor($pageId);
        $slotAssignmentId = $this->slotAssignmentIdFor($fieldId);
        $slotColumn = $this->slotColumnFor($slotAssignmentId);

        $entryIds = $this->seedSlotValues($modelId, $tableName, $slotColumn, 5);
        $this->tombstoneSlotAssignment($slotAssignmentId);

        // Reuse the test PDO connection; the decorator injects 40001
        // once on the UPDATE then passes through.
        $pdo = DeadlockInjectingPdo::wrap($this->pdo, $tableName, $slotColumn, throwTimes: 1);

        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $logger = new StdoutNdjsonLogger(new SystemClock(), $stream);

        $sweeper = new SlotSweeper(
            pdo: $pdo,
            logger: $logger,
            chunkSize: 500,
            interChunkDelayMicros: 0,
            deadlockRetryBudget: 3,
            sleepFn: static fn (int $_micros) => null,
        );

        $slot = new TombstonedSlot(
            slotAssignmentId: $slotAssignmentId,
            pageId: $pageId,
            slotColumn: $slotColumn,
            tableName: $tableName,
            sweepCursorId: null,
        );

        $sweeper->sweep($slot, 'test-corr-deadlock');

        $events = array_map(static fn ($e) => $e['event'], $this->readNdjsonStream($stream));
        self::assertSame(['deadlock_retry', 'sweep_chunk', 'sweep_complete'], $events);

        self::assertSame(0, $this->countNonNullValues($tableName, $slotColumn));
        $row = $this->fetchSlotAssignment($slotAssignmentId);
        self::assertSame('free', $row['status']);
        self::assertSame(0, (int) $row['sweep_gap_count'], 'Successful retry must not bump sweep_gap_count.');
        self::assertSame($entryIds[4], (int) $row['sweep_cursor_id']);
    }

    /**
     * ADR 0038 regression: a **lock wait timeout (errno 1205)** is
     * retried, exactly as a deadlock is.
     *
     * Before ADR 0038 `SlotSweeper` tested for `SQLSTATE 40001` / errno
     * 1213 only. That was sufficient until model deletion existed;
     * measured on MySQL 8.0.13, the purge cascading `entry_data` deletes
     * into `entry_slots_page_X` contends with this sweeper over the same
     * rows and the loser gets **1205 in both directions, never 1213** —
     * and severance tombstones every slot of the deleted model, so the
     * Liberator is guaranteed to be sweeping precisely those slots.
     *
     * An unretried 1205 propagated out of `sweep()` past the gap path,
     * and `PollLoop` deliberately does not catch tick exceptions, so the
     * daemon exited and crash-looped for the duration of every model
     * purge. That is why the fix landed with the purge rather than after
     * it.
     */
    public function testLockWaitTimeoutIsRetriedLikeADeadlock(): void
    {
        [$modelId, $fieldId, $pageId, $_fieldName] = $this->setupModelWithReservedField(1, 'string');
        $tableName = $this->pageTableNameFor($pageId);
        $slotAssignmentId = $this->slotAssignmentIdFor($fieldId);
        $slotColumn = $this->slotColumnFor($slotAssignmentId);

        $entryIds = $this->seedSlotValues($modelId, $tableName, $slotColumn, 5);
        $this->tombstoneSlotAssignment($slotAssignmentId);

        $pdo = DeadlockInjectingPdo::wrap(
            $this->pdo,
            $tableName,
            $slotColumn,
            throwTimes: 1,
            errorInfo: DeadlockInjectingPdo::LOCK_WAIT_TIMEOUT,
        );

        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $logger = new StdoutNdjsonLogger(new SystemClock(), $stream);

        $sweeper = new SlotSweeper(
            pdo: $pdo,
            logger: $logger,
            chunkSize: 500,
            interChunkDelayMicros: 0,
            deadlockRetryBudget: 3,
            sleepFn: static fn (int $_micros) => null,
        );

        $slot = new TombstonedSlot(
            slotAssignmentId: $slotAssignmentId,
            pageId: $pageId,
            slotColumn: $slotColumn,
            tableName: $tableName,
            sweepCursorId: null,
        );

        // Without the fix this throws straight out and kills the daemon.
        $sweeper->sweep($slot, 'test-corr-lockwait');

        $events = array_map(static fn ($e) => $e['event'], $this->readNdjsonStream($stream));
        self::assertSame(['deadlock_retry', 'sweep_chunk', 'sweep_complete'], $events);

        self::assertSame(0, $this->countNonNullValues($tableName, $slotColumn));
        $row = $this->fetchSlotAssignment($slotAssignmentId);
        self::assertSame('free', $row['status'], 'The sweep must still complete and reclaim the slot.');
        self::assertSame(0, (int) $row['sweep_gap_count'], 'A successful retry must not bump sweep_gap_count.');
        self::assertSame($entryIds[4], (int) $row['sweep_cursor_id']);
    }

    public function testThreeConsecutiveDeadlocksTriggersSweepGap(): void
    {
        [$modelId, $fieldId, $pageId, $_fieldName] = $this->setupModelWithReservedField(1, 'string');
        $tableName = $this->pageTableNameFor($pageId);
        $slotAssignmentId = $this->slotAssignmentIdFor($fieldId);
        $slotColumn = $this->slotColumnFor($slotAssignmentId);

        // 20 rows, chunkSize=10. The decorator throws 40001 forever on
        // the first chunk's UPDATE — retry budget is 3, so the third
        // deadlock triggers the gap path, the cursor advances by
        // chunkSize=10, and the second chunk (rows 11–20) succeeds.
        $entryIds = $this->seedSlotValues($modelId, $tableName, $slotColumn, 20);
        $this->tombstoneSlotAssignment($slotAssignmentId);

        $pdo = DeadlockInjectingPdo::wrap($this->pdo, $tableName, $slotColumn, throwTimes: 3);

        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $logger = new StdoutNdjsonLogger(new SystemClock(), $stream);

        $sweeper = new SlotSweeper(
            pdo: $pdo,
            logger: $logger,
            chunkSize: 10,
            interChunkDelayMicros: 0,
            deadlockRetryBudget: 3,
            sleepFn: static fn (int $_micros) => null,
        );

        $slot = new TombstonedSlot(
            slotAssignmentId: $slotAssignmentId,
            pageId: $pageId,
            slotColumn: $slotColumn,
            tableName: $tableName,
            sweepCursorId: null,
        );

        $sweeper->sweep($slot, 'test-corr-gap');

        $events = array_map(static fn ($e) => $e['event'], $this->readNdjsonStream($stream));
        // 3 deadlock_retry -> sweep_gap_flagged -> second chunk succeeds
        // -> empty chunk closes the pass. **No `sweep_complete`**: per
        // ADR 0046 a sweep that abandoned a chunk does not reclaim, and
        // blueprint AC#11 defines that event as one per slot
        // transitioned to `free`.
        self::assertSame(
            ['deadlock_retry', 'deadlock_retry', 'deadlock_retry', 'sweep_gap_flagged',
             'sweep_chunk', 'sweep_chunk'],
            $events,
        );

        $row = $this->fetchSlotAssignment($slotAssignmentId);
        self::assertSame(
            'tombstoned',
            $row['status'],
            'ADR 0009 step 4 permits free only on a slot confirmed 100% nullified.',
        );
        self::assertSame(1, (int) $row['sweep_gap_count'], 'One gap must increment sweep_gap_count by 1.');
        // Rows 11-20 nullified; rows 1-10 still hold values (the gap).
        self::assertSame(10, $this->countNonNullValues($tableName, $slotColumn));
        self::assertNotNull($entryIds[0]);
        // Rewound to the first gap's cursor, not left at the end, so the
        // next pass re-walks the skipped range instead of sailing past it.
        self::assertSame(0, (int) $row['sweep_cursor_id']);
    }

    /**
     * ROADMAP item 23 - the gap must skip the CHUNK, not a span of ids.
     *
     * `$cursor + $chunkSize` is arithmetic on ids where ADR 0009 says
     * skip ahead by `LIMIT` rows. The two agree only where the page's
     * `entry_id` values are dense and start just above the cursor,
     * which is what dropping `entry_data` between tests used to
     * guarantee. On a sparse page the gap under-advances, re-selects
     * the same poisoned rows, and burns the whole retry budget again
     * for every `chunkSize` of *id space* it creeps forward.
     */
    public function testGapSkipsTheWholeChunkWhenPageIdsAreSparse(): void
    {
        [$modelId, $fieldId, $pageId, $_n] = $this->setupModelWithReservedField(1, 'string');
        $tableName = $this->pageTableNameFor($pageId);
        $slotAssignmentId = $this->slotAssignmentIdFor($fieldId);
        $slotColumn = $this->slotColumnFor($slotAssignmentId);

        // Two rows, a wide id gap, then two more - a model occupying one
        // page among several, or a page that has seen deletions.
        //
        // Bump RELATIVE to the ids just issued. An absolute
        // `AUTO_INCREMENT = 200` is silently a no-op once the counter is
        // already past it, and since ADR 0046 took `entry_data` out of
        // `SchemaFixture::IDENTITY_TABLES` it usually is - this test
        // passed alone and failed in the suite before that was fixed.
        $low = $this->seedSlotValues($modelId, $tableName, $slotColumn, 2);
        $this->pdo->exec('ALTER TABLE entry_data AUTO_INCREMENT = ' . ($low[1] + 200));
        $high = $this->seedSlotValues($modelId, $tableName, $slotColumn, 2);
        self::assertGreaterThan($low[1] + 50, $high[0], 'Fixture: ids must actually be sparse.');

        $this->tombstoneSlotAssignment($slotAssignmentId);

        // Throw forever on the nullification UPDATE, so every chunk gaps.
        $pdo = DeadlockInjectingPdo::wrap($this->pdo, $tableName, $slotColumn, throwTimes: PHP_INT_MAX);

        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $logger = new StdoutNdjsonLogger(new SystemClock(), $stream);

        $sweeper = new SlotSweeper(
            pdo: $pdo,
            logger: $logger,
            chunkSize: 2,
            interChunkDelayMicros: 0,
            deadlockRetryBudget: 3,
            sleepFn: static fn (int $_micros) => null,
        );

        $sweeper->sweep(
            new TombstonedSlot($slotAssignmentId, $pageId, $slotColumn, $tableName, null),
            'test-corr-sparse',
        );

        $events = array_map(static fn ($e) => $e['event'], $this->readNdjsonStream($stream));
        $gaps = count(array_filter($events, static fn ($e) => $e === 'sweep_gap_flagged'));

        // Two populated chunks, so two gaps. Advancing by chunkSize of
        // id space instead took 101 to cross the hole, measured.
        self::assertSame(2, $gaps, 'Each gap must consume one whole chunk.');
        self::assertSame(
            2,
            (int) $this->fetchSlotAssignment($slotAssignmentId)['sweep_gap_count'],
            'sweep_gap_count must count skipped chunks, not failed attempts to pass one.',
        );
    }

    /**
     * ADR 0046, the convergence half: the slot stays tombstoned, so the
     * next Liberator cycle picks it up, re-walks the gap now that the
     * contention has cleared, and only then reclaims.
     *
     * Without this the fix would be a capacity leak rather than a
     * correctness fix - it is what makes "do not reclaim" a deferral
     * instead of a refusal.
     */
    public function testTheNextPassClearsTheGapAndOnlyThenReclaims(): void
    {
        [$modelId, $fieldId, $pageId, $_n] = $this->setupModelWithReservedField(1, 'string');
        $tableName = $this->pageTableNameFor($pageId);
        $slotAssignmentId = $this->slotAssignmentIdFor($fieldId);
        $slotColumn = $this->slotColumnFor($slotAssignmentId);

        $this->seedSlotValues($modelId, $tableName, $slotColumn, 20);
        $this->tombstoneSlotAssignment($slotAssignmentId);

        // Pass 1: contention on the first chunk, so it gaps.
        $pdo = DeadlockInjectingPdo::wrap($this->pdo, $tableName, $slotColumn, throwTimes: 3);
        $sweeper = new SlotSweeper(
            pdo: $pdo,
            logger: new NullLogger(),
            chunkSize: 10,
            interChunkDelayMicros: 0,
            deadlockRetryBudget: 3,
            sleepFn: static fn (int $_micros) => null,
        );
        $sweeper->sweep(
            new TombstonedSlot($slotAssignmentId, $pageId, $slotColumn, $tableName, null),
            'test-corr-pass-1',
        );

        self::assertSame('tombstoned', $this->fetchSlotAssignment($slotAssignmentId)['status']);
        self::assertSame(10, $this->countNonNullValues($tableName, $slotColumn));

        // Pass 2: the real Liberator, contention gone. It re-loads the
        // slot from the registry, so it picks up the rewound cursor.
        $this->makeLiberator(chunkSize: 10)->tick();

        $row = $this->fetchSlotAssignment($slotAssignmentId);
        self::assertSame('free', $row['status'], 'A clean pass must reclaim.');
        self::assertSame(0, $this->countNonNullValues($tableName, $slotColumn));
        self::assertSame(
            1,
            (int) $row['sweep_gap_count'],
            'The gap annotation survives the reclaim as the record of what happened.',
        );
    }

    /**
     * ROADMAP item 25, defect A: a lock failure in the gap commit must
     * not kill the daemon.
     *
     * `commitGap()` used to rethrow, and it is called from inside
     * `sweep()`'s own retry handler, so its failure escaped past the
     * budget and the gap path both. `PollLoop` documents that it does
     * not catch, so the Liberator process exited.
     */
    public function testALockFailureInTheGapCommitDoesNotEscapeSweep(): void
    {
        [$modelId, $fieldId, $pageId, $_n] = $this->setupModelWithReservedField(1, 'string');
        $tableName = $this->pageTableNameFor($pageId);
        $slotAssignmentId = $this->slotAssignmentIdFor($fieldId);
        $slotColumn = $this->slotColumnFor($slotAssignmentId);

        $this->seedSlotValues($modelId, $tableName, $slotColumn, 20);
        $this->tombstoneSlotAssignment($slotAssignmentId);

        // Fail every registry cursor write. Under the old shape the
        // chunk retries exhaust, the gap commit is attempted, and its
        // own failure propagates straight out.
        $pdo = DeadlockInjectingPdo::wrap(
            $this->pdo,
            $tableName,
            $slotColumn,
            throwTimes: PHP_INT_MAX,
            errorInfo: DeadlockInjectingPdo::DEADLOCK,
            sqlFragment: 'UPDATE stardust_slot_assignments SET sweep_cursor_id',
        );

        $sweeper = new SlotSweeper(
            pdo: $pdo,
            logger: new NullLogger(),
            chunkSize: 10,
            interChunkDelayMicros: 0,
            deadlockRetryBudget: 3,
            sleepFn: static fn (int $_micros) => null,
        );

        // The assertion is that this returns at all.
        $sweeper->sweep(
            new TombstonedSlot($slotAssignmentId, $pageId, $slotColumn, $tableName, null),
            'test-corr-gapfail',
        );

        $row = $this->fetchSlotAssignment($slotAssignmentId);
        self::assertSame('tombstoned', $row['status'], 'Nothing committed, so nothing reclaims.');

        // The shape an operator sees for the WORST-stuck slot, and the
        // reason the runbook queries on `tombstoned_at` rather than on
        // `sweep_gap_count`: the counter rides the next successful chunk
        // commit, and here no commit succeeded, so it reads zero. A
        // query filtering on a non-zero count cannot find this slot.
        self::assertSame(0, (int) $row['sweep_gap_count']);
        self::assertNull($row['sweep_cursor_id']);
        self::assertSame(
            20,
            $this->countNonNullValues($tableName, $slotColumn),
            'Nothing was swept, which is why the slot must stay tombstoned.',
        );
    }

    /**
     * ROADMAP item 25, defect B: after a gap, no chunk may commit a
     * cursor past the skipped rows.
     *
     * ADR 0046 keeps its no-reclaim decision in `$firstGapCursor`,
     * which is in-memory, and only rewound the durable cursor on the
     * FINAL chunk. Every chunk in between committed its own high-water
     * mark - past the gap. A daemon killed in that window restarts
     * beyond the skipped rows, sees no gap of its own, and reclaims a
     * slot with residue: the bleed 0046 exists to close, reached
     * through the restart rather than through reuse.
     *
     * Asserted on the event stream because it is a property of every
     * intermediate commit, not just of the end state - a completed pass
     * rewinds and hides it.
     */
    public function testNoChunkAfterAGapCommitsACursorPastIt(): void
    {
        [$modelId, $fieldId, $pageId, $_n] = $this->setupModelWithReservedField(1, 'string');
        $tableName = $this->pageTableNameFor($pageId);
        $slotAssignmentId = $this->slotAssignmentIdFor($fieldId);
        $slotColumn = $this->slotColumnFor($slotAssignmentId);

        // 30 rows / chunk 10, so there is a committed chunk between the
        // gap and the final one. With 20 there is not, and the rewind
        // masks the defect entirely.
        $this->seedSlotValues($modelId, $tableName, $slotColumn, 30);
        $this->tombstoneSlotAssignment($slotAssignmentId);

        $pdo = DeadlockInjectingPdo::wrap($this->pdo, $tableName, $slotColumn, throwTimes: 3);

        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $logger = new StdoutNdjsonLogger(new SystemClock(), $stream);

        $sweeper = new SlotSweeper(
            pdo: $pdo,
            logger: $logger,
            chunkSize: 10,
            interChunkDelayMicros: 0,
            deadlockRetryBudget: 3,
            sleepFn: static fn (int $_micros) => null,
        );
        $sweeper->sweep(
            new TombstonedSlot($slotAssignmentId, $pageId, $slotColumn, $tableName, null),
            'test-corr-nopast',
        );

        $events = $this->readNdjsonStream($stream);

        $gapCursor = null;
        foreach ($events as $e) {
            if ($e['event'] === 'sweep_gap_flagged') {
                // The gap's own lower bound: the cursor it was standing at.
                $gapCursor ??= (int) $e['start_id'];
                continue;
            }
            if ($e['event'] === 'sweep_chunk' && $gapCursor !== null) {
                self::assertLessThanOrEqual(
                    $gapCursor,
                    (int) $e['sweep_cursor_id'],
                    'A chunk after a gap committed a cursor past the skipped rows; a crash here reclaims over them.',
                );
            }
        }
        self::assertNotNull($gapCursor, 'Fixture: the sweep must actually have gapped.');
    }

    private function slotAssignmentIdFor(int $fieldId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM stardust_slot_assignments WHERE field_id = ?');
        $stmt->execute([$fieldId]);
        return (int) $stmt->fetchColumn();
    }

    private function slotColumnFor(int $slotAssignmentId): string
    {
        $stmt = $this->pdo->prepare('SELECT slot_column FROM stardust_slot_assignments WHERE id = ?');
        $stmt->execute([$slotAssignmentId]);
        return (string) $stmt->fetchColumn();
    }
}

/**
 * PDO decorator that injects an InnoDB-deadlock failure (`SQLSTATE
 * 40001`) the first N times the sweeper's slot-column UPDATE is
 * executed on this connection, then transparently passes through to
 * the underlying connection. Construction takes the wrapped PDO so
 * the test's transactional state is shared end-to-end.
 *
 * PDO::__construct requires a live driver+DSN, so we bypass it via
 * ReflectionClass::newInstanceWithoutConstructor() and seed state
 * through the {@see self::wrap()} factory. We never call any inherited
 * PDO method directly — every public override delegates to
 * {@see self::$inner} — so the uninitialised parent is harmless.
 */
final class DeadlockInjectingPdo extends PDO
{
    private PDO $inner;
    private int $remaining;
    private string $targetSqlFragment;
    /** @var array{0: string, 1: int, 2: string} */
    private array $errorInfo;

    /** The InnoDB deadlock this fixture was originally written for. */
    public const DEADLOCK = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];

    /**
     * ADR 0038: the failure a concurrent model purge actually produces.
     *
     * Measured on MySQL 8.0.13 in both directions — the purge cascading
     * `entry_data` deletes into `entry_slots_page_X` versus this sweeper
     * nullifying the same rows — the loser gets errno **1205**, never
     * 1213. `SlotSweeper` matched only 40001/1213 until ADR 0038, so
     * this propagated past the retry budget and the gap path, out of
     * `sweep()`, and `PollLoop` deliberately does not catch: the
     * Liberator daemon exited and crash-looped for the whole purge.
     */
    public const LOCK_WAIT_TIMEOUT = ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'];

    /** @param array{0: string, 1: int, 2: string} $errorInfo */
    public static function wrap(
        PDO $inner,
        string $tableName,
        string $slotColumn,
        int $throwTimes,
        array $errorInfo = self::DEADLOCK,
        ?string $sqlFragment = null,
    ): self {
        $reflection = new ReflectionClass(self::class);
        /** @var self $instance */
        $instance = $reflection->newInstanceWithoutConstructor();
        $instance->inner = $inner;
        $instance->remaining = $throwTimes;
        $instance->errorInfo = $errorInfo;
        // Match the literal fragment SlotSweeper builds:
        //   UPDATE <table> SET <col> = NULL WHERE entry_id IN (...)
        // `$sqlFragment` overrides the default. An empty chunk issues no
        // nullification UPDATE at all, so the only way to fail one is to
        // target a registry statement it does issue.
        $instance->targetSqlFragment = $sqlFragment ?? "UPDATE {$tableName} SET {$slotColumn} = NULL";
        return $instance;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $stmt = $this->inner->prepare($query, $options);
        if ($stmt === false) {
            return false;
        }
        if (str_contains($query, $this->targetSqlFragment) && $this->remaining > 0) {
            return DeadlockInjectingStatement::wrap($stmt, $this->consumeThrow(...), $this->errorInfo);
        }
        return $stmt;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return $fetchMode === null
            ? $this->inner->query($query)
            : $this->inner->query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        return $this->inner->exec($statement);
    }

    public function beginTransaction(): bool
    {
        return $this->inner->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->inner->commit();
    }

    public function rollBack(): bool
    {
        return $this->inner->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->inner->lastInsertId($name);
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        return $this->inner->setAttribute($attribute, $value);
    }

    public function getAttribute(int $attribute): mixed
    {
        return $this->inner->getAttribute($attribute);
    }

    public function errorCode(): ?string
    {
        return $this->inner->errorCode();
    }

    public function errorInfo(): array
    {
        return $this->inner->errorInfo();
    }

    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        return $this->inner->quote($string, $type);
    }

    private function consumeThrow(): int
    {
        if ($this->remaining > 0) {
            $this->remaining--;
            return 1;
        }
        return 0;
    }
}

/**
 * PDOStatement decorator that throws `SQLSTATE 40001` on
 * {@see self::execute()} until the injected counter is exhausted, then
 * delegates to the real prepared statement.
 *
 * Same constructor-bypass trick as {@see DeadlockInjectingPdo}:
 * PDOStatement::__construct is private (only PDO may instantiate),
 * so we go through reflection and never touch any inherited state.
 */
final class DeadlockInjectingStatement extends PDOStatement
{
    private PDOStatement $inner;
    /** @var callable():int */
    private $shouldThrow;
    /** @var array{0: string, 1: int, 2: string} */
    private array $errorInfo;

    /**
     * @param callable():int $shouldThrow Returns 1 to throw, 0 to pass through.
     * @param array{0: string, 1: int, 2: string} $errorInfo
     */
    public static function wrap(
        PDOStatement $inner,
        callable $shouldThrow,
        array $errorInfo = DeadlockInjectingPdo::DEADLOCK,
    ): self {
        $reflection = new ReflectionClass(self::class);
        /** @var self $instance */
        $instance = $reflection->newInstanceWithoutConstructor();
        $instance->inner = $inner;
        $instance->shouldThrow = $shouldThrow;
        $instance->errorInfo = $errorInfo;
        return $instance;
    }

    public function execute(?array $params = null): bool
    {
        if (($this->shouldThrow)() === 1) {
            $e = new PDOException('Mock InnoDB lock failure injected by test fixture.');
            $e->errorInfo = $this->errorInfo;
            throw $e;
        }
        return $params === null ? $this->inner->execute() : $this->inner->execute($params);
    }

    public function bindValue(int|string $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        return $this->inner->bindValue($param, $value, $type);
    }

    public function bindParam(
        int|string $param,
        mixed &$var,
        int $type = PDO::PARAM_STR,
        int $maxLength = 0,
        mixed $driverOptions = null,
    ): bool {
        return $this->inner->bindParam($param, $var, $type, $maxLength, $driverOptions);
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->inner->fetch($mode, $cursorOrientation, $cursorOffset);
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->inner->fetchAll($mode, ...$args);
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->inner->fetchColumn($column);
    }

    public function rowCount(): int
    {
        return $this->inner->rowCount();
    }

    public function closeCursor(): bool
    {
        return $this->inner->closeCursor();
    }

    public function errorCode(): ?string
    {
        return $this->inner->errorCode();
    }

    public function errorInfo(): array
    {
        return $this->inner->errorInfo();
    }
}
