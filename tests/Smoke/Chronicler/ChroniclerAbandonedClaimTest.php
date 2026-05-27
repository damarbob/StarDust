<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Chronicler;

use StarDust\Tests\Smoke\Phase7TestCase;

/**
 * Abandoned-claim sweep (chronicler_daemon.md §4 AC#7):
 *   - Detects `status='processing'` rows whose `heartbeat_at` is older
 *     than `leaseTimeoutSeconds`.
 *   - Best-effort deletes the prior partial artifact.
 *   - Overwrites `worker_identity` + `heartbeat_at`; `claimed_at` is
 *     intentionally PRESERVED so operators see the original claim time.
 *   - Resumes processing from `last_cursor` and produces a complete
 *     final artifact.
 */
final class ChroniclerAbandonedClaimTest extends Phase7TestCase
{
    public function testStaleHeartbeatReclaimed(): void
    {
        $modelId = $this->createModel(1, 'abandoned');
        $this->createFieldNamed($modelId, 'idx', 'int');
        $this->seedEntryDataBatch(1, $modelId, 5);

        // Stranded prior worker: status='processing', heartbeat 60s
        // ago. Lease timeout 30s (default) → abandoned.
        $artifactDir = $this->makeTempArtifactDir();
        $partialPath = $artifactDir . DIRECTORY_SEPARATOR . 'export_stranded_partial.csv';
        file_put_contents($partialPath, "stranded\r\n");

        $staleHeartbeat = (new \DateTimeImmutable('-60 seconds'))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
        $originalClaim = (new \DateTimeImmutable('-65 seconds'))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $jobId = $this->seedExportJob(
            1, $modelId,
            status: 'processing',
            format: 'csv',
            lastCursor: null,
            artifactPath: $partialPath,
            workerIdentity: 'host:9999:stale-uuid',
            heartbeatAt: $staleHeartbeat,
            claimedAt: $originalClaim,
        );

        $this->makeChronicler(artifactDir: $artifactDir, leaseTimeoutSeconds: 30)->tick();

        $row = $this->fetchExportJob($jobId);
        self::assertSame('completed', $row['status']);
        self::assertNotSame('host:9999:stale-uuid', $row['worker_identity']);
        // Original claimed_at PRESERVED.
        self::assertSame($originalClaim, $row['claimed_at']);
        // Old partial deleted.
        self::assertFalse(is_file($partialPath));
        // New artifact present with the seeded rows.
        self::assertNotNull($row['artifact_path']);
        self::assertNotSame($partialPath, $row['artifact_path']);
        $rows = $this->readArtifactCsv((string) $row['artifact_path']);
        self::assertCount(5, $rows);
    }

    public function testFreshProcessingNotReclaimed(): void
    {
        $modelId = $this->createModel(1, 'fresh');
        $this->createFieldNamed($modelId, 'k');

        $freshHeartbeat = (new \DateTimeImmutable('-5 seconds'))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $jobId = $this->seedExportJob(
            1, $modelId,
            status: 'processing',
            workerIdentity: 'host:1234:alive',
            heartbeatAt: $freshHeartbeat,
            claimedAt: $freshHeartbeat,
        );

        // No pending jobs and the only processing job is healthy →
        // idle tick (GC sweep runs, but nothing to do).
        $this->makeChronicler(leaseTimeoutSeconds: 30)->tick();

        $row = $this->fetchExportJob($jobId);
        self::assertSame('processing', $row['status']);
        self::assertSame('host:1234:alive', $row['worker_identity']);
    }

    public function testResumesFromLastCursor(): void
    {
        $modelId = $this->createModel(1, 'resume');
        $this->createFieldNamed($modelId, 'idx', 'int');
        $entryIds = $this->seedEntryDataBatch(1, $modelId, 10);

        $staleHeartbeat = (new \DateTimeImmutable('-60 seconds'))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        // Prior worker committed through entry 5 before its lease expired.
        $jobId = $this->seedExportJob(
            1, $modelId,
            status: 'processing',
            format: 'csv',
            lastCursor: $entryIds[4], // already processed first 5
            workerIdentity: 'host:dead:1',
            heartbeatAt: $staleHeartbeat,
            claimedAt: $staleHeartbeat,
        );

        $this->makeChronicler(leaseTimeoutSeconds: 30)->tick();

        $row = $this->fetchExportJob($jobId);
        self::assertSame('completed', $row['status']);
        // Final artifact has only the remaining 5 rows (resume from cursor).
        $rows = $this->readArtifactCsv((string) $row['artifact_path']);
        self::assertCount(5, $rows);
    }
}
