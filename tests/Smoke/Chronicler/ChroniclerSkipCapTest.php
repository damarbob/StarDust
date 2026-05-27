<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Chronicler;

use StarDust\Chronicler\ClaimKind;
use StarDust\Chronicler\ClaimedJob;
use StarDust\Chronicler\JobOutcome;
use StarDust\Tests\Smoke\Phase7TestCase;

/**
 * Skip-cap abort path (ADR 0025 commitment 4 / 5):
 *
 *   - Per-row encoding failure → `row_skipped` + `skip_count++`.
 *   - When `skip_count > skip_count_cap` the job is marked
 *     `failed:excessive_skips`, the partial artifact is deleted, and
 *     a `job_failed{reason:excessive_skips}` event fires.
 */
final class ChroniclerSkipCapTest extends Phase7TestCase
{
    public function testRowSkipChargesSkipCount(): void
    {
        $modelId = $this->createModel(1, 'row_skip');
        $this->createFieldNamed($modelId, 'val');

        // Two rows: one valid, one with embedded NUL.
        $this->seedEntryDataBatch(1, $modelId, 2, static fn (int $i) => [
            'val' => $i === 0 ? 'good' : "bad\0value",
        ]);

        $logger = $this->makeRecordingLogger();
        $jobId = $this->seedExportJob(1, $modelId, 'pending', 'csv');
        $this->makeChronicler($logger)->tick();

        $row = $this->fetchExportJob($jobId);
        self::assertSame('completed', $row['status']);
        self::assertSame(1, (int) $row['skip_count']);

        $skipped = $this->recordsWithEvent($logger->records(), 'row_skipped');
        self::assertCount(1, $skipped);
        self::assertSame('format_invalid', $skipped[0]['context']['reason']);
    }

    public function testSkipCapExceededFailsJob(): void
    {
        $modelId = $this->createModel(1, 'skip_cap');
        $this->createFieldNamed($modelId, 'val');

        // Three bad rows; cap = 2. Third row trips the cap.
        $this->seedEntryDataBatch(1, $modelId, 3, static fn () => [
            'val' => "bad\0value",
        ]);

        $logger = $this->makeRecordingLogger();
        $jobId = $this->seedExportJob(1, $modelId, 'pending', 'csv');

        $this->makeChronicler($logger, skipCountCap: 2)->tick();

        $row = $this->fetchExportJob($jobId);
        self::assertSame('failed', $row['status']);
        self::assertSame('excessive_skips', $row['failed_reason']);
        // Partial artifact deleted on terminal failure.
        self::assertNull($row['artifact_path']);

        $failed = $this->recordsWithEvent($logger->records(), 'job_failed');
        self::assertCount(1, $failed);
        self::assertSame('excessive_skips', $failed[0]['context']['reason']);
    }

    public function testReclaimContinuesChargingFromPriorSkipCount(): void
    {
        $modelId = $this->createModel(1, 'resume_skip');
        $this->createFieldNamed($modelId, 'val');

        // 5 bad rows. Prior worker charged 4 skips; cap = 4 ⇒ the
        // very first bad row in the reclaim trips the cap.
        $this->seedEntryDataBatch(1, $modelId, 5, static fn () => [
            'val' => "bad\0value",
        ]);

        $jobId = $this->seedExportJob(
            1, $modelId,
            status: 'pending',
            format: 'csv',
            skipCount: 4, // inherited
        );

        $this->makeChronicler(skipCountCap: 4)->tick();
        $row = $this->fetchExportJob($jobId);
        self::assertSame('failed', $row['status']);
        self::assertSame('excessive_skips', $row['failed_reason']);
        // 4 inherited + 1 charged this tick = 5; cap is 4, so the
        // very first row trips. Confirms charges persist across claims.
        self::assertSame(5, (int) $row['skip_count']);
    }

    public function testProcessorReturnsFailedExcessiveSkipsOutcome(): void
    {
        $modelId = $this->createModel(1, 'outcome_check');
        $this->createFieldNamed($modelId, 'val');
        $this->seedEntryDataBatch(1, $modelId, 1, static fn () => ['val' => "bad\0"]);

        $jobId = $this->seedExportJob(
            1, $modelId, 'processing', 'csv',
            workerIdentity: 'host:test:uuid',
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
            workerIdentity: 'host:test:uuid',
            claimKind: ClaimKind::Pending,
            skipCount: 0,
        );

        $outcome = $this->makeProcessor(skipCountCap: 0)->process($claim, 'corr');
        self::assertSame(JobOutcome::FailedExcessiveSkips, $outcome);
    }
}
