<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Chronicler;

use StarDust\Tests\Smoke\Phase7TestCase;

/**
 * Abandoned-claim sweep (chronicler_daemon.md §4 AC#7):
 *   - Detects `status='processing'` rows whose `heartbeat_at` is older
 *     than `leaseTimeoutSeconds`.
 *   - Overwrites `worker_identity` + `heartbeat_at`; `claimed_at` is
 *     intentionally PRESERVED so operators see the original claim time.
 *   - Per ADR 0047, the claimer no longer deletes the prior partial
 *     artifact — it hands `artifact_path` / `artifact_bytes` forward so
 *     the new worker's stream can attempt a verified re-open. These
 *     tests cover the CLAIM mechanics using the no-anchor case (no
 *     `artifact_bytes` seeded, matching a row claimed before that
 *     column existed) — the simplest path, where the new worker always
 *     rebuilds a fresh artifact. Genuine byte-level resume, and every
 *     anchor-rejection cause, is covered by `ChroniclerResumeTest`.
 */
final class ChroniclerAbandonedClaimTest extends Phase7TestCase
{
    public function testStaleHeartbeatReclaimedWithNoAnchor(): void
    {
        $modelId = $this->createModel(1, 'abandoned');
        $this->createFieldNamed($modelId, 'idx', 'int');
        $this->seedEntryDataBatch(1, $modelId, 5);

        // Stranded prior worker: status='processing', heartbeat 60s
        // ago. Lease timeout 30s (default) → abandoned. No
        // artifact_bytes is seeded, so the claim carries no adoptable
        // anchor — the new worker always rebuilds fresh.
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
        // Old partial NOT deleted by the claimer (ADR 0047) — it simply
        // has no artifact_bytes anchor to adopt, so it is orphaned
        // rather than destroyed. This is the accepted degradation for a
        // pre-0047 row; a genuinely anchored row is adopted instead
        // (ChroniclerResumeTest).
        self::assertTrue(is_file($partialPath));
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

    /**
     * The regression this whole item exists for. ADR 0025 Commitment 1,
     * taken literally ("resume from last_cursor with the partial
     * deleted"), is self-contradictory: without a real byte anchor
     * there is nothing to resume INTO, so trusting `last_cursor` would
     * silently skip every row the (now-orphaned, or historically
     * deleted) file held. With no `artifact_bytes` seeded, the claim
     * carries no anchor and the processor MUST rebuild the whole
     * artifact from zero.
     */
    public function testReclaimWithNoAnchorRebuildsTheWholeArtifact(): void
    {
        $modelId = $this->createModel(1, 'resume');
        $this->createFieldNamed($modelId, 'idx', 'int');
        $entryIds = $this->seedEntryDataBatch(1, $modelId, 10);

        $staleHeartbeat = (new \DateTimeImmutable('-60 seconds'))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        // Prior worker's last_cursor claims 5 rows already processed,
        // but with no artifact_bytes anchor there is nothing to verify
        // that claim against — the new worker cannot trust it.
        $jobId = $this->seedExportJob(
            1, $modelId,
            status: 'processing',
            format: 'csv',
            lastCursor: $entryIds[4], // already processed first 5 (unverifiable)
            workerIdentity: 'host:dead:1',
            heartbeatAt: $staleHeartbeat,
            claimedAt: $staleHeartbeat,
        );

        $this->makeChronicler(leaseTimeoutSeconds: 30)->tick();

        $row = $this->fetchExportJob($jobId);
        self::assertSame('completed', $row['status']);
        // Final artifact has every row — not merely the ones after the
        // dead worker's cursor, which no anchor exists to verify.
        $rows = $this->readArtifactCsv((string) $row['artifact_path']);
        self::assertCount(10, $rows);
    }
}
