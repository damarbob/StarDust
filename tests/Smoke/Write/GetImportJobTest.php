<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Write;

use Psr\Log\NullLogger;
use StarDust\Clock\SystemClock;
use StarDust\Exception\InvalidTenantIdException;
use StarDust\Reconciler\TickOutcome;
use StarDust\Tests\Smoke\Phase5TestCase;
use StarDust\Write\BulkIngestSubmitter;
use StarDust\Write\ImportChunkRecord;

/**
 * `getImportJob()` tenant-isolation + hydration contract — the
 * import-side mirror of `Chronicler/GetExportJobTest`.
 *
 * Extends `Phase5TestCase` rather than `WritePathTestCase` (its
 * ancestor) purely for `makeImportJobWorkSource()`, which lets one
 * test assert the manifest hoisting against a REAL drain rather than
 * against a hand-written row.
 */
final class GetImportJobTest extends Phase5TestCase
{
    private function newSubmitter(): BulkIngestSubmitter
    {
        return new BulkIngestSubmitter(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: new NullLogger(),
            // No submission happens through this instance, and the
            // constructor touches no filesystem — mkdir lives in
            // submit(). The dir is named only to satisfy the signature.
            artifactDir: sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stardust-unused',
        );
    }

    public function testGetReturnsHydratedJobForOwnTenant(): void
    {
        $jobId = $this->seedImportJob(
            7,
            entryCount: 250,
            idempotencyKey: 'monthly-2026-08',
        );

        $job = $this->newSubmitter()->getJob(7, $jobId);

        self::assertNotNull($job);
        self::assertSame($jobId, $job->id);
        self::assertSame(7, $job->tenantId);
        self::assertSame('pending', $job->status);
        self::assertSame('monthly-2026-08', $job->idempotencyKey);
        self::assertSame('import_seeded.json', $job->artifactPath);
        self::assertSame(250, $job->entryCount);
        self::assertNull($job->chunks, 'No chunk has committed, so the manifest is absent.');
        self::assertNull($job->entriesWritten);
        self::assertNull($job->failedReason);
        self::assertNull($job->workerIdentity);
        self::assertNull($job->claimedAt);
        self::assertNull($job->completedAt);
        self::assertNotNull($job->createdAt);
    }

    public function testGetReturnsNullForOtherTenant(): void
    {
        $jobId = $this->seedImportJob(
            5,
            status: 'completed',
            artifactPath: '/tmp/private-to-5.json',
            entryCount: 10,
            manifest: ['chunks' => 1, 'entries_written' => 10],
        );

        // Tenant 6 cannot read tenant 5's job.
        self::assertNull($this->newSubmitter()->getJob(6, $jobId));
    }

    public function testGetReturnsNullForMissingJob(): void
    {
        self::assertNull($this->newSubmitter()->getJob(1, 9_999_999));
    }

    public function testGetRejectsInvalidTenantId(): void
    {
        $this->expectException(InvalidTenantIdException::class);
        $this->newSubmitter()->getJob(0, 1);
    }

    /**
     * The load-bearing one: proves the hoisted `chunks` /
     * `entriesWritten` match what `ImportJobWorkSource::completeJob()`
     * actually writes, rather than what a seeded row claims it writes.
     */
    public function testGetHydratesCompletedJobFromRealDrain(): void
    {
        [$modelId, $_fieldId, $_pageId, $fieldName] = $this->setupModelWithReservedField(1, 'string');

        $artifactDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'stardust-test-' . bin2hex(random_bytes(4));
        mkdir($artifactDir, 0777, true);

        try {
            $entries = [
                ['tenant_id' => 1, 'model_id' => $modelId, 'fields' => [$fieldName => 'a']],
                ['tenant_id' => 1, 'model_id' => $modelId, 'fields' => [$fieldName => 'b']],
                ['tenant_id' => 1, 'model_id' => $modelId, 'fields' => [$fieldName => 'c']],
            ];
            [$jobId] = $this->writePendingImportJob(1, $entries, $artifactDir);

            // Before the drain: pending, nothing committed.
            $before = $this->newSubmitter()->getJob(1, $jobId);
            self::assertNotNull($before);
            self::assertSame('pending', $before->status);
            self::assertNull($before->entriesWritten);

            $outcome = $this->makeImportJobWorkSource(artifactDir: $artifactDir)
                ->tickOne('test-corr-get-import');
            self::assertSame(TickOutcome::WORK_DONE, $outcome);

            $after = $this->newSubmitter()->getJob(1, $jobId);
            self::assertNotNull($after);
            self::assertSame('completed', $after->status);
            self::assertSame(1, $after->chunks);
            self::assertSame(3, $after->entriesWritten);
            self::assertSame(3, $after->entryCount);
            self::assertNull($after->failedReason);
            self::assertNotNull($after->completedAt);
            // Stamped on the terminal transition, not only while a
            // worker holds the lease.
            self::assertNotNull($after->heartbeatAt);

            // ADR 0011 §26 / ADR 0040: the per-chunk enumeration, hoisted
            // into typed records. Before the drain there is nothing to
            // report; after it, one record per chunk.
            self::assertSame([], $before->chunkManifest);
            self::assertCount(1, $after->chunkManifest);
            $record = $after->chunkManifest[0];
            self::assertSame(1, $record->index);
            self::assertSame(3, $record->size);
            self::assertSame(ImportChunkRecord::OUTCOME_COMMITTED, $record->outcome);
            self::assertNull($record->failureReason);

            // The range is asserted against the ids entry_data actually
            // assigned, so the record is checked against reality.
            $ids = $this->pdo->query(
                'SELECT id FROM entry_data WHERE model_id = ' . $modelId . ' ORDER BY id'
            )->fetchAll(\PDO::FETCH_COLUMN);
            self::assertSame((int) $ids[0], $record->entryIdFirst);
            self::assertSame((int) $ids[2], $record->entryIdLast);
        } finally {
            foreach (glob($artifactDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($artifactDir);
        }
    }

    /**
     * A failed job keeps the last committed chunk's manifest, because
     * it is checkpointed inside each chunk transaction and `failJob()`
     * never overwrites it. That count is the operator's replay
     * boundary, and it is the reason this DTO exists.
     */
    public function testGetHydratesFailedJobRetainingLastCommittedManifest(): void
    {
        $jobId = $this->seedImportJob(
            1,
            status: 'failed',
            entryCount: 1500,
            manifest: ['chunks' => 2, 'entries_written' => 1000],
            failedReason: 'entry_write_failed',
            completedAt: '2026-05-27 12:30:00',
        );

        $job = $this->newSubmitter()->getJob(1, $jobId);

        self::assertNotNull($job);
        self::assertSame('failed', $job->status);
        self::assertSame('entry_write_failed', $job->failedReason);
        self::assertSame(2, $job->chunks);
        self::assertSame(1000, $job->entriesWritten, 'Replay resumes from entry 1000 of 1500.');
        self::assertSame(1500, $job->entryCount);
    }

    /**
     * A `malformed_json` failure trips before any chunk runs, so the
     * manifest is genuinely absent — null, not zero. The distinction
     * is why the DTO does not reuse the work source's decoder, which
     * collapses a missing manifest to 0.
     */
    public function testFailureBeforeFirstChunkReportsNullNotZero(): void
    {
        $jobId = $this->seedImportJob(
            1,
            status: 'failed',
            entryCount: 40,
            failedReason: 'malformed_json',
        );

        $job = $this->newSubmitter()->getJob(1, $jobId);

        self::assertNotNull($job);
        self::assertNull($job->chunks);
        self::assertNull($job->entriesWritten);
        self::assertNotSame(0, $job->entriesWritten);
    }

    public function testGetHydratesTimestampsAsUtc(): void
    {
        $jobId = $this->seedImportJob(
            1,
            status: 'completed',
            entryCount: 5,
            manifest: ['chunks' => 1, 'entries_written' => 5],
            workerIdentity: 'host:1:abc',
            claimedAt: '2026-05-27 11:00:00',
            heartbeatAt: '2026-05-27 12:00:00',
            createdAt: '2026-05-27 10:00:00',
            completedAt: '2026-05-27 12:30:00',
        );

        $job = $this->newSubmitter()->getJob(1, $jobId);

        self::assertNotNull($job);
        self::assertSame('host:1:abc', $job->workerIdentity);
        self::assertNotNull($job->claimedAt);
        self::assertSame('2026-05-27 11:00:00', $job->claimedAt->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $job->claimedAt->getTimezone()->getName());
        self::assertNotNull($job->heartbeatAt);
        self::assertSame('2026-05-27 12:00:00', $job->heartbeatAt->format('Y-m-d H:i:s'));
        self::assertSame('2026-05-27 10:00:00', $job->createdAt->format('Y-m-d H:i:s'));
        self::assertNotNull($job->completedAt);
        self::assertSame('2026-05-27 12:30:00', $job->completedAt->format('Y-m-d H:i:s'));
    }
}
