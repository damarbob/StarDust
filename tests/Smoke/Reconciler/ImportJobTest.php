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

    public function testIdleWhenNoPendingJob(): void
    {
        $source = $this->makeImportJobWorkSource();
        self::assertSame(TickOutcome::IDLE, $source->tickOne('test-corr-idle'));
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
