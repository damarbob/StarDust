<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Liberator;

use PDO;
use PDOException;
use PDOStatement;
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
        // 3 deadlock_retry → sweep_gap_flagged → second chunk succeeds
        // (sweep_chunk + sweep_complete because rows 11–20 = 10 rows
        // returned, next iteration sees 0 rows < chunk = isLast).
        self::assertSame(
            ['deadlock_retry', 'deadlock_retry', 'deadlock_retry', 'sweep_gap_flagged',
             'sweep_chunk', 'sweep_chunk', 'sweep_complete'],
            $events,
        );

        $row = $this->fetchSlotAssignment($slotAssignmentId);
        self::assertSame('free', $row['status']);
        self::assertSame(1, (int) $row['sweep_gap_count'], 'One gap must increment sweep_gap_count by 1.');
        // Rows 11-20 nullified; rows 1-10 still hold values (the gap).
        self::assertSame(10, $this->countNonNullValues($tableName, $slotColumn));
        self::assertNotNull($entryIds[0]);
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

    public static function wrap(PDO $inner, string $tableName, string $slotColumn, int $throwTimes): self
    {
        $reflection = new ReflectionClass(self::class);
        /** @var self $instance */
        $instance = $reflection->newInstanceWithoutConstructor();
        $instance->inner = $inner;
        $instance->remaining = $throwTimes;
        // Match the literal fragment SlotSweeper builds:
        //   UPDATE <table> SET <col> = NULL WHERE entry_id IN (...)
        $instance->targetSqlFragment = "UPDATE {$tableName} SET {$slotColumn} = NULL";
        return $instance;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $stmt = $this->inner->prepare($query, $options);
        if ($stmt === false) {
            return false;
        }
        if (str_contains($query, $this->targetSqlFragment) && $this->remaining > 0) {
            return DeadlockInjectingStatement::wrap($stmt, $this->consumeThrow(...));
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

    /** @param callable():int $shouldThrow Returns 1 to throw, 0 to pass through. */
    public static function wrap(PDOStatement $inner, callable $shouldThrow): self
    {
        $reflection = new ReflectionClass(self::class);
        /** @var self $instance */
        $instance = $reflection->newInstanceWithoutConstructor();
        $instance->inner = $inner;
        $instance->shouldThrow = $shouldThrow;
        return $instance;
    }

    public function execute(?array $params = null): bool
    {
        if (($this->shouldThrow)() === 1) {
            $e = new PDOException('Mock InnoDB deadlock injected by test fixture.');
            $e->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];
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
