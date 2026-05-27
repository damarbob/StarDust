<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Chronicler;

use StarDust\Exception\InvalidTenantIdException;
use StarDust\Tests\Smoke\Phase7TestCase;

/**
 * `getExportJob()` tenant-isolation + hydration contract.
 */
final class GetExportJobTest extends Phase7TestCase
{
    public function testGetReturnsHydratedJobForOwnTenant(): void
    {
        $modelId = $this->createModel(7, 'gettable');
        $jobId = $this->seedExportJob(7, $modelId, 'pending', 'json');

        $job = $this->makeExportSubmitter()->getJob(7, $jobId);

        self::assertNotNull($job);
        self::assertSame($jobId, $job->id);
        self::assertSame(7, $job->tenantId);
        self::assertSame($modelId, $job->modelId);
        self::assertSame('pending', $job->status);
        self::assertSame('json', $job->format);
        self::assertSame([], $job->filter,
            'Consumer QueryFilter is preserved unmodified — model_id is exposed as a typed field.');
        self::assertNull($job->lastCursor);
        self::assertNull($job->artifactPath);
        self::assertNull($job->failedReason);
        self::assertSame(0, $job->skipCount);
        self::assertNotNull($job->createdAt);
    }

    public function testGetReturnsNullForOtherTenant(): void
    {
        $modelId = $this->createModel(5, 'private_to_5');
        $jobId = $this->seedExportJob(5, $modelId, 'completed', 'csv',
            artifactPath: '/tmp/secret.csv',
            heartbeatAt: $this->utcNowString(),
            claimedAt: $this->utcNowString(),
            completedAt: $this->utcNowString());

        // Tenant 6 cannot read tenant 5's job.
        self::assertNull($this->makeExportSubmitter()->getJob(6, $jobId));
    }

    public function testGetReturnsNullForMissingJob(): void
    {
        self::assertNull($this->makeExportSubmitter()->getJob(1, 9_999_999));
    }

    public function testGetRejectsInvalidTenantId(): void
    {
        $this->expectException(InvalidTenantIdException::class);
        $this->makeExportSubmitter()->getJob(0, 1);
    }

    public function testGetHydratesCompletedJobTimestamps(): void
    {
        $modelId = $this->createModel(1, 'timestamps');
        $jobId = $this->seedExportJob(
            1, $modelId,
            status: 'completed',
            format: 'csv',
            lastCursor: 42,
            artifactPath: '/tmp/x.csv',
            workerIdentity: 'host:1:abc',
            heartbeatAt: '2026-05-27 12:00:00',
            claimedAt: '2026-05-27 11:00:00',
            completedAt: '2026-05-27 12:30:00',
        );

        $job = $this->makeExportSubmitter()->getJob(1, $jobId);
        self::assertNotNull($job);
        self::assertSame(42, $job->lastCursor);
        self::assertSame('/tmp/x.csv', $job->artifactPath);
        self::assertSame('host:1:abc', $job->workerIdentity);
        self::assertNotNull($job->claimedAt);
        self::assertSame('2026-05-27 11:00:00', $job->claimedAt->format('Y-m-d H:i:s'));
        self::assertNotNull($job->completedAt);
        self::assertSame('2026-05-27 12:30:00', $job->completedAt->format('Y-m-d H:i:s'));
    }
}
