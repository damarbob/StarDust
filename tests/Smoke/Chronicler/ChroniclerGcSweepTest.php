<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Chronicler;

use StarDust\Tests\Smoke\Phase7TestCase;

/**
 * Artifact GC sweep:
 *   - TTL'd completed jobs lose their artifact file + artifact_path
 *     column (job row retained for audit).
 *   - Orphaned partials from failed jobs older than 1 h are also
 *     swept (best-effort cleanup of crash-time leftovers).
 *   - `gc_swept` is emitted ONLY when artifacts_deleted > 0; idle
 *     cycles produce no event spam.
 */
final class ChroniclerGcSweepTest extends Phase7TestCase
{
    public function testTtlPastCompletedJobArtifactReclaimed(): void
    {
        $artifactDir = $this->makeTempArtifactDir();
        $oldArtifact = $artifactDir . DIRECTORY_SEPARATOR . 'export_old.csv';
        file_put_contents($oldArtifact, str_repeat('a', 1024));

        $modelId = $this->createModel(1, 'gc_completed');
        $jobId = $this->seedExportJob(
            1, $modelId,
            status: 'completed',
            artifactPath: $oldArtifact,
            heartbeatAt: $this->utcNowString(),
            claimedAt: '2026-05-26 00:00:00',
            completedAt: '2026-05-26 00:00:00', // > 24 h ago
        );

        $logger = $this->makeRecordingLogger();
        $this->makeChronicler(
            $logger,
            artifactDir: $artifactDir,
            artifactTtlSeconds: 60, // anything > 60s old is TTL'd
        )->tick();

        // File gone, column cleared, row retained.
        self::assertFalse(is_file($oldArtifact));
        $row = $this->fetchExportJob($jobId);
        self::assertSame('completed', $row['status']);
        self::assertNull($row['artifact_path']);

        $events = $this->recordsWithEvent($logger->records(), 'gc_swept');
        self::assertCount(1, $events);
        self::assertGreaterThanOrEqual(1, $events[0]['context']['artifacts_deleted']);
        self::assertGreaterThan(0, $events[0]['context']['bytes_reclaimed']);
    }

    public function testFailedJobOrphanedPartialReclaimed(): void
    {
        $artifactDir = $this->makeTempArtifactDir();
        $orphan = $artifactDir . DIRECTORY_SEPARATOR . 'export_orphan.csv';
        file_put_contents($orphan, str_repeat('x', 512));

        $modelId = $this->createModel(1, 'gc_failed');
        $jobId = $this->seedExportJob(
            1, $modelId,
            status: 'failed',
            artifactPath: $orphan,
            failedReason: 'other',
            heartbeatAt: $this->utcNowString(),
            claimedAt: '2026-05-26 00:00:00',
            completedAt: '2026-05-26 00:00:00', // > 1 h ago
        );

        $this->makeChronicler(
            artifactDir: $artifactDir,
            orphanedPartialTtlSeconds: 60,
        )->tick();

        self::assertFalse(is_file($orphan));
        $row = $this->fetchExportJob($jobId);
        self::assertSame('failed', $row['status']);
        self::assertNull($row['artifact_path']);
    }

    public function testNothingToSweepEmitsNoGcEvent(): void
    {
        // Fresh-completed job (not past TTL) → nothing to do.
        $artifactDir = $this->makeTempArtifactDir();
        $freshArtifact = $artifactDir . DIRECTORY_SEPARATOR . 'export_fresh.csv';
        file_put_contents($freshArtifact, 'recent');

        $modelId = $this->createModel(1, 'gc_quiet');
        $this->seedExportJob(
            1, $modelId,
            status: 'completed',
            artifactPath: $freshArtifact,
            heartbeatAt: $this->utcNowString(),
            claimedAt: $this->utcNowString(),
            completedAt: $this->utcNowString(),
        );

        $logger = $this->makeRecordingLogger();
        $this->makeChronicler(
            $logger,
            artifactDir: $artifactDir,
            artifactTtlSeconds: 86_400, // 24 h
        )->tick();

        $events = $this->recordsWithEvent($logger->records(), 'gc_swept');
        self::assertCount(0, $events);
        self::assertTrue(is_file($freshArtifact));
    }
}
