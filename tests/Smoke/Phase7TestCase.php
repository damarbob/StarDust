<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use DateTimeZone;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use StarDust\Chronicler\ArtifactStreamFactory;
use StarDust\Chronicler\Chronicler;
use StarDust\Chronicler\DiskPressureGate;
use StarDust\Chronicler\EntryDataPager;
use StarDust\Chronicler\ExportJobClaimer;
use StarDust\Chronicler\ExportJobProcessor;
use StarDust\Chronicler\GcSweeper;
use StarDust\Chronicler\HeaderResolver;
use StarDust\Clock\SystemClock;
use StarDust\Export\ExportJobSubmitter;

/**
 * Shared scaffolding for Phase 7 Chronicler + Export-API smoke tests.
 *
 * Builds on the Phase 6b helper chain so retype/promotion fixtures are
 * available alongside the Chronicler-specific helpers introduced here.
 *
 * Test temp artifact directories are tracked and removed in
 * {@see self::tearDown()} so a misbehaving test cannot leak GB of
 * orphan CSV/JSON files between runs.
 */
abstract class Phase7TestCase extends Phase6bTestCase
{
    /** @var list<string> */
    private array $tempArtifactDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tempArtifactDirs as $dir) {
            $this->rmTree($dir);
        }
        $this->tempArtifactDirs = [];
        parent::tearDown();
    }

    // === Factory helpers ===

    protected function makeExportSubmitter(
        ?LoggerInterface $logger = null,
        int $perTenantActiveCap = 3,
    ): ExportJobSubmitter {
        return new ExportJobSubmitter(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: $logger ?? new NullLogger(),
            perTenantActiveCap: $perTenantActiveCap,
        );
    }

    protected function makeChronicler(
        ?LoggerInterface $logger = null,
        ?string $artifactDir = null,
        int $pageSize = 500,
        int $skipCountCap = 1_000,
        int $artifactSizeCapBytes = 5 * 1024 * 1024 * 1024,
        int $leaseTimeoutSeconds = 30,
        int $artifactTtlSeconds = 86_400,
        int $orphanedPartialTtlSeconds = 3_600,
        float $lowDiskThresholdPct = 0.0, // disabled by default for tests
        int $deadlockRetryBudget = 3,
    ): Chronicler {
        $log = $logger ?? new NullLogger();
        $dir = $artifactDir ?? $this->makeTempArtifactDir();
        return new Chronicler(
            logger: $log,
            claimer: new ExportJobClaimer(
                pdo: $this->pdo,
                clock: new SystemClock(),
                leaseTimeoutSeconds: $leaseTimeoutSeconds,
            ),
            processor: new ExportJobProcessor(
                pdo: $this->pdo,
                clock: new SystemClock(),
                logger: $log,
                pager: new EntryDataPager($this->pdo),
                headerResolver: new HeaderResolver($this->pdo),
                streamFactory: new ArtifactStreamFactory($dir),
                pageSize: $pageSize,
                interChunkDelayMicros: 0,
                deadlockRetryBudget: $deadlockRetryBudget,
                skipCountCap: $skipCountCap,
                artifactSizeCapBytes: $artifactSizeCapBytes,
                dbDisconnectBackoffSeconds: [0, 0, 0],
                sleepFn: static fn (int $_micros) => null,
            ),
            diskGate: new DiskPressureGate(
                artifactDir: $dir,
                lowDiskThresholdPct: $lowDiskThresholdPct,
            ),
            gcSweeper: new GcSweeper(
                pdo: $this->pdo,
                logger: $log,
                artifactTtlSeconds: $artifactTtlSeconds,
                orphanedPartialTtlSeconds: $orphanedPartialTtlSeconds,
            ),
        );
    }

    protected function makeProcessor(
        ?LoggerInterface $logger = null,
        ?string $artifactDir = null,
        int $pageSize = 500,
        int $skipCountCap = 1_000,
        int $artifactSizeCapBytes = 5 * 1024 * 1024 * 1024,
        int $deadlockRetryBudget = 3,
    ): ExportJobProcessor {
        return new ExportJobProcessor(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: $logger ?? new NullLogger(),
            pager: new EntryDataPager($this->pdo),
            headerResolver: new HeaderResolver($this->pdo),
            streamFactory: new ArtifactStreamFactory($artifactDir ?? $this->makeTempArtifactDir()),
            pageSize: $pageSize,
            interChunkDelayMicros: 0,
            deadlockRetryBudget: $deadlockRetryBudget,
            skipCountCap: $skipCountCap,
            artifactSizeCapBytes: $artifactSizeCapBytes,
            dbDisconnectBackoffSeconds: [0, 0, 0],
            sleepFn: static fn (int $_micros) => null,
        );
    }

    // === Seeders ===

    /**
     * Inserts a `stardust_export_jobs` row directly without going
     * through the submitter. Useful when a test needs a specific state
     * (e.g., `processing` with a stale heartbeat) the public API
     * would never produce on its own.
     *
     * @param array<string,mixed> $filter Stored verbatim; the
     *   Chronicler reads `model_id` out of this map.
     */
    protected function seedExportJob(
        int $tenantId,
        int $modelId,
        string $status = 'pending',
        string $format = 'csv',
        ?int $lastCursor = null,
        ?string $artifactPath = null,
        ?string $workerIdentity = null,
        ?string $heartbeatAt = null,
        ?string $claimedAt = null,
        ?string $createdAt = null,
        ?string $completedAt = null,
        ?string $failedReason = null,
        int $skipCount = 0,
        array $filter = [],
    ): int {
        $filter['model_id'] = $modelId;
        $filterJson = json_encode($filter, JSON_THROW_ON_ERROR);
        $now = $createdAt ?? $this->utcNowString();

        $stmt = $this->pdo->prepare(
            'INSERT INTO stardust_export_jobs'
            . ' (tenant_id, status, filter, format, last_cursor, artifact_path,'
            . '  failed_reason, skip_count, worker_identity, claimed_at,'
            . '  heartbeat_at, created_at, completed_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $tenantId, $status, $filterJson, $format, $lastCursor, $artifactPath,
            $failedReason, $skipCount, $workerIdentity, $claimedAt,
            $heartbeatAt, $now, $completedAt,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Bulk-INSERT `entry_data` rows for export fixtures. Bypasses
     * {@see \StarDust\Write\EntryWriter} so the test can build a
     * large dataset cheaply without going through the slot UPSERT
     * path. The Chronicler reads only `(id, fields)` so the absence
     * of `entry_slots_page_X` rows is fine.
     *
     * @param callable(int $i): array<string,mixed>|null $fieldsBuilder
     *   Per-index field builder; when null, emits `['idx' => $i]`.
     * @return list<int>
     */
    protected function seedEntryDataBatch(
        int $tenantId,
        int $modelId,
        int $count,
        ?callable $fieldsBuilder = null,
    ): array {
        $fieldsBuilder ??= static fn (int $i): array => ['idx' => $i];

        $stmt = $this->pdo->prepare(
            'INSERT INTO entry_data (tenant_id, model_id, created_at, updated_at, fields)'
            . ' VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?)'
        );

        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $stmt->execute([
                $tenantId,
                $modelId,
                json_encode($fieldsBuilder($i), JSON_THROW_ON_ERROR),
            ]);
            $ids[] = (int) $this->pdo->lastInsertId();
        }
        return $ids;
    }

    /**
     * Inserts a stardust_fields row directly with a chosen name —
     * tests with deterministic header expectations need the field
     * names to be known in advance.
     */
    protected function createFieldNamed(int $modelId, string $name, string $declaredType = 'string'): int
    {
        return $this->createField($modelId, $declaredType, false, $name);
    }

    // === Inspection helpers ===

    protected function fetchExportJob(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM stardust_export_jobs WHERE id = ?');
        $stmt->execute([$jobId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return list<array<string,string>> Header + per-row keyed by column */
    protected function readArtifactCsv(string $path): array
    {
        $raw = (string) file_get_contents($path);
        $lines = explode("\r\n", $raw);
        // Drop trailing empty line from final \r\n.
        if (end($lines) === '') {
            array_pop($lines);
        }
        if ($lines === []) {
            return [];
        }
        // PHP 8.4 deprecates the implicit $escape argument; pass ''
        // explicitly to opt into the post-8.4 behaviour (no escape
        // character, which matches RFC 4180 — only the doubled-quote
        // rule applies).
        $header = str_getcsv($lines[0], ',', '"', '');
        $rows = [];
        for ($i = 1; $i < count($lines); $i++) {
            $cells = str_getcsv($lines[$i], ',', '"', '');
            $assoc = [];
            foreach ($header as $j => $name) {
                $assoc[$name] = $cells[$j] ?? '';
            }
            $rows[] = $assoc;
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    protected function readArtifactJson(string $path): array
    {
        $raw = (string) file_get_contents($path);
        $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    // === Temp artifact directory ===

    protected function makeTempArtifactDir(): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'stardust-test-' . bin2hex(random_bytes(6));
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            self::fail("Could not create temp artifact dir: {$dir}");
        }
        $this->tempArtifactDirs[] = $dir;
        return $dir;
    }

    protected function utcNowString(): string
    {
        return (new SystemClock())->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (is_dir($path)) {
                $this->rmTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
