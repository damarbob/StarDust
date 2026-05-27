<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Chronicler;

use StarDust\Tests\Smoke\Phase7TestCase;

/**
 * Artifact size cap (ADR 0025 commitment 4):
 *
 *   Once cumulative bytes written exceed the configured cap, the job
 *   is marked `failed:artifact_size_exceeded`, the partial artifact is
 *   deleted, and `artifact_oversized` is emitted (NOT `job_failed`).
 *   The distinct event is important for dashboard routing — operators
 *   triaging "consumer asked for too much" don't want it mixed with
 *   generic infrastructure failures.
 */
final class ChroniclerArtifactCapTest extends Phase7TestCase
{
    public function testArtifactBytesExceedingCapEmitsArtifactOversized(): void
    {
        $modelId = $this->createModel(1, 'oversize');
        $this->createFieldNamed($modelId, 'payload');

        // Each row carries ~100 bytes of payload; with cap=200 the
        // second row trips the limit.
        $this->seedEntryDataBatch(1, $modelId, 5, static fn (int $i) => [
            'payload' => str_repeat('a', 100) . $i,
        ]);

        $logger = $this->makeRecordingLogger();
        $jobId = $this->seedExportJob(1, $modelId, 'pending', 'csv');

        $this->makeChronicler($logger, artifactSizeCapBytes: 200)->tick();

        $row = $this->fetchExportJob($jobId);
        self::assertSame('failed', $row['status']);
        self::assertSame('artifact_size_exceeded', $row['failed_reason']);
        self::assertNull($row['artifact_path']);

        // artifact_oversized MUST be emitted; job_failed MUST NOT.
        $oversized = $this->recordsWithEvent($logger->records(), 'artifact_oversized');
        self::assertCount(1, $oversized);
        $jobFailed = $this->recordsWithEvent($logger->records(), 'job_failed');
        self::assertCount(0, $jobFailed,
            'artifact_oversized is its own terminal event; job_failed must not also fire.');
    }

    public function testArtifactOversizedDeletesPartialFile(): void
    {
        $modelId = $this->createModel(1, 'oversize_delete');
        $this->createFieldNamed($modelId, 'payload');
        $this->seedEntryDataBatch(1, $modelId, 10, static fn () => [
            'payload' => str_repeat('x', 200),
        ]);

        $artifactDir = $this->makeTempArtifactDir();
        $jobId = $this->seedExportJob(1, $modelId, 'pending', 'csv');
        $this->makeChronicler(artifactDir: $artifactDir, artifactSizeCapBytes: 100)->tick();

        $row = $this->fetchExportJob($jobId);
        self::assertSame('failed', $row['status']);
        self::assertNull($row['artifact_path']);

        // No leftover export_* file in the directory.
        $files = glob($artifactDir . DIRECTORY_SEPARATOR . 'export_*');
        self::assertSame([], $files);
    }
}
