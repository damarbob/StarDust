<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Chronicler;

use StarDust\Tests\Smoke\Phase7TestCase;

/**
 * End-to-end happy paths for both CSV and JSON formats. Asserts:
 *   - `job_claimed` → `chunk_written*` → `job_complete` event sequence.
 *   - Artifact contents match seeded rows in id order.
 *   - Row state transitions to `completed` with `artifact_path` set.
 */
final class ChroniclerCompleteJobTest extends Phase7TestCase
{
    public function testCsvHappyPathSingleChunk(): void
    {
        $modelId = $this->createModel(1, 'csv_model');
        $this->createFieldNamed($modelId, 'name');
        $this->createFieldNamed($modelId, 'age', 'int');

        $this->seedEntryDataBatch(1, $modelId, 3, static fn (int $i) => [
            'name' => "User {$i}",
            'age'  => 20 + $i,
        ]);

        $logger = $this->makeRecordingLogger();
        $jobId = $this->seedExportJob(1, $modelId, 'pending', 'csv');

        $this->makeChronicler($logger, pageSize: 100)->tick();

        $events = array_map(static fn (array $r) => $r['context']['event'] ?? null, $logger->records());
        self::assertContains('job_claimed', $events);
        self::assertContains('chunk_written', $events);
        self::assertContains('job_complete', $events);

        $row = $this->fetchExportJob($jobId);
        self::assertSame('completed', $row['status']);
        self::assertNotNull($row['artifact_path']);
        self::assertNotNull($row['completed_at']);

        $rows = $this->readArtifactCsv((string) $row['artifact_path']);
        self::assertCount(3, $rows);
        self::assertSame(['age' => '20', 'name' => 'User 0'], $rows[0]);
        self::assertSame(['age' => '21', 'name' => 'User 1'], $rows[1]);
        self::assertSame(['age' => '22', 'name' => 'User 2'], $rows[2]);
    }

    public function testCsvHappyPathMultipleChunks(): void
    {
        $modelId = $this->createModel(1, 'csv_chunked');
        $this->createFieldNamed($modelId, 'idx', 'int');
        $this->seedEntryDataBatch(1, $modelId, 12);

        $logger = $this->makeRecordingLogger();
        $jobId = $this->seedExportJob(1, $modelId, 'pending', 'csv');

        // pageSize=5 → 3 chunks (5/5/2). Final chunk transitions to completed.
        $this->makeChronicler($logger, pageSize: 5)->tick();

        $chunkWritten = $this->recordsWithEvent($logger->records(), 'chunk_written');
        self::assertCount(3, $chunkWritten);

        $row = $this->fetchExportJob($jobId);
        self::assertSame('completed', $row['status']);

        $artifact = $this->readArtifactCsv((string) $row['artifact_path']);
        self::assertCount(12, $artifact);
    }

    public function testJsonHappyPathProducesValidArray(): void
    {
        $modelId = $this->createModel(1, 'json_model');
        $this->createFieldNamed($modelId, 'name');

        $this->seedEntryDataBatch(1, $modelId, 3, static fn (int $i) => [
            'name' => "user_{$i}",
            'idx'  => $i,
        ]);

        $jobId = $this->seedExportJob(1, $modelId, 'pending', 'json');
        $this->makeChronicler()->tick();

        $row = $this->fetchExportJob($jobId);
        self::assertSame('completed', $row['status']);

        $payload = $this->readArtifactJson((string) $row['artifact_path']);
        self::assertCount(3, $payload);
        self::assertSame('user_0', $payload[0]['name']);
        self::assertSame(2, $payload[2]['idx']);
    }

    public function testEmptyDatasetCompletesWithEmptyArtifact(): void
    {
        $modelId = $this->createModel(1, 'empty_model');
        $this->createFieldNamed($modelId, 'col');

        $jobId = $this->seedExportJob(1, $modelId, 'pending', 'csv');
        $this->makeChronicler()->tick();

        $row = $this->fetchExportJob($jobId);
        self::assertSame('completed', $row['status']);

        // CSV: just the header line, no data rows.
        $rows = $this->readArtifactCsv((string) $row['artifact_path']);
        self::assertSame([], $rows);
    }

    public function testEmptyJsonDatasetCompletesWithEmptyArray(): void
    {
        $modelId = $this->createModel(1, 'empty_json');
        $this->createFieldNamed($modelId, 'col');

        $jobId = $this->seedExportJob(1, $modelId, 'pending', 'json');
        $this->makeChronicler()->tick();

        $row = $this->fetchExportJob($jobId);
        self::assertSame('completed', $row['status']);
        $payload = $this->readArtifactJson((string) $row['artifact_path']);
        self::assertSame([], $payload);
    }

    public function testEventCarriesExpectedPayload(): void
    {
        $modelId = $this->createModel(1, 'payload_model');
        $this->createFieldNamed($modelId, 'k');
        $this->seedEntryDataBatch(1, $modelId, 2);

        $logger = $this->makeRecordingLogger();
        $jobId = $this->seedExportJob(1, $modelId, 'pending', 'csv');
        $this->makeChronicler($logger)->tick();

        $claimed = $this->recordsWithEvent($logger->records(), 'job_claimed');
        self::assertCount(1, $claimed);
        self::assertSame($jobId, $claimed[0]['context']['job_id']);
        self::assertSame(1, $claimed[0]['context']['tenant_id']);
        self::assertSame('pending', $claimed[0]['context']['claim_kind']);

        $complete = $this->recordsWithEvent($logger->records(), 'job_complete');
        self::assertCount(1, $complete);
        self::assertSame($jobId, $complete[0]['context']['job_id']);
        self::assertSame(2, $complete[0]['context']['rows_streamed_total']);
        self::assertSame(0, $complete[0]['context']['skip_count']);
    }
}
