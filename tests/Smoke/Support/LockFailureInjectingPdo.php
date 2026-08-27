<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Support;

use PDO;
use PDOException;
use PDOStatement;
use ReflectionClass;

/**
 * A `PDO` decorator that throws an InnoDB lock failure on the first N
 * `execute()` calls of any statement whose SQL contains a given
 * fragment, then passes everything through to the real connection.
 *
 * ## Why reflection
 *
 * `PDO::__construct` requires a live driver and DSN, and
 * `PDOStatement::__construct` is private — only PDO may instantiate one.
 * `ReflectionClass::newInstanceWithoutConstructor()` is the cleanest way
 * to wrap the suite's real connection without opening a second one. The
 * decorator delegates every method to the inner object, so the bypassed
 * parent state is never touched. Same trick as
 * `LiberatorDeadlockRetryTest` and `ChroniclerDeadlockRetryTest`, which
 * predate this class and keep their own copies — theirs are hard-wired
 * to one statement shape each, this one takes the fragment as an
 * argument so every work source can share it.
 *
 * ## Inject 1205, not just 40001
 *
 * `LOCK_WAIT_TIMEOUT` is the default here on purpose. Measured on MySQL
 * 8.0.13, the collision this engine actually produces — a chunk writing
 * `entry_slots_page_X` while the Liberator nullifies the same rows —
 * gives the loser errno **1205**, never 1213, in both directions. A
 * fixture that only injected deadlocks would pass against a handler that
 * misses the case that really happens; that is exactly how the gap ADR
 * 0038 closed for the Liberator survived its own test.
 */
final class LockFailureInjectingPdo extends PDO
{
    private PDO $inner;
    private int $remaining;
    private string $targetSqlFragment;
    /** @var array{0: string, 1: int, 2: string} */
    private array $errorInfo;

    /** @var array{0: string, 1: int, 2: string} */
    public const DEADLOCK = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];

    /** @var array{0: string, 1: int, 2: string} */
    public const LOCK_WAIT_TIMEOUT = ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'];

    /**
     * @param string $sqlFragment  Literal substring identifying the statement to fail.
     * @param int    $throwTimes   How many `execute()` calls to fail before passing through.
     * @param array{0: string, 1: int, 2: string} $errorInfo
     */
    public static function wrap(
        PDO $inner,
        string $sqlFragment,
        int $throwTimes,
        array $errorInfo = self::LOCK_WAIT_TIMEOUT,
    ): self {
        $reflection = new ReflectionClass(self::class);
        /** @var self $instance */
        $instance = $reflection->newInstanceWithoutConstructor();
        $instance->inner = $inner;
        $instance->remaining = $throwTimes;
        $instance->errorInfo = $errorInfo;
        $instance->targetSqlFragment = $sqlFragment;

        return $instance;
    }

    /** Failures still owed, so a test can assert the budget was actually spent. */
    public function remainingFailures(): int
    {
        return $this->remaining;
    }

    /** @param array<mixed> $options */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $stmt = $this->inner->prepare($query, $options);
        if ($stmt === false) {
            return false;
        }
        if (str_contains($query, $this->targetSqlFragment) && $this->remaining > 0) {
            return LockFailureInjectingStatement::wrap($stmt, $this->consumeThrow(...), $this->errorInfo);
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

    /** @return array<int, mixed> */
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
 * The statement half of {@see LockFailureInjectingPdo}. Throws on
 * `execute()` while the injected counter is unspent, then delegates.
 */
final class LockFailureInjectingStatement extends PDOStatement
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
        array $errorInfo = LockFailureInjectingPdo::LOCK_WAIT_TIMEOUT,
    ): self {
        $reflection = new ReflectionClass(self::class);
        /** @var self $instance */
        $instance = $reflection->newInstanceWithoutConstructor();
        $instance->inner = $inner;
        $instance->shouldThrow = $shouldThrow;
        $instance->errorInfo = $errorInfo;

        return $instance;
    }

    /** @param array<mixed>|null $params */
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

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0,
    ): mixed {
        return $this->inner->fetch($mode, $cursorOrientation, $cursorOffset);
    }

    /** @return array<int, mixed> */
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

    /** @return array<int, mixed> */
    public function errorInfo(): array
    {
        return $this->inner->errorInfo();
    }
}
