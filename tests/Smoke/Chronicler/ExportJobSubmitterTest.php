<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Chronicler;

use StarDust\Exception\ExportFilterNotSupportedException;
use StarDust\Exception\ExportJobActiveCapExceededException;
use StarDust\Exception\InvalidTenantIdException;
use StarDust\Export\ExportJobRequest;
use StarDust\Tests\Smoke\Phase7TestCase;

/**
 * Submission API contract:
 *   - Validates tenant id at boundary.
 *   - Validates `format` ('csv' | 'json') at DTO construction.
 *   - Stores filter JSON with `model_id` injected.
 *   - Enforces per-tenant active-job cap atomically (≥ cap ⇒ throws).
 *   - Emits `export_accepted` event (source `export_api`) on success.
 */
final class ExportJobSubmitterTest extends Phase7TestCase
{
    /**
     * A non-empty filter is refused rather than accepted and ignored.
     *
     * The Chronicler's pager selects on `tenant_id / model_id /
     * deleted_at` only and never reads the stored filter back, so
     * accepting one turned a request for a subset into a full extract of
     * the model — silently, into an artifact the consumer keeps.
     */
    public function testNonEmptyFilterIsRejected(): void
    {
        $modelId = $this->createModel(1, 'filter_reject');
        $this->createFieldNamed($modelId, 'k');

        $this->expectException(ExportFilterNotSupportedException::class);
        $this->makeExportSubmitter()->submit(new ExportJobRequest(
            tenantId: 1,
            modelId: $modelId,
            format: 'csv',
            filter: ['name' => ['eq' => 'Acme']],
        ));
    }

    /**
     * The guard sits before the transaction, the INSERT and the
     * per-tenant cap probe, so a rejected request leaves no trace and
     * costs the tenant nothing.
     */
    public function testRejectedFilterInsertsNoRowAndConsumesNoCapSlot(): void
    {
        $modelId = $this->createModel(1, 'filter_reject_atomic');
        $this->createFieldNamed($modelId, 'k');

        $logger = $this->makeRecordingLogger();
        $submitter = $this->makeExportSubmitter($logger, perTenantActiveCap: 1);

        try {
            $submitter->submit(new ExportJobRequest(
                tenantId: 1,
                modelId: $modelId,
                format: 'csv',
                filter: ['anything' => 1],
            ));
            self::fail('Expected ExportFilterNotSupportedException.');
        } catch (ExportFilterNotSupportedException $e) {
            self::assertSame(1, $e->tenantId);
            self::assertSame($modelId, $e->modelId);
            self::assertSame(['anything'], $e->filterKeys);
        }

        $count = (int) $this->pdo
            ->query('SELECT COUNT(*) FROM stardust_export_jobs')
            ->fetchColumn();
        self::assertSame(0, $count, 'A refused submission must insert nothing.');

        self::assertSame(
            [],
            $this->recordsWithEvent($logger->records(), 'export_accepted'),
            'A refused submission must not emit export_accepted.',
        );

        // The cap is 1; if the refusal had consumed a slot this would
        // now throw ExportJobActiveCapExceededException instead.
        $id = $submitter->submit(new ExportJobRequest(
            tenantId: 1,
            modelId: $modelId,
            format: 'csv',
        ));
        self::assertGreaterThan(0, $id->jobId);
    }

    public function testSubmitInsertsPendingRow(): void
    {
        $modelId = $this->createModel(1, 'submit');
        $this->createFieldNamed($modelId, 'k');

        $logger = $this->makeRecordingLogger();
        $submitter = $this->makeExportSubmitter($logger);

        $id = $submitter->submit(new ExportJobRequest(
            tenantId: 1,
            modelId: $modelId,
            format: 'csv',
        ));

        self::assertGreaterThan(0, $id->jobId);

        $row = $this->fetchExportJob($id->jobId);
        self::assertSame('pending', $row['status']);
        self::assertSame('csv', $row['format']);
        self::assertNull($row['worker_identity']);
        self::assertNull($row['claimed_at']);
        self::assertNull($row['last_cursor']);

        // Envelope shape stays {model_id, filter} — ExportJobClaimer
        // ::extractModelId() depends on it — with `filter` now always
        // empty, since a non-empty one is refused at submission.
        $envelope = json_decode((string) $row['filter'], true);
        self::assertSame($modelId, $envelope['model_id']);
        self::assertSame([], $envelope['filter']);

        $accepted = $this->recordsWithEvent($logger->records(), 'export_accepted');
        self::assertCount(1, $accepted);
        self::assertSame($id->jobId, $accepted[0]['context']['job_id']);
        self::assertSame($modelId, $accepted[0]['context']['model_id']);
        self::assertSame('export_api', $accepted[0]['context']['source']);
    }

    public function testCapEnforcedAfterThreeActiveJobs(): void
    {
        $modelId = $this->createModel(1, 'cap');
        $this->createFieldNamed($modelId, 'k');

        $submitter = $this->makeExportSubmitter(perTenantActiveCap: 3);

        for ($i = 0; $i < 3; $i++) {
            $submitter->submit(new ExportJobRequest(1, $modelId, 'csv'));
        }

        try {
            $submitter->submit(new ExportJobRequest(1, $modelId, 'csv'));
            self::fail('Expected ExportJobActiveCapExceededException.');
        } catch (ExportJobActiveCapExceededException $e) {
            self::assertSame(1, $e->tenantId);
            self::assertSame(3, $e->activeCount);
            self::assertSame(3, $e->cap);
        }
    }

    public function testCapCountsProcessingJobsToo(): void
    {
        $modelId = $this->createModel(1, 'cap_processing');
        $this->createFieldNamed($modelId, 'k');

        // Seed two processing rows directly + one pending.
        $this->seedExportJob(1, $modelId, status: 'processing',
            workerIdentity: 'h:1:u', heartbeatAt: $this->utcNowString(),
            claimedAt: $this->utcNowString());
        $this->seedExportJob(1, $modelId, status: 'processing',
            workerIdentity: 'h:2:u', heartbeatAt: $this->utcNowString(),
            claimedAt: $this->utcNowString());
        $this->seedExportJob(1, $modelId, status: 'pending');

        $submitter = $this->makeExportSubmitter(perTenantActiveCap: 3);

        $this->expectException(ExportJobActiveCapExceededException::class);
        $submitter->submit(new ExportJobRequest(1, $modelId, 'csv'));
    }

    public function testCompletedJobsDoNotCountAgainstCap(): void
    {
        $modelId = $this->createModel(1, 'cap_completed');
        $this->createFieldNamed($modelId, 'k');

        // 3 completed + 0 active → 4th submission accepted.
        for ($i = 0; $i < 3; $i++) {
            $this->seedExportJob(1, $modelId, status: 'completed',
                artifactPath: '/tmp/x',
                heartbeatAt: $this->utcNowString(),
                claimedAt: $this->utcNowString(),
                completedAt: $this->utcNowString());
        }

        $submitter = $this->makeExportSubmitter(perTenantActiveCap: 3);
        $id = $submitter->submit(new ExportJobRequest(1, $modelId, 'csv'));
        self::assertGreaterThan(0, $id->jobId);
    }

    public function testCapIsPerTenant(): void
    {
        $modelA = $this->createModel(1, 'mA');
        $modelB = $this->createModel(2, 'mB');
        $this->createFieldNamed($modelA, 'k');
        $this->createFieldNamed($modelB, 'k');

        $submitter = $this->makeExportSubmitter(perTenantActiveCap: 1);

        $submitter->submit(new ExportJobRequest(1, $modelA, 'csv'));
        $submitter->submit(new ExportJobRequest(2, $modelB, 'csv'));

        try {
            $submitter->submit(new ExportJobRequest(1, $modelA, 'csv'));
            self::fail('Expected ExportJobActiveCapExceededException for tenant 1.');
        } catch (ExportJobActiveCapExceededException $e) {
            self::assertSame(1, $e->tenantId);
        }
    }

    public function testInvalidTenantIdRejected(): void
    {
        $modelId = $this->createModel(1, 'm');
        $this->createFieldNamed($modelId, 'k');
        $this->expectException(InvalidTenantIdException::class);
        $this->makeExportSubmitter()->submit(new ExportJobRequest(0, $modelId, 'csv'));
    }

    public function testInvalidFormatRejectedAtDtoConstruction(): void
    {
        $this->expectException(\RuntimeException::class);
        new ExportJobRequest(1, 1, 'xml');
    }
}
