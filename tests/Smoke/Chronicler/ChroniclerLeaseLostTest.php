<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Chronicler;

use StarDust\Chronicler\ClaimKind;
use StarDust\Chronicler\ClaimedJob;
use StarDust\Chronicler\JobOutcome;
use StarDust\Tests\Smoke\Phase7TestCase;

/**
 * Lease-loss self-detection (chronicler_daemon.md §4 AC#8):
 *
 *   When `UPDATE … WHERE worker_identity = self_identity` affects zero
 *   rows, the processor emits `lease_lost`, deletes its partial
 *   artifact, and exits the job loop WITHOUT marking the row failed.
 *   The re-claimer (whoever overwrote the row) owns the terminal state
 *   from that point on.
 *
 * This test simulates the race by hand-crafting a ClaimedJob that
 * claims to be one worker but submitting it to a processor while the
 * row carries a different worker_identity in the database.
 */
final class ChroniclerLeaseLostTest extends Phase7TestCase
{
    public function testWorkerIdentityMismatchProducesLeaseLost(): void
    {
        $modelId = $this->createModel(1, 'lease_lost');
        $this->createFieldNamed($modelId, 'idx', 'int');
        $this->seedEntryDataBatch(1, $modelId, 3);

        // Insert a row whose actual worker_identity belongs to the
        // "winning" re-claimer, NOT the worker that's about to attempt
        // the chunk commit.
        $jobId = $this->seedExportJob(
            1, $modelId,
            status: 'processing',
            format: 'csv',
            workerIdentity: 'host:WIN:re-claimer-uuid',
            heartbeatAt: $this->utcNowString(),
            claimedAt: $this->utcNowString(),
        );

        $loserClaim = new ClaimedJob(
            id: $jobId,
            tenantId: 1,
            modelId: $modelId,
            format: 'csv',
            filter: ['model_id' => $modelId],
            lastCursor: null,
            workerIdentity: 'host:LOSE:displaced-uuid',
            claimKind: ClaimKind::Pending,
            skipCount: 0,
        );

        $logger = $this->makeRecordingLogger();
        $outcome = $this->makeProcessor($logger)->process($loserClaim, 'test-correlation');

        self::assertSame(JobOutcome::LeaseLost, $outcome);
        $events = $this->recordsWithEvent($logger->records(), 'lease_lost');
        self::assertCount(1, $events);
        self::assertSame('host:LOSE:displaced-uuid', $events[0]['context']['worker_identity']);

        // Row state untouched by the loser (still 'processing' under
        // the winner's identity).
        $row = $this->fetchExportJob($jobId);
        self::assertSame('processing', $row['status']);
        self::assertSame('host:WIN:re-claimer-uuid', $row['worker_identity']);
    }

    public function testLeaseLostDoesNotMarkRowFailed(): void
    {
        $modelId = $this->createModel(1, 'lease_no_fail');
        $this->createFieldNamed($modelId, 'idx', 'int');
        $this->seedEntryDataBatch(1, $modelId, 2);

        $jobId = $this->seedExportJob(
            1, $modelId,
            status: 'processing',
            workerIdentity: 'host:winning:uuid',
            heartbeatAt: $this->utcNowString(),
            claimedAt: $this->utcNowString(),
        );

        $loser = new ClaimedJob(
            id: $jobId,
            tenantId: 1,
            modelId: $modelId,
            format: 'csv',
            filter: ['model_id' => $modelId],
            lastCursor: null,
            workerIdentity: 'host:loser:uuid',
            claimKind: ClaimKind::Pending,
            skipCount: 0,
        );

        $this->makeProcessor()->process($loser, 'corr');

        $row = $this->fetchExportJob($jobId);
        // Critical: lease loss does NOT transition status; the winner
        // is presumed to be processing and will produce the terminal
        // state on its own.
        self::assertSame('processing', $row['status']);
        self::assertNull($row['failed_reason']);
    }
}
