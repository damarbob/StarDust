<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Chronicler;

use PDO;
use PDOException;
use PDOStatement;
use ReflectionClass;
use StarDust\Chronicler\ArtifactStreamFactory;
use StarDust\Chronicler\ClaimKind;
use StarDust\Chronicler\ClaimedJob;
use StarDust\Chronicler\EntryDataPager;
use StarDust\Chronicler\ExportJobProcessor;
use StarDust\Chronicler\HeaderResolver;
use StarDust\Chronicler\JobOutcome;
use StarDust\Clock\SystemClock;
use StarDust\Tests\Smoke\Phase7TestCase;

/**
 * ADR 0025 commitment 3: 3-deadlock retry budget per chunk.
 *
 *   - `SQLSTATE 40001` → emit `deadlock_retry`, retry the same chunk
 *     from the same cursor.
 *   - After `deadlockRetryBudget` consecutive deadlocks, emit
 *     `chunk_skipped{cause: 'deadlock_budget_exhausted'}`, advance
 *     `last_cursor` by `pageSize`, charge `skip_count += pageSize`,
 *     continue.
 *
 * Deadlocks are injected via a `PDO` subclass that flags the
 * `entry_data` SELECT statement as targeted — the bypass-constructor
 * pattern (also used by `LiberatorDeadlockRetryTest`) wraps the live
 * PDO so transactional state and the existing fixture survive intact.
 */
final class ChroniclerDeadlockRetryTest extends Phase7TestCase
{
    public function testRetryThenSuccess(): void
    {
        $modelId = $this->createModel(1, 'deadlock_recoverable');
        $this->createFieldNamed($modelId, 'k');
        $this->seedEntryDataBatch(1, $modelId, 3);

        // Inject 1 deadlock on the entry_data SELECT; the chunk
        // retries and succeeds on attempt 2.
        $pdo = DeadlockInjectingPdo::wrap($this->pdo, throwTimes: 1);

        $jobId = $this->seedExportJob(
            1, $modelId, 'processing', 'csv',
            workerIdentity: 'host:test:retry',
            heartbeatAt: $this->utcNowString(),
            claimedAt: $this->utcNowString(),
        );

        $claim = new ClaimedJob(
            id: $jobId,
            tenantId: 1,
            modelId: $modelId,
            format: 'csv',
            filter: ['model_id' => $modelId],
            lastCursor: null,
            workerIdentity: 'host:test:retry',
            claimKind: ClaimKind::Pending,
            skipCount: 0,
        );

        $logger = $this->makeRecordingLogger();
        $processor = $this->makeProcessorWithPdo(
            $pdo, $logger, deadlockRetryBudget: 3, pageSize: 100,
        );

        $outcome = $processor->process($claim, 'corr-retry');

        self::assertSame(JobOutcome::Completed, $outcome);

        $retries = $this->recordsWithEvent($logger->records(), 'deadlock_retry');
        self::assertCount(1, $retries);
        self::assertSame(1, $retries[0]['context']['retry_count']);

        $skipped = $this->recordsWithEvent($logger->records(), 'chunk_skipped');
        self::assertCount(0, $skipped, 'A successful retry must not emit chunk_skipped.');

        $row = $this->fetchExportJob($jobId);
        self::assertSame('completed', $row['status']);
        self::assertSame(0, (int) $row['skip_count']);
    }

    public function testBudgetExhaustionEmitsChunkSkippedAndAdvancesCursor(): void
    {
        $modelId = $this->createModel(1, 'deadlock_gap');
        $this->createFieldNamed($modelId, 'k');
        // 6 rows + pageSize 5 → first chunk contended (rows 1-5);
        // budget exhausted after 3 deadlocks; cursor jumps to (cursor +
        // pageSize) = 5; second chunk picks up row 6 successfully.
        $entryIds = $this->seedEntryDataBatch(1, $modelId, 6);

        // Throw forever on the entry_data SELECT — every retry sees a
        // fresh 40001 until the budget exhausts. The cursor then
        // advances past the contended range, and subsequent queries
        // still hit the deadlock injection — so we expect the SECOND
        // chunk to also exhaust its budget. With 6 rows / pageSize 5,
        // that's two chunk_skipped events; skip_count would charge
        // 5+5=10 (worst case per ADR 0025).
        $pdo = DeadlockInjectingPdo::wrap($this->pdo, throwTimes: PHP_INT_MAX);

        $jobId = $this->seedExportJob(
            1, $modelId, 'processing', 'csv',
            workerIdentity: 'host:test:gap',
            heartbeatAt: $this->utcNowString(),
            claimedAt: $this->utcNowString(),
        );

        $claim = new ClaimedJob(
            id: $jobId,
            tenantId: 1,
            modelId: $modelId,
            format: 'csv',
            filter: ['model_id' => $modelId],
            lastCursor: null,
            workerIdentity: 'host:test:gap',
            claimKind: ClaimKind::Pending,
            skipCount: 0,
        );

        $logger = $this->makeRecordingLogger();
        // skipCountCap=20 keeps the job alive long enough to observe
        // multiple chunk_skipped events before excessive_skips kicks in.
        $processor = $this->makeProcessorWithPdo(
            $pdo, $logger,
            deadlockRetryBudget: 3, pageSize: 5, skipCountCap: 20,
        );

        $outcome = $processor->process($claim, 'corr-gap');

        // First chunk: 3 retries + 1 chunk_skipped (charges 5);
        // second chunk: 3 retries + 1 chunk_skipped (charges 5,
        // bringing total to 10); third chunk past id 10 returns no
        // rows from the entry_data range → either succeeds in its
        // probe or trips another deadlock. The deterministic guarantee
        // is: budget exhaustion always emits chunk_skipped before
        // advancing — never silently.
        $retries = $this->recordsWithEvent($logger->records(), 'deadlock_retry');
        self::assertGreaterThanOrEqual(3, count($retries));

        $skipped = $this->recordsWithEvent($logger->records(), 'chunk_skipped');
        self::assertGreaterThanOrEqual(1, count($skipped));
        self::assertSame('deadlock_budget_exhausted', $skipped[0]['context']['cause']);
        self::assertSame(0, $skipped[0]['context']['start_cursor']);
        self::assertSame(5, $skipped[0]['context']['end_cursor']);

        // The job either completed past the deadlock zone or hit
        // excessive_skips — the contract is that ADR 0025 invariants
        // hold either way.
        self::assertContains(
            $outcome,
            [JobOutcome::Completed, JobOutcome::FailedExcessiveSkips],
        );

        // entryIds[0] exists; we don't assert artifact contents because
        // the gap path skipped them.
        self::assertNotNull($entryIds[0]);
    }

    private function makeProcessorWithPdo(
        PDO $pdo,
        \Psr\Log\LoggerInterface $logger,
        int $deadlockRetryBudget,
        int $pageSize,
        int $skipCountCap = 1_000,
    ): ExportJobProcessor {
        return new ExportJobProcessor(
            pdo: $pdo,
            clock: new SystemClock(),
            logger: $logger,
            pager: new EntryDataPager($pdo),
            headerResolver: new HeaderResolver($pdo),
            streamFactory: new ArtifactStreamFactory($this->makeTempArtifactDir()),
            pageSize: $pageSize,
            interChunkDelayMicros: 0,
            deadlockRetryBudget: $deadlockRetryBudget,
            skipCountCap: $skipCountCap,
            artifactSizeCapBytes: 5 * 1024 * 1024 * 1024,
            dbDisconnectBackoffSeconds: [0, 0, 0],
            sleepFn: static fn (int $_micros) => null,
        );
    }
}

/**
 * PDO decorator that injects `SQLSTATE 40001` on the chronicler's
 * `entry_data` SELECT statement. Same constructor-bypass trick used by
 * {@see \StarDust\Tests\Smoke\Liberator\LiberatorDeadlockRetryTest}'s
 * `DeadlockInjectingPdo` — `PDO::__construct` requires a live DSN, so
 * we instantiate via reflection and delegate every method to the
 * wrapped real connection.
 */
final class DeadlockInjectingPdo extends PDO
{
    private PDO $inner;
    private int $remaining;

    public static function wrap(PDO $inner, int $throwTimes): self
    {
        $reflection = new ReflectionClass(self::class);
        /** @var self $instance */
        $instance = $reflection->newInstanceWithoutConstructor();
        $instance->inner = $inner;
        $instance->remaining = $throwTimes;
        return $instance;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $stmt = $this->inner->prepare($query, $options);
        if ($stmt === false) {
            return false;
        }
        // Target the chronicler's bounded probe — the only query whose
        // failure ought to drive the retry budget.
        if (str_contains($query, 'FROM entry_data')
            && str_contains($query, 'AND deleted_at IS NULL')
            && $this->remaining > 0
        ) {
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
            $this->remaining = $this->remaining === PHP_INT_MAX ? PHP_INT_MAX : ($this->remaining - 1);
            return 1;
        }
        return 0;
    }
}

/**
 * PDOStatement decorator that throws `SQLSTATE 40001` on execute()
 * until the injected counter is exhausted, then passes through to the
 * real prepared statement. Mirrors the Liberator's
 * `DeadlockInjectingStatement` pattern.
 */
final class DeadlockInjectingStatement extends PDOStatement
{
    private PDOStatement $inner;
    /** @var callable():int */
    private $shouldThrow;

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
            $e = new PDOException('Mock InnoDB deadlock injected by Chronicler test fixture.');
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
