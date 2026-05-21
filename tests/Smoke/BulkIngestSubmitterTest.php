<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use Psr\Log\NullLogger;
use StarDust\Clock\SystemClock;
use StarDust\Exception\InvalidTenantIdException;
use StarDust\Logging\StdoutNdjsonLogger;
use StarDust\Write\BulkIngestSubmitter;
use StarDust\Write\EntryPayload;

/**
 * Phase 3 BulkIngestSubmitter smoke suite.
 */
final class BulkIngestSubmitterTest extends WritePathTestCase
{
    private string $artifactDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artifactDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'stardust_test_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (isset($this->artifactDir) && is_dir($this->artifactDir)) {
            foreach (glob($this->artifactDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->artifactDir);
        }
    }

    private function newSubmitter(?StdoutNdjsonLogger $logger = null): BulkIngestSubmitter
    {
        return new BulkIngestSubmitter(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: $logger ?? new NullLogger(),
            artifactDir: $this->artifactDir,
        );
    }

    /** Exit criterion: async submission persists job + artifact and returns the id. */
    public function testSubmitPersistsArtifactAndJobRow(): void
    {
        $modelId = $this->createModel(1);
        $payloads = [
            new EntryPayload(1, $modelId, ['a' => 1]),
            new EntryPayload(1, $modelId, ['a' => 2]),
        ];

        $id = $this->newSubmitter()->submit(1, $payloads);

        self::assertGreaterThan(0, $id->jobId);

        $row = $this->pdo
            ->query("SELECT * FROM stardust_import_jobs WHERE id = {$id->jobId}")
            ->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame(1, (int) $row['tenant_id']);
        self::assertSame('pending', (string) $row['status']);
        self::assertSame(2, (int) $row['entry_count']);
        self::assertNull($row['idempotency_key']);

        $artifactPath = (string) $row['artifact_path'];
        self::assertFileExists($artifactPath);

        $artifact = json_decode((string) file_get_contents($artifactPath), true);
        self::assertIsArray($artifact);
        self::assertSame(1, $artifact['tenant_id']);
        self::assertCount(2, $artifact['entries']);
        self::assertSame(1, $artifact['entries'][0]['fields']['a']);
    }

    /** Exit criterion: idempotency-key retry returns the same job id. */
    public function testRetryWithSameIdempotencyKeyReturnsExistingJob(): void
    {
        $modelId = $this->createModel(1);
        $payloads = [new EntryPayload(1, $modelId, ['a' => 1])];

        $submitter = $this->newSubmitter();
        $first = $submitter->submit(1, $payloads, 'key-A');
        $second = $submitter->submit(1, $payloads, 'key-A');

        self::assertSame($first->jobId, $second->jobId, 'Same key must return the same job id.');

        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM stardust_import_jobs WHERE idempotency_key = 'key-A'"
        )->fetchColumn();
        self::assertSame(1, $count, 'Only one row should exist for the key.');
    }

    /** Different idempotency keys produce distinct jobs. */
    public function testDifferentKeysProduceDistinctJobs(): void
    {
        $modelId = $this->createModel(1);
        $payloads = [new EntryPayload(1, $modelId, ['a' => 1])];

        $submitter = $this->newSubmitter();
        $a = $submitter->submit(1, $payloads, 'key-A');
        $b = $submitter->submit(1, $payloads, 'key-B');

        self::assertNotSame($a->jobId, $b->jobId);
    }

    /** Multiple NULL idempotency_key rows for the same tenant are permitted. */
    public function testNullIdempotencyKeyDoesNotCollide(): void
    {
        $modelId = $this->createModel(1);
        $payloads = [new EntryPayload(1, $modelId, ['a' => 1])];

        $submitter = $this->newSubmitter();
        $a = $submitter->submit(1, $payloads, null);
        $b = $submitter->submit(1, $payloads, null);

        self::assertNotSame($a->jobId, $b->jobId);

        $count = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM stardust_import_jobs WHERE idempotency_key IS NULL'
        )->fetchColumn();
        self::assertSame(2, $count);
    }

    /** tenant_id validation rejects invalid values before any SQL or filesystem write. */
    public function testSubmitRejectsInvalidTenantId(): void
    {
        $modelId = $this->createModel(1);
        $payloads = [new EntryPayload(1, $modelId, [])];

        $this->expectException(InvalidTenantIdException::class);
        try {
            $this->newSubmitter()->submit(0, $payloads);
        } finally {
            // Artifact directory should not even have been created on validation failure.
            self::assertFalse(is_dir($this->artifactDir));
        }
    }

    /** Mixed-tenant payload is rejected. */
    public function testSubmitRejectsMixedTenantPayload(): void
    {
        $modelId = $this->createModel(1);
        $payloads = [
            new EntryPayload(1, $modelId, []),
            new EntryPayload(2, $modelId, []),
        ];

        $this->expectException(\RuntimeException::class);
        $this->newSubmitter()->submit(1, $payloads);
    }

    /** ADR 0020 `bulk_accepted` event lands on the structured-log stream. */
    public function testSubmitEmitsBulkAcceptedEvent(): void
    {
        $modelId = $this->createModel(1);
        $payloads = [new EntryPayload(1, $modelId, ['x' => 1])];

        $stream = fopen('php://memory', 'r+');
        $submitter = $this->newSubmitter(new StdoutNdjsonLogger(new SystemClock(), $stream));

        $id = $submitter->submit(1, $payloads, 'logged-key');

        rewind($stream);
        $records = array_values(array_filter(explode("\n", (string) stream_get_contents($stream))));
        self::assertCount(1, $records);

        $decoded = json_decode($records[0], true);
        self::assertSame('bulk_accepted', $decoded['event'] ?? null);
        self::assertSame('bulk_api', $decoded['source'] ?? null);
        self::assertSame(1, $decoded['tenant_id'] ?? null);
        self::assertSame($id->jobId, $decoded['job_id'] ?? null);
        self::assertSame(1, $decoded['entry_count'] ?? null);
        self::assertSame('logged-key', $decoded['idempotency_key'] ?? null);
    }
}
