<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Reconciler;

use PDO;
use StarDust\Reconciler\TickOutcome;
use StarDust\Tests\Smoke\Phase5TestCase;

/**
 * Phase 5 import-job processing exit criteria:
 *   - pending job transitions through processing → completed;
 *   - entries land in `entry_data`;
 *   - manifest carries per-chunk counts;
 *   - malformed artifact transitions the job to failed + DLQ.
 */
final class ImportJobTest extends Phase5TestCase
{
    public function testHappyPathTransitionsJobToCompleted(): void
    {
        [$modelId, $_fieldId, $_pageId, $fieldName] = $this->setupModelWithReservedField(1, 'string');

        $artifactDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stardust-test-' . bin2hex(random_bytes(4));
        mkdir($artifactDir, 0777, true);

        try {
            $entries = [
                ['tenant_id' => 1, 'model_id' => $modelId, 'fields' => [$fieldName => 'a']],
                ['tenant_id' => 1, 'model_id' => $modelId, 'fields' => [$fieldName => 'b']],
                ['tenant_id' => 1, 'model_id' => $modelId, 'fields' => [$fieldName => 'c']],
            ];
            [$jobId] = $this->writePendingImportJob(1, $entries, $artifactDir);

            $source = $this->makeImportJobWorkSource(artifactDir: $artifactDir);
            $outcome = $source->tickOne('test-corr-import');

            self::assertSame(TickOutcome::WORK_DONE, $outcome);

            $job = $this->fetchJob($jobId);
            self::assertSame('completed', $job['status']);
            self::assertNotNull($job['manifest']);
            $manifest = json_decode((string) $job['manifest'], true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(3, $manifest['entries_written']);

            $entryCount = (int) $this->pdo->query(
                'SELECT COUNT(*) FROM entry_data WHERE model_id = ' . $modelId
            )->fetchColumn();
            self::assertSame(3, $entryCount);
        } finally {
            $this->cleanupDir($artifactDir);
        }
    }

    public function testMalformedArtifactFailsJobAndQuarantines(): void
    {
        $artifactDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stardust-test-' . bin2hex(random_bytes(4));
        mkdir($artifactDir, 0777, true);

        try {
            $filename = 'import_1_' . bin2hex(random_bytes(4)) . '.json';
            file_put_contents(
                $artifactDir . DIRECTORY_SEPARATOR . $filename,
                '{ not even valid json',
                LOCK_EX,
            );

            $now = (new \StarDust\Clock\SystemClock())->now()
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
            $stmt = $this->pdo->prepare(
                'INSERT INTO stardust_import_jobs (tenant_id, status, artifact_path, entry_count, created_at)'
                . " VALUES (1, 'pending', ?, 0, ?)"
            );
            $stmt->execute([$filename, $now]);
            $jobId = (int) $this->pdo->lastInsertId();

            $source = $this->makeImportJobWorkSource(artifactDir: $artifactDir);
            $source->tickOne('test-corr-malformed');

            $job = $this->fetchJob($jobId);
            self::assertSame('failed', $job['status']);
            self::assertSame('malformed_json', $job['failed_reason']);

            $dlqCount = (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_reconciler_dlq')->fetchColumn();
            self::assertSame(1, $dlqCount);

            $dlq = $this->pdo->query(
                'SELECT * FROM stardust_reconciler_dlq ORDER BY id DESC LIMIT 1'
            )->fetch(PDO::FETCH_ASSOC);
            self::assertSame('bulk_import', $dlq['source']);
            self::assertSame('malformed_json', $dlq['reason']);
        } finally {
            $this->cleanupDir($artifactDir);
        }
    }

    /**
     * An artifact entry with no `fields` key writes an empty payload rather
     * than fataling.
     *
     * The decoded artifact's shape is whatever `json_decode` produced from a
     * file on disk, not something the type system verified, so the `?? []`
     * fallback in `ImportJobWorkSource` is a real guard. Static analysis reads
     * the declared shape and calls it redundant; this test is the evidence it
     * is not. A missing key is a lesser corruption than invalid JSON — it never
     * reaches the `malformed_json` path — so without the fallback the worker
     * would die on an entry the decoder happily accepted.
     */
    public function testEntryWithoutFieldsKeyWritesEmptyPayload(): void
    {
        [$modelId] = $this->setupModelWithReservedField(1, 'string');

        $artifactDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stardust-test-' . bin2hex(random_bytes(4));
        mkdir($artifactDir, 0777, true);

        try {
            $entries = [['tenant_id' => 1, 'model_id' => $modelId]];
            [$jobId] = $this->writePendingImportJob(1, $entries, $artifactDir);

            $source  = $this->makeImportJobWorkSource(artifactDir: $artifactDir);
            $outcome = $source->tickOne('test-corr-nofields');

            self::assertSame(TickOutcome::WORK_DONE, $outcome);
            self::assertSame('completed', $this->fetchJob($jobId)['status']);

            $fields = $this->pdo->query(
                'SELECT fields FROM entry_data WHERE model_id = ' . $modelId
            )->fetchColumn();
            self::assertSame([], json_decode((string) $fields, true, flags: JSON_THROW_ON_ERROR));
        } finally {
            $this->cleanupDir($artifactDir);
        }
    }

    public function testIdleWhenNoPendingJob(): void
    {
        $source = $this->makeImportJobWorkSource();
        self::assertSame(TickOutcome::IDLE, $source->tickOne('test-corr-idle'));
    }

    /**
     * ADR 0011 §26 / ADR 0040: the manifest enumerates each chunk's
     * outcome and its entity ID range.
     *
     * The ranges are asserted against the ids `entry_data` actually
     * assigned, not against an expected sequence, so the test proves the
     * record describes reality rather than restating the fixture.
     */
    public function testManifestEnumeratesEachChunkWithItsEntityIdRange(): void
    {
        [$modelId, $_fieldId, $_pageId, $fieldName] = $this->setupModelWithReservedField(1, 'string');

        $artifactDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stardust-test-' . bin2hex(random_bytes(4));
        mkdir($artifactDir, 0777, true);

        try {
            $entries = [];
            foreach (['a', 'b', 'c', 'd', 'e'] as $value) {
                $entries[] = ['tenant_id' => 1, 'model_id' => $modelId, 'fields' => [$fieldName => $value]];
            }
            [$jobId] = $this->writePendingImportJob(1, $entries, $artifactDir);

            // 5 entries at 2 per chunk => 3 chunks, the last one short.
            $source = $this->makeImportJobWorkSource(artifactDir: $artifactDir, chunkSize: 2);
            self::assertSame(TickOutcome::WORK_DONE, $source->tickOne('test-corr-ranges'));

            $manifest = $this->fetchManifest($jobId);
            self::assertSame(3, $manifest['chunks']);
            self::assertSame(5, $manifest['entries_written']);

            $records = $manifest['chunk_manifest'];
            self::assertCount(3, $records);

            $ids = $this->pdo->query(
                'SELECT id FROM entry_data WHERE model_id = ' . $modelId . ' ORDER BY id'
            )->fetchAll(PDO::FETCH_COLUMN);
            $ids = array_map('intval', $ids);
            self::assertCount(5, $ids);

            $expected = [
                ['index' => 1, 'size' => 2, 'first' => $ids[0], 'last' => $ids[1]],
                ['index' => 2, 'size' => 2, 'first' => $ids[2], 'last' => $ids[3]],
                ['index' => 3, 'size' => 1, 'first' => $ids[4], 'last' => $ids[4]],
            ];

            foreach ($expected as $i => $want) {
                self::assertSame($want['index'], $records[$i]['index']);
                self::assertSame($want['size'], $records[$i]['size']);
                self::assertSame('committed', $records[$i]['outcome']);
                self::assertSame($want['first'], $records[$i]['entry_id_first']);
                self::assertSame($want['last'], $records[$i]['entry_id_last']);
            }
        } finally {
            $this->cleanupDir($artifactDir);
        }
    }

    /**
     * A chunk that fails appends one terminal `failed` record and leaves
     * the committed counters exactly where they were — they are the
     * boundary a consumer replays from, so a failure must not move them.
     */
    public function testFailedChunkAppendsTerminalRecordWithoutMovingTheBoundary(): void
    {
        [$modelId, $_fieldId, $_pageId, $fieldName] = $this->setupModelWithReservedField(1, 'string');

        $artifactDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stardust-test-' . bin2hex(random_bytes(4));
        mkdir($artifactDir, 0777, true);

        try {
            $entries = [
                ['tenant_id' => 1, 'model_id' => $modelId, 'fields' => [$fieldName => 'a']],
                ['tenant_id' => 1, 'model_id' => $modelId, 'fields' => [$fieldName => 'b']],
                // Over FilterLimits::DEFAULT_MAX_STRING_LENGTH (4096), so
                // PayloadSplitter rejects it before any SQL.
                ['tenant_id' => 1, 'model_id' => $modelId, 'fields' => [$fieldName => str_repeat('x', 5000)]],
            ];
            [$jobId] = $this->writePendingImportJob(1, $entries, $artifactDir);

            $source = $this->makeImportJobWorkSource(artifactDir: $artifactDir, chunkSize: 2);
            $source->tickOne('test-corr-failed-record');

            $job = $this->fetchJob($jobId);
            self::assertSame('failed', $job['status']);
            self::assertSame('entry_write_failed', $job['failed_reason']);

            $manifest = $this->fetchManifest($jobId);
            self::assertSame(1, $manifest['chunks'], 'the committed chunk count must not move');
            self::assertSame(2, $manifest['entries_written'], 'the replay boundary must not move');

            $records = $manifest['chunk_manifest'];
            self::assertCount(2, $records);
            self::assertSame('committed', $records[0]['outcome']);
            self::assertSame('failed', $records[1]['outcome']);
            self::assertSame(2, $records[1]['index']);
            self::assertSame(1, $records[1]['size']);
            self::assertSame('entry_write_failed', $records[1]['failure_reason']);
            // The window rolled back, so the chunk owns no entry_data rows.
            self::assertNull($records[1]['entry_id_first']);
            self::assertNull($records[1]['entry_id_last']);
        } finally {
            $this->cleanupDir($artifactDir);
        }
    }

    /**
     * When the FIRST chunk is the one that fails, the counters are
     * omitted from the manifest rather than written as 0.
     *
     * `ImportJob` documents `null` ("nothing committed") as distinct
     * from `0` ("ran, wrote nothing"), and `getImportJob()` reads an
     * absent key as null. Writing `chunks: 0` here would collapse the
     * two silently.
     */
    public function testFirstChunkFailureOmitsTheCountersRatherThanWritingZero(): void
    {
        [$modelId, $_fieldId, $_pageId, $fieldName] = $this->setupModelWithReservedField(1, 'string');

        $artifactDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stardust-test-' . bin2hex(random_bytes(4));
        mkdir($artifactDir, 0777, true);

        try {
            $entries = [
                ['tenant_id' => 1, 'model_id' => $modelId, 'fields' => [$fieldName => str_repeat('x', 5000)]],
            ];
            [$jobId] = $this->writePendingImportJob(1, $entries, $artifactDir);

            $source = $this->makeImportJobWorkSource(artifactDir: $artifactDir, chunkSize: 2);
            $source->tickOne('test-corr-first-chunk-failure');

            $manifest = $this->fetchManifest($jobId);
            self::assertArrayNotHasKey('chunks', $manifest);
            self::assertArrayNotHasKey('entries_written', $manifest);

            self::assertCount(1, $manifest['chunk_manifest']);
            self::assertSame('failed', $manifest['chunk_manifest'][0]['outcome']);
        } finally {
            $this->cleanupDir($artifactDir);
        }
    }

    /**
     * A `malformed_json` failure trips before the first window opens, so
     * ADR 0011 §26's "for jobs that have produced any chunks" means the
     * column stays NULL — no manifest, not an empty one.
     */
    public function testMalformedArtifactLeavesTheManifestNull(): void
    {
        $artifactDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stardust-test-' . bin2hex(random_bytes(4));
        mkdir($artifactDir, 0777, true);

        try {
            $filename = 'import_1_' . bin2hex(random_bytes(4)) . '.json';
            file_put_contents($artifactDir . DIRECTORY_SEPARATOR . $filename, '{ nope', LOCK_EX);

            $now = (new \StarDust\Clock\SystemClock())->now()
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
            $stmt = $this->pdo->prepare(
                'INSERT INTO stardust_import_jobs (tenant_id, status, artifact_path, entry_count, created_at)'
                . " VALUES (1, 'pending', ?, 0, ?)"
            );
            $stmt->execute([$filename, $now]);
            $jobId = (int) $this->pdo->lastInsertId();

            $this->makeImportJobWorkSource(artifactDir: $artifactDir)->tickOne('test-corr-null-manifest');

            $job = $this->fetchJob($jobId);
            self::assertSame('failed', $job['status']);
            self::assertNull($job['manifest']);
        } finally {
            $this->cleanupDir($artifactDir);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchManifest(int $jobId): array
    {
        $raw = $this->fetchJob($jobId)['manifest'];
        self::assertNotNull($raw);
        $decoded = json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    private function fetchJob(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM stardust_import_jobs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }

    private function cleanupDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
