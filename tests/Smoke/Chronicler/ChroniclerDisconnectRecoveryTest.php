<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Chronicler;

use PDO;
use PDOException;
use PDOStatement;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use StarDust\Chronicler\ArtifactStreamFactory;
use StarDust\Chronicler\ClaimKind;
use StarDust\Chronicler\ClaimedJob;
use StarDust\Chronicler\EntryDataPager;
use StarDust\Chronicler\ExportJobProcessor;
use StarDust\Chronicler\HeaderResolver;
use StarDust\Chronicler\JobOutcome;
use StarDust\Chronicler\PdoConnector;
use StarDust\Clock\SystemClock;
use StarDust\Tests\Smoke\Phase7TestCase;

/**
 * ADR 0025 commitment 6: a DB disconnect mid-pagination is recovered by
 * re-establishing the connection with the `[1, 4, 16]` backoff schedule
 * and resuming the in-flight job from `last_cursor` — NOT by re-pinging
 * the dead handle (which never heals; PHP PDO does not auto-reconnect).
 *
 *   - Transient blip → reconnect via the injected {@see PdoConnector},
 *     swap the live PDO + pager, resume, complete.
 *   - Schedule exhausted (DB still down) → `failed:query_failure`,
 *     `last_cursor` preserved, `job_failed{reason:db_disconnect_exhausted}`.
 *   - No connector wired → cannot reconnect → same terminal failure,
 *     with no wasted backoff sleeps.
 *
 * Disconnects are injected via a `PDO` subclass that flags the
 * `entry_data` SELECT — the bypass-constructor pattern shared with
 * {@see ChroniclerDeadlockRetryTest} wraps the live PDO so the fixture
 * survives intact. The {@see CountingPdoConnector} double either returns
 * the real connection (recovery) or throws (exhaustion).
 */
final class ChroniclerDisconnectRecoveryTest extends Phase7TestCase
{
    public function testReconnectResumesAndCompletes(): void
    {
        $modelId = $this->createModel(1, 'disconnect_recoverable');
        $this->createFieldNamed($modelId, 'k');
        $this->seedEntryDataBatch(1, $modelId, 3, static fn (int $i): array => ['k' => "v{$i}"]);

        // Throw 1 disconnect on the entry_data SELECT; the connector then
        // hands back a live connection and the chunk re-probes + succeeds.
        $pdo = DisconnectInjectingPdo::wrap($this->pdo, throwTimes: 1);
        $connector = new CountingPdoConnector($this->pdo);

        $jobId = $this->seedExportJob(
            1, $modelId, 'processing', 'csv',
            workerIdentity: 'host:test:reconnect',
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
            workerIdentity: 'host:test:reconnect',
            claimKind: ClaimKind::Pending,
            skipCount: 0,
        );

        $logger = $this->makeRecordingLogger();
        $processor = $this->makeProcessorWithPdoAndConnector($pdo, $logger, $connector, pageSize: 100);

        $outcome = $processor->process($claim, 'corr-reconnect');

        self::assertSame(JobOutcome::Completed, $outcome);
        self::assertSame(1, $connector->calls, 'Exactly one fresh connection should be built.');

        $row = $this->fetchExportJob($jobId);
        self::assertSame('completed', $row['status']);
        self::assertNull($row['failed_reason']);

        // The artifact must contain every row — proving the job resumed
        // from cursor 0 against the reconnected handle, not just survived.
        $artifact = $this->readArtifactCsv((string) $row['artifact_path']);
        self::assertCount(3, $artifact);
        self::assertSame(['v0', 'v1', 'v2'], array_column($artifact, 'k'));

        // No terminal failure event leaked out.
        self::assertCount(0, $this->recordsWithEvent($logger->records(), 'job_failed'));
    }

    public function testBackoffExhaustionFailsWithPreservedCursor(): void
    {
        $modelId = $this->createModel(1, 'disconnect_persistent');
        $this->createFieldNamed($modelId, 'k');
        $this->seedEntryDataBatch(1, $modelId, 3, static fn (int $i): array => ['k' => "v{$i}"]);

        // The entry_data SELECT always disconnects, and the connector
        // can never re-establish — every backoff attempt fails.
        $pdo = DisconnectInjectingPdo::wrap($this->pdo, throwTimes: PHP_INT_MAX);
        $connector = new CountingPdoConnector(null); // connect() throws

        // last_cursor=5 lets us prove the cursor is PRESERVED (not reset).
        $jobId = $this->seedExportJob(
            1, $modelId, 'processing', 'csv',
            lastCursor: 5,
            workerIdentity: 'host:test:exhaust',
            heartbeatAt: $this->utcNowString(),
            claimedAt: $this->utcNowString(),
        );

        $claim = new ClaimedJob(
            id: $jobId,
            tenantId: 1,
            modelId: $modelId,
            format: 'csv',
            filter: ['model_id' => $modelId],
            lastCursor: 5,
            workerIdentity: 'host:test:exhaust',
            claimKind: ClaimKind::Pending,
            skipCount: 0,
        );

        $logger = $this->makeRecordingLogger();
        $processor = $this->makeProcessorWithPdoAndConnector($pdo, $logger, $connector, pageSize: 100);

        $outcome = $processor->process($claim, 'corr-exhaust');

        self::assertSame(JobOutcome::FailedQueryFailure, $outcome);
        // One connect() attempt per backoff slot (schedule length 3).
        self::assertSame(3, $connector->calls);

        $row = $this->fetchExportJob($jobId);
        self::assertSame('failed', $row['status']);
        self::assertSame('query_failure', $row['failed_reason']);
        self::assertSame(5, (int) $row['last_cursor'], 'last_cursor must be preserved for restart.');

        $failed = $this->recordsWithEvent($logger->records(), 'job_failed');
        self::assertCount(1, $failed);
        self::assertSame('db_disconnect_exhausted', $failed[0]['context']['reason']);
        self::assertSame(5, $failed[0]['context']['last_cursor']);
    }

    public function testNoConnectorDegradesToCleanFailure(): void
    {
        $modelId = $this->createModel(1, 'disconnect_no_connector');
        $this->createFieldNamed($modelId, 'k');
        $this->seedEntryDataBatch(1, $modelId, 3, static fn (int $i): array => ['k' => "v{$i}"]);

        $pdo = DisconnectInjectingPdo::wrap($this->pdo, throwTimes: PHP_INT_MAX);

        $jobId = $this->seedExportJob(
            1, $modelId, 'processing', 'csv',
            lastCursor: 7,
            workerIdentity: 'host:test:noconn',
            heartbeatAt: $this->utcNowString(),
            claimedAt: $this->utcNowString(),
        );

        $claim = new ClaimedJob(
            id: $jobId,
            tenantId: 1,
            modelId: $modelId,
            format: 'csv',
            filter: ['model_id' => $modelId],
            lastCursor: 7,
            workerIdentity: 'host:test:noconn',
            claimKind: ClaimKind::Pending,
            skipCount: 0,
        );

        $logger = $this->makeRecordingLogger();
        // connector: null — the processor cannot reconnect and must fail
        // cleanly with the cursor preserved (honest degraded path).
        $processor = $this->makeProcessorWithPdoAndConnector($pdo, $logger, null, pageSize: 100);

        $outcome = $processor->process($claim, 'corr-noconn');

        self::assertSame(JobOutcome::FailedQueryFailure, $outcome);

        $row = $this->fetchExportJob($jobId);
        self::assertSame('failed', $row['status']);
        self::assertSame('query_failure', $row['failed_reason']);
        self::assertSame(7, (int) $row['last_cursor']);

        $failed = $this->recordsWithEvent($logger->records(), 'job_failed');
        self::assertCount(1, $failed);
        self::assertSame('db_disconnect_exhausted', $failed[0]['context']['reason']);
    }

    private function makeProcessorWithPdoAndConnector(
        PDO $pdo,
        LoggerInterface $logger,
        ?PdoConnector $connector,
        int $pageSize,
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
            deadlockRetryBudget: 3,
            skipCountCap: 1_000,
            artifactSizeCapBytes: 5 * 1024 * 1024 * 1024,
            dbDisconnectBackoffSeconds: [0, 0, 0],
            sleepFn: static fn (int $_micros) => null,
            connector: $connector,
        );
    }
}

/**
 * {@see PdoConnector} test double. Returns a pre-supplied live PDO
 * (recovery scenario) or throws a disconnect-shaped `PDOException`
 * (exhaustion scenario), counting every `connect()` call so a test can
 * assert how many reconnect attempts were made.
 */
final class CountingPdoConnector implements PdoConnector
{
    public int $calls = 0;

    public function __construct(private readonly ?PDO $pdo)
    {
    }

    public function connect(): PDO
    {
        $this->calls++;
        if ($this->pdo === null) {
            $e = new PDOException('Mock: DB still down (injected by disconnect test fixture).');
            $e->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];
            throw $e;
        }
        return $this->pdo;
    }
}

/**
 * PDO decorator that injects `2006 (MySQL server has gone away)` on the
 * chronicler's `entry_data` SELECT. Same constructor-bypass trick as
 * {@see DeadlockInjectingPdo} — `PDO::__construct` requires a live DSN,
 * so we instantiate via reflection and delegate every method to the
 * wrapped real connection.
 */
final class DisconnectInjectingPdo extends PDO
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
        if (str_contains($query, 'FROM entry_data')
            && str_contains($query, 'AND deleted_at IS NULL')
            && $this->remaining > 0
        ) {
            return DisconnectInjectingStatement::wrap($stmt, $this->consumeThrow(...));
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
 * PDOStatement decorator that throws `2006 (server has gone away)` on
 * execute() until the injected counter is exhausted, then passes through
 * to the real prepared statement. Mirrors
 * {@see DeadlockInjectingStatement}.
 */
final class DisconnectInjectingStatement extends PDOStatement
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
            $e = new PDOException('Mock: MySQL server has gone away (injected by Chronicler test fixture).');
            $e->errorInfo = ['HY000', 2006, 'MySQL server has gone away'];
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
