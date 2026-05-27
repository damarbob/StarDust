<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Chronicler;

use StarDust\Tests\Smoke\Phase7TestCase;

/**
 * Heartbeat refresh contract (ADR 0025 commitment 1):
 *
 *   Every chunk-commit transaction also writes `heartbeat_at = NOW()`.
 *   There is NO separate heartbeat timer. A worker that cannot commit
 *   a chunk has lost its lease by construction.
 */
final class ChroniclerHeartbeatTest extends Phase7TestCase
{
    public function testChunkCommitRefreshesHeartbeat(): void
    {
        $modelId = $this->createModel(1, 'heartbeat');
        $this->createFieldNamed($modelId, 'idx', 'int');
        $this->seedEntryDataBatch(1, $modelId, 6);

        $jobId = $this->seedExportJob(1, $modelId, 'pending', 'csv');

        // Submission row has no heartbeat yet.
        $beforeRow = $this->fetchExportJob($jobId);
        self::assertNull($beforeRow['heartbeat_at']);

        // pageSize=2 → 3 chunks; each commits an updated heartbeat_at.
        $this->makeChronicler(pageSize: 2)->tick();

        $afterRow = $this->fetchExportJob($jobId);
        self::assertNotNull($afterRow['heartbeat_at']);
        self::assertSame('completed', $afterRow['status']);
        self::assertNotNull($afterRow['claimed_at']);
    }

    public function testHeartbeatAdvancesAcrossChunks(): void
    {
        $modelId = $this->createModel(1, 'heartbeat_advance');
        $this->createFieldNamed($modelId, 'idx', 'int');
        $this->seedEntryDataBatch(1, $modelId, 4);

        $jobId = $this->seedExportJob(1, $modelId, 'pending', 'csv');
        $this->makeChronicler(pageSize: 2)->tick();

        $row = $this->fetchExportJob($jobId);
        // heartbeat_at should not be earlier than claimed_at — both
        // are written in the same chunk transaction sequence.
        self::assertGreaterThanOrEqual(
            (string) $row['claimed_at'],
            (string) $row['heartbeat_at'],
        );
    }
}
