<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Reconciler;

use DateTimeImmutable;
use PDO;
use Psr\Clock\ClockInterface;
use StarDust\Clock\SystemClock;
use StarDust\Reconciler\TickOutcome;
use StarDust\Tests\Smoke\Phase6bTestCase;

/**
 * Gap 5 — import-job abandoned-claim / lease recovery.
 *
 * The Reconciler's import path mirrors the Chronicler's
 * {@see \StarDust\Chronicler\ExportJobClaimer}:
 *   - a `processing` job whose `heartbeat_at` lapsed past the lease
 *     timeout is re-claimed and RESUMED from the `manifest` checkpoint,
 *     never re-processing a committed chunk (no duplicate `entry_data`);
 *   - a fresh `processing` job (heartbeat within the lease) is left alone;
 *   - the prior worker self-aborts on a `worker_identity` mismatch and
 *     does NOT mark the row failed — the re-claimer owns terminal state.
 *
 * Extends Phase6bTestCase for the recording-logger helpers; the import
 * fixtures themselves live on Phase5TestCase.
 */
final class ImportJobAbandonedClaimTest extends Phase6bTestCase
{
    public function testStaleProcessingJobReclaimedAndCompleted(): void
    {
        [$modelId, , , $fieldName] = $this->setupModelWithReservedField(1, 'string');

        $dir = $this->makeDir();
        try {
            $entries = [];
            for ($i = 0; $i < 5; $i++) {
                $entries[] = ['tenant_id' => 1, 'model_id' => $modelId, 'fields' => [$fieldName => 'v' . $i]];
            }

            // heartbeat 60 s ago > 30 s lease ⇒ abandoned.
            [$jobId] = $this->writeProcessingImportJob(
                tenantId: 1,
                entries: $entries,
                heartbeatAgoSeconds: 60,
                manifest: null,
                workerIdentity: 'origin-host:1:dead-uuid',
                artifactDir: $dir,
            );

            $before = $this->fetchJob($jobId);

            $source = $this->makeImportJobWorkSource(artifactDir: $dir, leaseTimeoutSeconds: 30);
            $outcome = $source->tickOne('corr-stale');

            self::assertSame(TickOutcome::WORK_DONE, $outcome);

            $after = $this->fetchJob($jobId);
            self::assertSame('completed', $after['status']);
            $manifest = json_decode((string) $after['manifest'], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(5, $manifest['entries_written']);

            // worker_identity overwritten; claimed_at PRESERVED.
            self::assertNotSame('origin-host:1:dead-uuid', $after['worker_identity']);
            self::assertSame($before['claimed_at'], $after['claimed_at']);

            self::assertSame(5, $this->countEntries(1, $modelId));
        } finally {
            $this->cleanupDir($dir);
        }
    }

    public function testResumeFromManifestOffsetWritesNoDuplicates(): void
    {
        [$modelId, , , $fieldName] = $this->setupModelWithReservedField(1, 'string');

        $dir = $this->makeDir();
        try {
            $entries = [];
            for ($i = 0; $i < 5; $i++) {
                $entries[] = ['tenant_id' => 1, 'model_id' => $modelId, 'fields' => [$fieldName => 'v' . $i]];
            }

            // The prior worker committed the first 2 entries before
            // crashing — replay them through the real EntryWriter so the
            // DB state matches a genuine partial import.
            $this->seedEntry(1, $modelId, $entries[0]['fields']);
            $this->seedEntry(1, $modelId, $entries[1]['fields']);
            self::assertSame(2, $this->countEntries(1, $modelId));

            [$jobId] = $this->writeProcessingImportJob(
                tenantId: 1,
                entries: $entries,
                heartbeatAgoSeconds: 60,
                manifest: ['chunks' => 1, 'entries_written' => 2],
                artifactDir: $dir,
            );

            $source = $this->makeImportJobWorkSource(artifactDir: $dir, leaseTimeoutSeconds: 30);
            $source->tickOne('corr-resume');

            $job = $this->fetchJob($jobId);
            self::assertSame('completed', $job['status']);
            $manifest = json_decode((string) $job['manifest'], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(5, $manifest['entries_written']);

            // Critical: total is 5, NOT 7 — the resumed run skipped the
            // 2 already-committed entries instead of re-writing them.
            self::assertSame(5, $this->countEntries(1, $modelId));
        } finally {
            $this->cleanupDir($dir);
        }
    }

    public function testFreshProcessingJobNotReclaimed(): void
    {
        [$modelId, , , $fieldName] = $this->setupModelWithReservedField(1, 'string');

        $dir = $this->makeDir();
        try {
            $entries = [['tenant_id' => 1, 'model_id' => $modelId, 'fields' => [$fieldName => 'a']]];

            // heartbeat 5 s ago < 30 s lease ⇒ still healthy.
            [$jobId] = $this->writeProcessingImportJob(
                tenantId: 1,
                entries: $entries,
                heartbeatAgoSeconds: 5,
                manifest: null,
                workerIdentity: 'live-host:1:live-uuid',
                artifactDir: $dir,
            );

            $source = $this->makeImportJobWorkSource(artifactDir: $dir, leaseTimeoutSeconds: 30);
            $outcome = $source->tickOne('corr-fresh');

            self::assertSame(TickOutcome::IDLE, $outcome);

            $job = $this->fetchJob($jobId);
            self::assertSame('processing', $job['status']);
            self::assertSame('live-host:1:live-uuid', $job['worker_identity']);
            self::assertSame(0, $this->countEntries(1, $modelId));
        } finally {
            $this->cleanupDir($dir);
        }
    }

    public function testLeaseLostWorkerSelfAbortsWithoutFailing(): void
    {
        [$modelId, , , $fieldName] = $this->setupModelWithReservedField(1, 'string');

        $sibling = $this->makeSiblingPdo();
        $dir = $this->makeDir();
        try {
            $entries = [];
            for ($i = 0; $i < 4; $i++) {
                $entries[] = ['tenant_id' => 1, 'model_id' => $modelId, 'fields' => [$fieldName => 'v' . $i]];
            }
            [$jobId] = $this->writePendingImportJob(1, $entries, $dir);

            // A clock that, once the first chunk's checkpoint has
            // committed (manifest.entries_written >= chunkSize), flips
            // the row's worker_identity to a "re-claimer" via the sibling
            // connection — so the worker's next chunk checkpoint
            // (`WHERE worker_identity = self`) matches zero rows.
            $flipClock = new class($sibling, $jobId) implements ClockInterface {
                private bool $flipped = false;
                private SystemClock $inner;

                public function __construct(
                    private readonly PDO $sibling,
                    private readonly int $jobId,
                ) {
                    $this->inner = new SystemClock();
                }

                public function now(): DateTimeImmutable
                {
                    if (!$this->flipped) {
                        $stmt = $this->sibling->prepare(
                            'SELECT manifest FROM stardust_import_jobs WHERE id = ?'
                        );
                        $stmt->execute([$this->jobId]);
                        $raw = $stmt->fetchColumn();
                        $written = 0;
                        if (is_string($raw) && $raw !== '') {
                            $decoded = json_decode($raw, true);
                            $written = is_array($decoded) ? (int) ($decoded['entries_written'] ?? 0) : 0;
                        }
                        if ($written >= 2) {
                            $upd = $this->sibling->prepare(
                                'UPDATE stardust_import_jobs SET worker_identity = ? WHERE id = ?'
                            );
                            $upd->execute(['host:OTHER:reclaimer', $this->jobId]);
                            $this->flipped = true;
                        }
                    }
                    return $this->inner->now();
                }
            };

            $logger = $this->makeRecordingLogger();
            $source = $this->makeImportJobWorkSource(
                logger: $logger,
                artifactDir: $dir,
                chunkSize: 2,
                clock: $flipClock,
            );
            $outcome = $source->tickOne('corr-lease');

            self::assertSame(TickOutcome::WORK_DONE, $outcome);

            // Only chunk 1 (2 entries) survives; chunk 2 was rolled back.
            self::assertSame(2, $this->countEntries(1, $modelId));

            $job = $this->fetchJob($jobId);
            // Lease loss does NOT transition the row — the re-claimer owns
            // terminal state.
            self::assertSame('processing', $job['status']);
            self::assertNull($job['failed_reason']);
            self::assertSame('host:OTHER:reclaimer', $job['worker_identity']);

            $leaseLost = $this->recordsWithEvent($logger->records(), 'lease_lost');
            self::assertCount(1, $leaseLost);

            // No DLQ row — lease loss is not a failure.
            $dlq = (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_reconciler_dlq')->fetchColumn();
            self::assertSame(0, $dlq);
        } finally {
            $this->cleanupDir($dir);
        }
    }

    private function fetchJob(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM stardust_import_jobs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }

    private function countEntries(int $tenantId, int $modelId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM entry_data WHERE tenant_id = ? AND model_id = ?'
        );
        $stmt->execute([$tenantId, $modelId]);
        return (int) $stmt->fetchColumn();
    }

    private function makeDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stardust-test-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        return $dir;
    }

    private function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    private function makeSiblingPdo(): PDO
    {
        $dsn  = getenv('STARDUST_TEST_DSN') ?: '';
        $user = getenv('STARDUST_TEST_USER') ?: '';
        $pass = getenv('STARDUST_TEST_PASS') ?: '';

        if ($dsn === '' || $user === '') {
            self::markTestSkipped('STARDUST_TEST_DSN/STARDUST_TEST_USER must be set.');
        }

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
