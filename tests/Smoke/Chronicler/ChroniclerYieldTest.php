<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Chronicler;

use PDO;
use PDOStatement;
use Psr\Log\NullLogger;
use ReflectionClass;
use StarDust\Chronicler\ArtifactStreamFactory;
use StarDust\Chronicler\ClaimKind;
use StarDust\Chronicler\ClaimedJob;
use StarDust\Chronicler\EntryDataPager;
use StarDust\Chronicler\ExportJobClaimer;
use StarDust\Chronicler\ExportJobProcessor;
use StarDust\Chronicler\HeaderResolver;
use StarDust\Chronicler\JobOutcome;
use StarDust\Clock\SystemClock;
use StarDust\Export\ExportJobRequest;
use StarDust\Tests\Smoke\Phase7TestCase;
use StarDust\Tests\Smoke\Support\ScriptedYieldSignal;

/**
 * ADR 0050: cooperative yield at a chunk boundary. An in-flight export
 * returns to `pending` with its ADR 0047 resume anchor intact rather
 * than running to completion, so `bin/stardust tick --exports` can
 * bound export work like everything else it composes.
 *
 * Complements {@see ChroniclerResumeTest} (the anchor mechanics a
 * yield depends on) and {@see ChroniclerLeaseLostTest} (the ordinary,
 * non-yield lease-loss path this one's race variant mirrors).
 */
final class ChroniclerYieldTest extends Phase7TestCase
{
    public function testAlwaysYieldCommitsExactlyOneChunkAndReturnsToPending(): void
    {
        $modelId = $this->createModel(1, 'yield_always');
        $this->createFieldNamed($modelId, 'idx', 'int');
        $entryIds = $this->seedEntryDataBatch(1, $modelId, 10);
        $artifactDir = $this->makeTempArtifactDir();

        $jobId = $this->seedExportJob(
            1, $modelId, 'processing', 'csv',
            workerIdentity: 'host:yield:test',
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
            workerIdentity: 'host:yield:test',
            claimKind: ClaimKind::Pending,
            skipCount: 0,
        );

        $logger = $this->makeRecordingLogger();
        $processor = $this->makeProcessor($logger, artifactDir: $artifactDir, pageSize: 4);
        $outcome = $processor->process($claim, 'corr-always-yield', new ScriptedYieldSignal(afterCalls: 1));

        self::assertSame(JobOutcome::Yielded, $outcome);

        $row = $this->fetchExportJob($jobId);
        self::assertSame('pending', $row['status']);
        self::assertNull($row['worker_identity']);
        self::assertNull($row['completed_at']);
        self::assertSame($entryIds[3], (int) $row['last_cursor'], 'Exactly one committed chunk (pageSize 4).');
        self::assertSame(0, (int) $row['skip_count']);
        self::assertNotNull($row['artifact_path']);
        self::assertGreaterThan(0, (int) $row['artifact_bytes']);
        self::assertSame(
            (int) $row['artifact_bytes'],
            (int) filesize((string) $row['artifact_path']),
            'The artifact on disk must hold exactly what the row promises — the yield closed the stream.',
        );

        self::assertCount(1, $this->recordsWithEvent($logger->records(), 'chunk_written'));
        self::assertCount(1, $this->recordsWithEvent($logger->records(), 'job_yielded'));
        self::assertCount(0, $this->recordsWithEvent($logger->records(), 'job_complete'));

        $yielded = $this->recordsWithEvent($logger->records(), 'job_yielded');
        self::assertSame('budget', $yielded[0]['context']['cause']);
        self::assertSame(4, $yielded[0]['context']['rows_streamed_total']);
    }

    public function testYieldedCsvExportConvergesToAByteIdenticalArtifact(): void
    {
        [$controlPath, $yieldedPath] = $this->runControlAndYieldedExports('csv');
        self::assertSame(
            (string) file_get_contents($controlPath),
            (string) file_get_contents($yieldedPath),
            'A job that yields at every chunk boundary must produce the exact same artifact'
            . ' bytes as one that never yields — a weaker "reached completed" assertion would'
            . ' pass even if a yielded resume restarted from byte zero.',
        );
    }

    public function testYieldedJsonExportConvergesToAByteIdenticalArtifact(): void
    {
        [$controlPath, $yieldedPath] = $this->runControlAndYieldedExports('json');
        self::assertSame(
            (string) file_get_contents($controlPath),
            (string) file_get_contents($yieldedPath),
        );
    }

    public function testResumedClaimReportsUsableAnchorAndGenuineRestart(): void
    {
        $modelId = $this->createModel(1, 'yield_resumed');
        $this->createFieldNamed($modelId, 'idx', 'int');
        $entryIds = $this->seedEntryDataBatch(1, $modelId, 10);
        $artifactDir = $this->makeTempArtifactDir();

        $jobId = $this->seedExportJob(1, $modelId, 'pending', 'csv');
        $claimer = new ExportJobClaimer($this->pdo, new SystemClock(), leaseTimeoutSeconds: 30);

        $firstClaim = $claimer->claimPendingOrAbandoned();
        self::assertNotNull($firstClaim);
        self::assertSame(ClaimKind::Pending, $firstClaim->claimKind, 'Never-run submission: no anchor to report.');
        self::assertNull($firstClaim->artifactPath);

        $processor = $this->makeProcessor(artifactDir: $artifactDir, pageSize: 4);
        $outcome = $processor->process($firstClaim, 'corr-resumed', new ScriptedYieldSignal(afterCalls: 1));
        self::assertSame(JobOutcome::Yielded, $outcome);

        $secondClaim = $claimer->claimPendingOrAbandoned();
        self::assertNotNull($secondClaim);
        self::assertSame(ClaimKind::Resumed, $secondClaim->claimKind, 'A pending row carrying a usable anchor.');
        self::assertNotNull($secondClaim->artifactPath);
        self::assertNotNull($secondClaim->artifactBytes);
        self::assertGreaterThan(0, $secondClaim->artifactBytes);
        self::assertSame($entryIds[3], $secondClaim->lastCursor);

        $logger = $this->makeRecordingLogger();
        $finalOutcome = $this->makeProcessor($logger, artifactDir: $artifactDir, pageSize: 4)
            ->process($secondClaim, 'corr-resumed', null);
        self::assertSame(JobOutcome::Completed, $finalOutcome);

        // The yield's stronger-than-abandoned guarantee: the lock was
        // released before the row became claimable, so the resumer
        // never sees restart_cause: 'locked', and the file was never
        // touched by anything else, so it never sees any other
        // fallback cause either.
        $resumed = $this->recordsWithEvent($logger->records(), 'artifact_resumed');
        self::assertCount(1, $resumed);
        self::assertGreaterThan(0, $resumed[0]['context']['resumed_from_byte']);
        self::assertNull($resumed[0]['context']['restart_cause']);

        $final = $this->fetchExportJob($jobId);
        self::assertSame('completed', $final['status']);
        $rows = $this->readArtifactCsv((string) $final['artifact_path']);
        self::assertCount(10, $rows);
        self::assertSame(range(0, 9), array_map(static fn (array $r): int => (int) $r['idx'], $rows));
    }

    public function testNeverYieldsOnTheFinalOrOnlyChunk(): void
    {
        $modelId = $this->createModel(1, 'yield_final_only');
        $this->createFieldNamed($modelId, 'idx', 'int');
        $this->seedEntryDataBatch(1, $modelId, 3); // one chunk at pageSize 10

        $jobId = $this->seedExportJob(
            1, $modelId, 'processing', 'csv',
            workerIdentity: 'host:only-chunk',
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
            workerIdentity: 'host:only-chunk',
            claimKind: ClaimKind::Pending,
            skipCount: 0,
        );

        // Configured to yield on the very first check — proves the
        // final chunk never offers one, rather than merely that this
        // particular fixture happened not to trigger it.
        $yieldSignal = new ScriptedYieldSignal(afterCalls: 1);
        $processor = $this->makeProcessor(artifactDir: $this->makeTempArtifactDir(), pageSize: 10);
        $outcome = $processor->process($claim, 'corr-final-only', $yieldSignal);

        self::assertSame(JobOutcome::Completed, $outcome);
        self::assertSame(0, $yieldSignal->callCount(), 'The final (=only) chunk must never consult the yield signal.');
        self::assertSame('completed', $this->fetchExportJob($jobId)['status']);
    }

    /**
     * The yield-commit UPDATE carries the SAME `WHERE worker_identity =
     * self` lease-loss detector as any other chunk commit. A genuine
     * concurrent re-claim landing between this worker's `appendRow()`s
     * and its yield attempt must still resolve to `lease_lost`, with
     * the row left `processing` under the winner's identity and this
     * worker's (already-closed) artifact left on disk untouched.
     */
    public function testYieldRacingAConcurrentReclaimReportsLeaseLost(): void
    {
        $modelId = $this->createModel(1, 'yield_race');
        $this->createFieldNamed($modelId, 'idx', 'int');
        // 20 rows / pageSize 4 = 5 chunks (4 non-final + 1 final), so
        // chunk 1 can commit normally — giving the DB row a real anchor
        // — before chunk 2 is where the yield-vs-race collision below
        // actually lands.
        $this->seedEntryDataBatch(1, $modelId, 20);
        $artifactDir = $this->makeTempArtifactDir();

        // On the SECOND non-final chunk-commit call — the one
        // ScriptedYieldSignal(afterCalls: 2) turns into a yield attempt
        // — swap worker_identity out from under this worker via a
        // side-channel write on the same connection, exactly as
        // ChroniclerResumeTest's ReclaimSimulatingPdo does for the
        // ordinary (non-yield) lease-loss path. Chunk 1 commits
        // normally first, so the DB row already carries a real anchor
        // by the time the race hits.
        $pdo = YieldRaceReclaimingPdo::wrap($this->pdo, reclaimOnCall: 2, newWorkerIdentity: 'host:winner');

        $jobId = $this->seedExportJob(
            1, $modelId, 'processing', 'csv',
            workerIdentity: 'host:loser',
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
            workerIdentity: 'host:loser',
            claimKind: ClaimKind::Pending,
            skipCount: 0,
        );

        $processor = new ExportJobProcessor(
            pdo: $pdo,
            clock: new SystemClock(),
            logger: new NullLogger(),
            pager: new EntryDataPager($pdo),
            headerResolver: new HeaderResolver($pdo),
            streamFactory: new ArtifactStreamFactory($artifactDir),
            pageSize: 4,
            interChunkDelayMicros: 0,
            deadlockRetryBudget: 3,
            skipCountCap: 1_000,
            artifactSizeCapBytes: 5 * 1024 * 1024 * 1024,
            dbDisconnectBackoffSeconds: [0, 0, 0],
            sleepFn: static fn (int $_micros) => null,
        );

        $outcome = $processor->process($claim, 'corr-race', new ScriptedYieldSignal(afterCalls: 2));

        self::assertSame(JobOutcome::LeaseLost, $outcome);

        $row = $this->fetchExportJob($jobId);
        self::assertSame('processing', $row['status'], 'The loser\'s yield commit never landed — status stays as the winner left it.');
        self::assertSame('host:winner', $row['worker_identity']);
        self::assertNotNull($row['artifact_path'], 'Chunk 1\'s normal commit already anchored the row.');
        self::assertTrue(is_file((string) $row['artifact_path']), 'The losing worker must not delete the artifact.');
    }

    /**
     * Fail-closed: the anchor a yield left behind can vanish between
     * ticks (disk cleanup, an operator's mistake). The re-claim still
     * reports {@see ClaimKind::Resumed} from the DB row alone — the
     * claimer cannot see the filesystem — but the stream's own
     * verification falls back to a fresh artifact rather than losing
     * or duplicating rows.
     */
    public function testDeletedAnchorBetweenYieldsFallsBackToFreshArtifactExactlyOnce(): void
    {
        $modelId = $this->createModel(1, 'yield_deleted_anchor');
        $this->createFieldNamed($modelId, 'idx', 'int');
        $this->seedEntryDataBatch(1, $modelId, 10);
        $artifactDir = $this->makeTempArtifactDir();

        $jobId = $this->seedExportJob(1, $modelId, 'pending', 'csv');
        $claimer = new ExportJobClaimer($this->pdo, new SystemClock(), leaseTimeoutSeconds: 30);

        $firstClaim = $claimer->claimPendingOrAbandoned();
        self::assertNotNull($firstClaim);
        $outcome = $this->makeProcessor(artifactDir: $artifactDir, pageSize: 4)
            ->process($firstClaim, 'corr-deleted-anchor', new ScriptedYieldSignal(afterCalls: 1));
        self::assertSame(JobOutcome::Yielded, $outcome);

        $mid = $this->fetchExportJob($jobId);
        self::assertNotNull($mid['artifact_path']);
        self::assertTrue(unlink((string) $mid['artifact_path']), 'Simulates the anchor vanishing between ticks.');

        $secondClaim = $claimer->claimPendingOrAbandoned();
        self::assertNotNull($secondClaim);
        self::assertSame(ClaimKind::Resumed, $secondClaim->claimKind, 'The row still looks resumable from the DB alone.');

        $logger = $this->makeRecordingLogger();
        $finalOutcome = $this->makeProcessor($logger, artifactDir: $artifactDir, pageSize: 4)
            ->process($secondClaim, 'corr-deleted-anchor', null);
        self::assertSame(JobOutcome::Completed, $finalOutcome);

        $resumed = $this->recordsWithEvent($logger->records(), 'artifact_resumed');
        self::assertCount(1, $resumed);
        self::assertSame('missing', $resumed[0]['context']['restart_cause']);
        self::assertSame(0, $resumed[0]['context']['resumed_from_byte']);

        $final = $this->fetchExportJob($jobId);
        $rows = $this->readArtifactCsv((string) $final['artifact_path']);
        self::assertCount(10, $rows, 'Every row exactly once, even though the anchor vanished mid-flight.');
        self::assertSame(range(0, 9), array_map(static fn (array $r): int => (int) $r['idx'], $rows));
    }

    public function testJobYieldedCarriesTheSubmissionsCorrelationId(): void
    {
        $modelId = $this->createModel(1, 'yield_correlation');
        $this->createFieldNamed($modelId, 'idx', 'int');
        $this->seedEntryDataBatch(1, $modelId, 10);
        $artifactDir = $this->makeTempArtifactDir();

        $jobId = $this->makeExportSubmitter()
            ->submit(new ExportJobRequest(1, $modelId, 'csv', correlationId: 'submission-corr-id'))
            ->jobId;

        $logger = $this->makeRecordingLogger();
        $this->makeChronicler(
            $logger,
            artifactDir: $artifactDir,
            pageSize: 4, // multiple chunks needed, or the 10-row job never sees a non-final one
            leaseTimeoutSeconds: 30,
            yieldSignal: new ScriptedYieldSignal(afterCalls: 1),
        )->tick();

        $row = $this->fetchExportJob($jobId);
        self::assertSame('pending', $row['status']);
        self::assertSame('submission-corr-id', $row['correlation_id']);

        $claimed = $this->recordsWithEvent($logger->records(), 'job_claimed');
        $yielded = $this->recordsWithEvent($logger->records(), 'job_yielded');
        self::assertCount(1, $claimed);
        self::assertCount(1, $yielded);
        self::assertSame('submission-corr-id', $claimed[0]['context']['correlation_id']);
        self::assertSame('submission-corr-id', $yielded[0]['context']['correlation_id']);
        self::assertSame($jobId, $yielded[0]['context']['job_id']);
    }

    // === Helpers ===

    /**
     * Runs the same 25-row dataset through the processor twice — once
     * straight through with no yield signal, once yielding at every
     * chunk boundary via repeated re-claims — and returns
     * `[$controlArtifactPath, $yieldedArtifactPath]`.
     *
     * @return array{0: string, 1: string}
     */
    private function runControlAndYieldedExports(string $format): array
    {
        $pageSize = 4;
        $rowCount = 25;
        $fieldsBuilder = static fn (int $i): array => ['idx' => $i];

        $controlModelId = $this->createModel(1, "yield_control_{$format}");
        $this->createFieldNamed($controlModelId, 'idx', 'int');
        $this->seedEntryDataBatch(1, $controlModelId, $rowCount, $fieldsBuilder);

        $yieldModelId = $this->createModel(1, "yield_resumed_{$format}");
        $this->createFieldNamed($yieldModelId, 'idx', 'int');
        $this->seedEntryDataBatch(1, $yieldModelId, $rowCount, $fieldsBuilder);

        $controlDir = $this->makeTempArtifactDir();
        $controlJobId = $this->seedExportJob(
            1, $controlModelId, 'processing', $format,
            workerIdentity: 'host:control',
            heartbeatAt: $this->utcNowString(),
            claimedAt: $this->utcNowString(),
        );
        $controlClaim = new ClaimedJob(
            id: $controlJobId,
            tenantId: 1,
            modelId: $controlModelId,
            format: $format,
            filter: ['model_id' => $controlModelId],
            lastCursor: null,
            workerIdentity: 'host:control',
            claimKind: ClaimKind::Pending,
            skipCount: 0,
        );
        $controlOutcome = $this->makeProcessor(artifactDir: $controlDir, pageSize: $pageSize)
            ->process($controlClaim, 'corr-control', null);
        self::assertSame(JobOutcome::Completed, $controlOutcome);
        $controlPath = (string) $this->fetchExportJob($controlJobId)['artifact_path'];

        $yieldDir = $this->makeTempArtifactDir();
        $yieldJobId = $this->seedExportJob(1, $yieldModelId, 'pending', $format);
        $claimer = new ExportJobClaimer($this->pdo, new SystemClock(), leaseTimeoutSeconds: 30);

        $finalOutcome = null;
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $claim = $claimer->claimPendingOrAbandoned();
            self::assertNotNull($claim, 'The job must remain claimable at every step.');
            $outcome = $this->makeProcessor(artifactDir: $yieldDir, pageSize: $pageSize)
                ->process($claim, 'corr-yielded', new ScriptedYieldSignal(afterCalls: 1));
            if ($outcome === JobOutcome::Completed) {
                $finalOutcome = $outcome;
                break;
            }
            self::assertSame(JobOutcome::Yielded, $outcome, "Unexpected outcome on attempt {$attempt}.");
        }
        self::assertSame(JobOutcome::Completed, $finalOutcome, 'The job must converge within a bounded number of yields.');
        $yieldedPath = (string) $this->fetchExportJob($yieldJobId)['artifact_path'];

        return [$controlPath, $yieldedPath];
    }
}

/**
 * PDO decorator that simulates a genuine concurrent re-claim landing
 * exactly between this worker's `appendRow()`s and its Nth non-final
 * chunk-commit UPDATE — the same trick as `ChroniclerResumeTest`'s
 * `ReclaimSimulatingPdo`, duplicated here rather than shared because
 * this file's own test classes are only autoloadable once PHPUnit's
 * suite discovery has already required this file (the existing
 * `*InjectingPdo` classes follow the same one-copy-per-file
 * precedent, e.g. `ChroniclerDeadlockRetryTest` vs
 * `LiberatorDeadlockRetryTest`).
 */
final class YieldRaceReclaimingPdo extends PDO
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
        // Matches both the ordinary non-final chunk commit AND the
        // yield-commit UPDATE — both set `artifact_bytes = ?` and
        // neither sets `status = 'completed'`.
        if (str_contains($query, 'stardust_export_jobs')
            && str_contains($query, 'artifact_bytes = ?')
            && !str_contains($query, "status = 'completed'")
        ) {
            return YieldRaceReclaimingStatement::wrap(
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

final class YieldRaceReclaimingStatement extends PDOStatement
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
            // Same connection, so the side-channel write is
            // immediately visible to this statement's own execute()
            // below — exactly as a genuinely separate connection's
            // already-committed write would be.
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
