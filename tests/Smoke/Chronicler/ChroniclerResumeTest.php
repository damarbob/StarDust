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
 * ADR 0047: the export resume anchor is the artifact file's verified
 * byte count, not `last_cursor` alone. This is the regression coverage
 * ROADMAP's Chronicler item asks for — a completed artifact after a
 * re-claim must contain every row of the filtered set, not merely
 * pass because the job reached `completed`.
 *
 * Complements {@see ChroniclerAbandonedClaimTest} (claim mechanics,
 * no-anchor case) and {@see ChroniclerLeaseLostTest} (the losing
 * worker's file survives). This file covers genuine byte-level
 * resume: a real anchor is adopted, every anchor-rejection cause, and
 * the two-worker interaction end to end.
 */
final class ChroniclerResumeTest extends Phase7TestCase
{
    /**
     * The flagship case. Worker A commits chunk 1 (4 of 10 rows), then
     * crashes mid-chunk-2 — after `appendRow()` has already flushed
     * rows 5-8 to disk but before the chunk-commit transaction
     * persists that progress. A fresh worker re-claims the stale row
     * and must produce a complete, non-duplicated 10-row artifact: the
     * uncommitted tail on disk is discarded by `ftruncate()` back to
     * the last *committed* anchor, then re-written cleanly.
     */
    public function testCrashMidChunkResumesWithoutDuplicatingOrLosingRows(): void
    {
        $modelId = $this->createModel(1, 'resume_csv');
        $this->createFieldNamed($modelId, 'idx', 'int');
        // entry_data.id is NOT reset between tests (ADR 0046 —
        // AUTO_INCREMENT survives so the Liberator's gap arithmetic
        // stays honest across the suite), so last_cursor must be
        // asserted against the actual returned ids, never a literal.
        $entryIds = $this->seedEntryDataBatch(1, $modelId, 10);

        $artifactDir = $this->makeTempArtifactDir();

        // Crash on the SECOND non-final chunk-commit UPDATE — i.e.,
        // after chunk 1 (rows 1-4) has already committed cleanly, and
        // after chunk 2's rows (5-8) have already been appendRow()'d
        // and flush()'d to the stream, but before that chunk's cursor
        // is persisted.
        $pdo = CrashInjectingPdo::wrap($this->pdo, crashOnCall: 2);

        $jobId = $this->seedExportJob(
            1, $modelId, 'processing', 'csv',
            workerIdentity: 'host:crashed:worker',
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
            workerIdentity: 'host:crashed:worker',
            claimKind: ClaimKind::Pending,
            skipCount: 0,
        );

        $processor = $this->makeProcessorWithPdo($pdo, $artifactDir, pageSize: 4);

        try {
            $processor->process($claim, 'corr-crash');
            self::fail('Expected the injected crash to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('simulated crash after appendRow, before commit', $e->getMessage());
        }

        // Confirm the fixture is genuinely mid-flight before asserting
        // the recovery: chunk 1 committed (cursor at the 4th entry's
        // id), but the file on disk already has chunk 2's rows
        // physically written past that committed anchor — the exact
        // defect shape ADR 0047 exists for.
        $row = $this->fetchExportJob($jobId);
        self::assertSame('processing', $row['status']);
        self::assertSame($entryIds[3], (int) $row['last_cursor']);
        self::assertNotNull($row['artifact_path']);
        $committedBytes = (int) $row['artifact_bytes'];
        self::assertGreaterThan(0, $committedBytes);
        self::assertGreaterThan(
            $committedBytes,
            (int) filesize((string) $row['artifact_path']),
            'The crashed chunk must have left uncommitted bytes on disk past the anchor.'
        );

        // Backdate the heartbeat so the abandoned-claim sweep fires.
        $this->backdateHeartbeat($jobId, 60);

        // A fresh worker (real Chronicler, unwrapped PDO) re-claims and
        // completes the job.
        $logger = $this->makeRecordingLogger();
        $this->makeChronicler($logger, artifactDir: $artifactDir, leaseTimeoutSeconds: 30)->tick();

        $final = $this->fetchExportJob($jobId);
        self::assertSame('completed', $final['status']);

        $rows = $this->readArtifactCsv((string) $final['artifact_path']);
        self::assertCount(10, $rows, 'Every row of the filtered set, exactly once.');
        self::assertSame(range(0, 9), array_map(static fn (array $r): int => (int) $r['idx'], $rows));

        $resumed = $this->recordsWithEvent($logger->records(), 'artifact_resumed');
        self::assertCount(1, $resumed);
        self::assertNull($resumed[0]['context']['restart_cause']);
        self::assertSame($committedBytes, $resumed[0]['context']['resumed_from_byte']);
    }

    /** Same defect shape, JSON format — proves the comma-prefix state survives the boundary. */
    public function testCrashMidChunkResumesJsonWithoutDuplicatingOrLosingRows(): void
    {
        $modelId = $this->createModel(1, 'resume_json');
        $this->createFieldNamed($modelId, 'idx', 'int');
        $this->seedEntryDataBatch(1, $modelId, 10);

        $artifactDir = $this->makeTempArtifactDir();
        $pdo = CrashInjectingPdo::wrap($this->pdo, crashOnCall: 2);

        $jobId = $this->seedExportJob(
            1, $modelId, 'processing', 'json',
            workerIdentity: 'host:crashed:worker-json',
            heartbeatAt: $this->utcNowString(),
            claimedAt: $this->utcNowString(),
        );

        $claim = new ClaimedJob(
            id: $jobId,
            tenantId: 1,
            modelId: $modelId,
            format: 'json',
            filter: ['model_id' => $modelId],
            lastCursor: null,
            workerIdentity: 'host:crashed:worker-json',
            claimKind: ClaimKind::Pending,
            skipCount: 0,
        );

        $processor = $this->makeProcessorWithPdo($pdo, $artifactDir, pageSize: 4);

        try {
            $processor->process($claim, 'corr-crash-json');
            self::fail('Expected the injected crash to propagate.');
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->backdateHeartbeat($jobId, 60);
        $this->makeChronicler(artifactDir: $artifactDir, leaseTimeoutSeconds: 30)->tick();

        $final = $this->fetchExportJob($jobId);
        self::assertSame('completed', $final['status']);

        $rows = $this->readArtifactJson((string) $final['artifact_path']);
        self::assertCount(10, $rows);
        self::assertSame(range(0, 9), array_map(static fn (array $r): int => (int) $r['idx'], $rows));

        // Exactly n-1 commas for n rows — proves no stray leading comma
        // and no missing one at the resume boundary.
        $raw = (string) file_get_contents((string) $final['artifact_path']);
        self::assertSame(9, substr_count($raw, ','));
    }

    public function testMissingAnchorFileFallsBackToFreshArtifact(): void
    {
        $modelId = $this->createModel(1, 'resume_missing');
        $this->createFieldNamed($modelId, 'idx', 'int');
        $this->seedEntryDataBatch(1, $modelId, 3);

        $nonexistent = $this->makeTempArtifactDir() . DIRECTORY_SEPARATOR . 'never-existed.csv';

        $logger = $this->makeRecordingLogger();
        $outcome = $this->processResumeClaim($modelId, $nonexistent, 5, $logger);

        self::assertSame(JobOutcome::Completed, $outcome->outcome);
        self::assertCount(3, $this->readArtifactCsv($outcome->artifactPath));
        $resumed = $this->recordsWithEvent($logger->records(), 'artifact_resumed');
        self::assertSame('missing', $resumed[0]['context']['restart_cause']);
        self::assertSame(0, $resumed[0]['context']['resumed_from_byte']);
    }

    public function testShortAnchorFileFallsBackToFreshArtifact(): void
    {
        $modelId = $this->createModel(1, 'resume_short');
        $this->createFieldNamed($modelId, 'idx', 'int');
        $this->seedEntryDataBatch(1, $modelId, 3);

        $path = $this->makeTempArtifactDir() . DIRECTORY_SEPARATOR . 'truncated.csv';
        file_put_contents($path, 'i'); // shorter than the claimed 100 bytes

        $logger = $this->makeRecordingLogger();
        $outcome = $this->processResumeClaim($modelId, $path, 100, $logger);

        self::assertSame(JobOutcome::Completed, $outcome->outcome);
        self::assertCount(3, $this->readArtifactCsv($outcome->artifactPath));
        $resumed = $this->recordsWithEvent($logger->records(), 'artifact_resumed');
        self::assertSame('short', $resumed[0]['context']['restart_cause']);
    }

    public function testHeaderMismatchFallsBackToFreshArtifact(): void
    {
        $modelId = $this->createModel(1, 'resume_header');
        $this->createFieldNamed($modelId, 'idx', 'int');
        $this->seedEntryDataBatch(1, $modelId, 3);

        // A field named "old_name" was resolved into this file by a
        // prior attempt; the field has since been renamed to "idx" —
        // simulates a rename landing between the two attempts.
        $path = $this->makeTempArtifactDir() . DIRECTORY_SEPARATOR . 'stale-header.csv';
        file_put_contents($path, "old_name\r\n");

        $logger = $this->makeRecordingLogger();
        $outcome = $this->processResumeClaim($modelId, $path, strlen("old_name\r\n"), $logger);

        self::assertSame(JobOutcome::Completed, $outcome->outcome);
        $rows = $this->readArtifactCsv($outcome->artifactPath);
        self::assertCount(3, $rows);
        self::assertArrayHasKey('idx', $rows[0]);
        $resumed = $this->recordsWithEvent($logger->records(), 'artifact_resumed');
        self::assertSame('header_mismatch', $resumed[0]['context']['restart_cause']);
    }

    public function testLockedAnchorFallsBackToFreshArtifact(): void
    {
        $modelId = $this->createModel(1, 'resume_locked');
        $this->createFieldNamed($modelId, 'idx', 'int');
        $this->seedEntryDataBatch(1, $modelId, 3);

        $path = $this->makeTempArtifactDir() . DIRECTORY_SEPARATOR . 'locked.csv';
        file_put_contents($path, "idx\r\n");

        // Hold an exclusive lock from this test process, simulating a
        // second live worker (or an unreleased handle) still touching
        // the same path.
        $holder = fopen($path, 'c+b');
        self::assertNotFalse($holder);
        self::assertTrue(flock($holder, LOCK_EX | LOCK_NB));

        try {
            $logger = $this->makeRecordingLogger();
            $outcome = $this->processResumeClaim($modelId, $path, strlen("idx\r\n"), $logger);

            self::assertSame(JobOutcome::Completed, $outcome->outcome);
            self::assertCount(3, $this->readArtifactCsv($outcome->artifactPath));
            $resumed = $this->recordsWithEvent($logger->records(), 'artifact_resumed');
            self::assertSame('locked', $resumed[0]['context']['restart_cause']);
        } finally {
            flock($holder, LOCK_UN);
            fclose($holder);
        }
    }

    /**
     * The zombie interaction: A commits chunk 1, then B genuinely
     * re-claims the row (worker_identity swapped out from under A) —
     * A's own next chunk-commit then discovers `rowCount() === 0`
     * mid-job and self-aborts with `lease_lost`, WITHOUT deleting the
     * file. B resumes from exactly where A left off and completes.
     */
    public function testZombieWorkerDoesNotCorruptTheReclaimersArtifact(): void
    {
        $modelId = $this->createModel(1, 'resume_zombie');
        $this->createFieldNamed($modelId, 'idx', 'int');
        $this->seedEntryDataBatch(1, $modelId, 10);

        $artifactDir = $this->makeTempArtifactDir();

        // On the SECOND non-final commit (chunk 2), simulate a real
        // re-claim: swap worker_identity on the row via a side-channel
        // write, then let A's own UPDATE proceed — it now affects zero
        // rows because its WHERE worker_identity=self no longer matches.
        $pdo = ReclaimSimulatingPdo::wrap($this->pdo, reclaimOnCall: 2, newWorkerIdentity: 'host:B:winner');

        $jobId = $this->seedExportJob(
            1, $modelId, 'processing', 'csv',
            workerIdentity: 'host:A:loser',
            heartbeatAt: $this->utcNowString(),
            claimedAt: $this->utcNowString(),
        );

        $claimA = new ClaimedJob(
            id: $jobId,
            tenantId: 1,
            modelId: $modelId,
            format: 'csv',
            filter: ['model_id' => $modelId],
            lastCursor: null,
            workerIdentity: 'host:A:loser',
            claimKind: ClaimKind::Pending,
            skipCount: 0,
        );

        $processorA = $this->makeProcessorWithPdo($pdo, $artifactDir, pageSize: 4);
        $outcomeA = $processorA->process($claimA, 'corr-a');

        self::assertSame(JobOutcome::LeaseLost, $outcomeA);

        $row = $this->fetchExportJob($jobId);
        self::assertSame('processing', $row['status']);
        self::assertSame('host:B:winner', $row['worker_identity']);
        $anchorPath  = (string) $row['artifact_path'];
        $anchorBytes = (int) $row['artifact_bytes'];
        self::assertTrue(is_file($anchorPath), 'A must not have deleted the artifact B is about to resume.');

        $claimB = new ClaimedJob(
            id: $jobId,
            tenantId: 1,
            modelId: $modelId,
            format: 'csv',
            filter: ['model_id' => $modelId],
            lastCursor: (int) $row['last_cursor'],
            workerIdentity: 'host:B:winner',
            claimKind: ClaimKind::Abandoned,
            skipCount: (int) $row['skip_count'],
            artifactPath: $anchorPath,
            artifactBytes: $anchorBytes,
        );

        $outcomeB = $this->makeProcessor(artifactDir: $artifactDir)->process($claimB, 'corr-b');

        self::assertSame(JobOutcome::Completed, $outcomeB);
        $final = $this->fetchExportJob($jobId);
        $rows = $this->readArtifactCsv((string) $final['artifact_path']);
        self::assertCount(10, $rows);
        self::assertSame(range(0, 9), array_map(static fn (array $r): int => (int) $r['idx'], $rows));
        self::assertSame($anchorPath, $final['artifact_path'], 'B adopted A\'s exact file, not a new one.');
    }

    /**
     * `bytesWritten()` must be SEEDED from the anchor, not reset to
     * zero, so the cumulative artifact-size cap stays honest across a
     * resume — a job that was already close to the cap before a
     * re-claim must not get a fresh budget.
     */
    public function testArtifactSizeCapStaysCumulativeAcrossResume(): void
    {
        $modelId = $this->createModel(1, 'resume_cap');
        $this->createFieldNamed($modelId, 'idx', 'int');
        $this->seedEntryDataBatch(1, $modelId, 3);

        $path = $this->makeTempArtifactDir() . DIRECTORY_SEPARATOR . 'near-cap.csv';
        $header = "idx\r\n";
        file_put_contents($path, $header);
        $anchorBytes = strlen($header);

        $claim = new ClaimedJob(
            id: $this->seedExportJob(
                1, $modelId, 'processing', 'csv',
                workerIdentity: 'host:cap:test',
                heartbeatAt: $this->utcNowString(),
                claimedAt: $this->utcNowString(),
            ),
            tenantId: 1,
            modelId: $modelId,
            format: 'csv',
            filter: ['model_id' => $modelId],
            lastCursor: null,
            workerIdentity: 'host:cap:test',
            claimKind: ClaimKind::Abandoned,
            skipCount: 0,
            artifactPath: $path,
            artifactBytes: $anchorBytes,
        );

        // Cap sits just above the anchor — the first appended row must
        // trip it, which is only possible if bytesWritten() started at
        // the anchor rather than at 0.
        $processor = $this->makeProcessor(
            artifactDir: dirname($path),
            artifactSizeCapBytes: $anchorBytes + 1,
        );
        $outcome = $processor->process($claim, 'corr-cap');

        self::assertSame(JobOutcome::FailedArtifactSizeExceeded, $outcome);
        $row = $this->fetchExportJob($claim->id);
        self::assertSame('failed', $row['status']);
        self::assertSame('artifact_size_exceeded', $row['failed_reason']);
    }

    // === Helpers ===

    private function backdateHeartbeat(int $jobId, int $secondsAgo): void
    {
        $stale = (new \DateTimeImmutable("-{$secondsAgo} seconds"))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('UPDATE stardust_export_jobs SET heartbeat_at = ? WHERE id = ?');
        $stmt->execute([$stale, $jobId]);
    }

    /**
     * Builds an abandoned claim carrying the given anchor and runs it
     * through a fresh processor, returning the outcome plus the row's
     * final artifact path (re-read from the DB — the anchor path is
     * only reused on a genuine resume).
     */
    private function processResumeClaim(
        int $modelId,
        string $anchorPath,
        int $anchorBytes,
        \Psr\Log\LoggerInterface $logger,
    ): ResumeOutcome {
        $jobId = $this->seedExportJob(
            1, $modelId, 'processing', 'csv',
            lastCursor: 999, // deliberately implausible; only trustworthy if the anchor verifies
            workerIdentity: 'host:resume:test',
            heartbeatAt: $this->utcNowString(),
            claimedAt: $this->utcNowString(),
        );

        $claim = new ClaimedJob(
            id: $jobId,
            tenantId: 1,
            modelId: $modelId,
            format: 'csv',
            filter: ['model_id' => $modelId],
            lastCursor: 999,
            workerIdentity: 'host:resume:test',
            claimKind: ClaimKind::Abandoned,
            skipCount: 0,
            artifactPath: $anchorPath,
            artifactBytes: $anchorBytes,
        );

        $outcome = $this->makeProcessor($logger, artifactDir: dirname($anchorPath))
            ->process($claim, 'corr-resume');

        $row = $this->fetchExportJob($jobId);
        return new ResumeOutcome($outcome, (string) $row['artifact_path']);
    }

    private function makeProcessorWithPdo(
        PDO $pdo,
        string $artifactDir,
        int $pageSize,
    ): ExportJobProcessor {
        return new ExportJobProcessor(
            pdo: $pdo,
            clock: new SystemClock(),
            logger: $this->makeRecordingLogger(),
            pager: new EntryDataPager($pdo),
            headerResolver: new HeaderResolver($pdo),
            streamFactory: new ArtifactStreamFactory($artifactDir),
            pageSize: $pageSize,
            interChunkDelayMicros: 0,
            deadlockRetryBudget: 3,
            skipCountCap: 1_000,
            artifactSizeCapBytes: 5 * 1024 * 1024 * 1024,
            dbDisconnectBackoffSeconds: [0, 0, 0],
            sleepFn: static fn (int $_micros) => null,
        );
    }
}

/** @internal Plain DTO for {@see ChroniclerResumeTest::processResumeClaim()}. */
final class ResumeOutcome
{
    public function __construct(
        public readonly JobOutcome $outcome,
        public readonly string $artifactPath,
    ) {
    }
}

/**
 * PDO decorator that throws a plain `RuntimeException` (deliberately
 * NOT a `PDOException` — this must NOT be caught by the deadlock/
 * disconnect handling, which only wraps the probe query) on the Nth
 * invocation of the chronicler's non-final chunk-commit UPDATE, after
 * the real statement's bind values have been captured but before the
 * write reaches the database — simulating a worker that dies between
 * flushing its artifact stream and persisting the chunk boundary.
 */
final class CrashInjectingPdo extends PDO
{
    private PDO $inner;
    private int $crashOnCall;
    private int $calls = 0;

    public static function wrap(PDO $inner, int $crashOnCall): self
    {
        $reflection = new ReflectionClass(self::class);
        /** @var self $instance */
        $instance = $reflection->newInstanceWithoutConstructor();
        $instance->inner = $inner;
        $instance->crashOnCall = $crashOnCall;
        return $instance;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $stmt = $this->inner->prepare($query, $options);
        if ($stmt === false) {
            return false;
        }
        // The non-final chunk-commit UPDATE: sets artifact_bytes but
        // never flips status to 'completed'.
        if (str_contains($query, 'stardust_export_jobs')
            && str_contains($query, 'artifact_bytes = ?')
            && !str_contains($query, "status = 'completed'")
        ) {
            return CrashInjectingStatement::wrap($stmt, $this->shouldCrash(...));
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

    private function shouldCrash(): bool
    {
        $this->calls++;
        return $this->calls === $this->crashOnCall;
    }
}

final class CrashInjectingStatement extends PDOStatement
{
    private PDOStatement $inner;
    /** @var callable():bool */
    private $shouldCrash;

    public static function wrap(PDOStatement $inner, callable $shouldCrash): self
    {
        $reflection = new ReflectionClass(self::class);
        /** @var self $instance */
        $instance = $reflection->newInstanceWithoutConstructor();
        $instance->inner = $inner;
        $instance->shouldCrash = $shouldCrash;
        return $instance;
    }

    public function execute(?array $params = null): bool
    {
        if (($this->shouldCrash)()) {
            throw new \RuntimeException('simulated crash after appendRow, before commit');
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

/**
 * PDO decorator that simulates a genuine concurrent re-claim: on the
 * Nth invocation of the chronicler's non-final chunk-commit UPDATE, it
 * first writes a NEW `worker_identity` onto the job row via a
 * side-channel statement against the real connection — exactly what
 * `ExportJobClaimer::claimAbandoned()` would do from a second process
 * — then lets the original statement execute normally. Because that
 * statement's `WHERE worker_identity = ?` still carries the ORIGINAL
 * (now stale) identity, it naturally affects zero rows, and
 * `ExportJobProcessor` discovers the lease loss exactly as it would in
 * production — no exception needed.
 */
final class ReclaimSimulatingPdo extends PDO
{
    private PDO $inner;
    private int $reclaimOnCall;
    private string $newWorkerIdentity;
    private int $calls = 0;

    public static function wrap(PDO $inner, int $reclaimOnCall, string $newWorkerIdentity): self
    {
        $reflection = new ReflectionClass(self::class);
        /** @var self $instance */
        $instance = $reflection->newInstanceWithoutConstructor();
        $instance->inner = $inner;
        $instance->reclaimOnCall = $reclaimOnCall;
        $instance->newWorkerIdentity = $newWorkerIdentity;
        return $instance;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $stmt = $this->inner->prepare($query, $options);
        if ($stmt === false) {
            return false;
        }
        if (str_contains($query, 'stardust_export_jobs')
            && str_contains($query, 'artifact_bytes = ?')
            && !str_contains($query, "status = 'completed'")
        ) {
            return ReclaimSimulatingStatement::wrap(
                $stmt,
                $this->inner,
                $this->shouldReclaim(...),
                $this->newWorkerIdentity,
            );
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

    private function shouldReclaim(): bool
    {
        $this->calls++;
        return $this->calls === $this->reclaimOnCall;
    }
}

final class ReclaimSimulatingStatement extends PDOStatement
{
    private PDOStatement $inner;
    private PDO $rawPdo;
    /** @var callable():bool */
    private $shouldReclaim;
    private string $newWorkerIdentity;

    public static function wrap(
        PDOStatement $inner,
        PDO $rawPdo,
        callable $shouldReclaim,
        string $newWorkerIdentity,
    ): self {
        $reflection = new ReflectionClass(self::class);
        /** @var self $instance */
        $instance = $reflection->newInstanceWithoutConstructor();
        $instance->inner = $inner;
        $instance->rawPdo = $rawPdo;
        $instance->shouldReclaim = $shouldReclaim;
        $instance->newWorkerIdentity = $newWorkerIdentity;
        return $instance;
    }

    public function execute(?array $params = null): bool
    {
        if (($this->shouldReclaim)() && is_array($params) && $params !== []) {
            // Side-channel: a second worker "wins" the row, exactly as
            // ExportJobClaimer::claimAbandoned() would from another
            // process — target by the CURRENT (still original)
            // worker_identity, which every chunk-commit UPDATE binds
            // as its last parameter. This runs on the same connection
            // as A's still-open chunk-commit transaction (InnoDB has
            // no reason to block it — no other transaction holds a
            // conflicting lock), and because it is the same connection
            // the write is immediately visible to A's own subsequent
            // statement below, exactly as a genuinely separate
            // connection's already-committed write would be.
            $originalIdentity = (string) end($params);
            $stmt = $this->rawPdo->prepare(
                'UPDATE stardust_export_jobs SET worker_identity = ? WHERE worker_identity = ?'
            );
            $stmt->execute([$this->newWorkerIdentity, $originalIdentity]);
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
