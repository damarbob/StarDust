<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use DateTimeZone;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use StarDust\Clock\SystemClock;
use StarDust\Page\PageProvisioner;
use StarDust\Reconciler\DlqReplayer;
use StarDust\Reconciler\DlqWriter;
use StarDust\Reconciler\ImportJobWorkSource;
use StarDust\Reconciler\Reconciler;
use StarDust\Reconciler\SyncQueueWorkSource;
use StarDust\Watcher\CapacityReporter;
use StarDust\Watcher\CardinalitySampler;
use StarDust\Watcher\Watcher;
use StarDust\Write\BackfillExecutor;
use StarDust\Write\EntryWriter;
use StarDust\Write\SlotRowUpserter;

/**
 * Shared scaffolding for Phase 5 daemon smoke tests. Adds helpers to:
 *   - fill every free slot so the next reservation fails;
 *   - enqueue a sync_queue row pointing at a known entry_data id;
 *   - write a pending import_jobs row with an artifact on disk;
 *   - construct each Phase 5 collaborator bound to the test PDO.
 */
abstract class Phase5TestCase extends ReadPathTestCase
{
    protected function fillAllFreeStringSlots(): void
    {
        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM stardust_slot_assignments WHERE status = 'free' AND slot_type = 'str'"
        );
        $free = (int) $stmt->fetchColumn();

        $modelId = $this->createModel(1, 'filler_model_' . bin2hex(random_bytes(3)));
        for ($i = 0; $i < $free; $i++) {
            $fieldId = $this->createField($modelId, 'string', false, 'filler_' . $i);
            $this->reserveSlotFor($fieldId);
        }
    }

    protected function enqueueSyncRow(int $entryId): void
    {
        $now = (new SystemClock())->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO stardust_sync_queue (entry_id, created_at) VALUES (?, ?)'
        );
        $stmt->execute([$entryId, $now]);
    }

    /**
     * Writes a JSON artifact on disk and inserts a pending
     * stardust_import_jobs row pointing at it. Returns `[jobId, artifactPath]`.
     *
     * @param list<array{tenant_id: int, model_id: int, fields: array<string, mixed>}> $entries
     * @return array{0: int, 1: string}
     */
    protected function writePendingImportJob(int $tenantId, array $entries, ?string $artifactDir = null): array
    {
        $artifactDir ??= sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stardust';
        if (!is_dir($artifactDir)) {
            mkdir($artifactDir, 0777, true);
        }

        $filename = 'import_' . $tenantId . '_' . bin2hex(random_bytes(8)) . '.json';
        $path = $artifactDir . DIRECTORY_SEPARATOR . $filename;

        $payload = ['tenant_id' => $tenantId, 'entries' => $entries];
        file_put_contents(
            $path,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX,
        );

        $now = (new SystemClock())->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            'INSERT INTO stardust_import_jobs'
            . ' (tenant_id, status, artifact_path, entry_count, created_at)'
            . " VALUES (?, 'pending', ?, ?, ?)"
        );
        $stmt->execute([$tenantId, $filename, count($entries), $now]);

        return [(int) $this->pdo->lastInsertId(), $path];
    }

    /**
     * Writes a JSON artifact on disk and inserts a `processing`
     * stardust_import_jobs row with a controllable heartbeat age,
     * manifest checkpoint, and worker_identity — the fixture for
     * abandoned-claim / resume tests. Returns `[jobId, artifactPath]`.
     *
     * @param list<array{tenant_id: int, model_id: int, fields: array<string, mixed>}> $entries
     * @param array{chunks: int, entries_written: int}|null $manifest
     * @return array{0: int, 1: string}
     */
    protected function writeProcessingImportJob(
        int $tenantId,
        array $entries,
        int $heartbeatAgoSeconds,
        ?array $manifest = null,
        string $workerIdentity = 'origin-host:1:00000000-0000-0000-0000-000000000000',
        ?string $artifactDir = null,
    ): array {
        $artifactDir ??= sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stardust';
        if (!is_dir($artifactDir)) {
            mkdir($artifactDir, 0777, true);
        }

        $filename = 'import_' . $tenantId . '_' . bin2hex(random_bytes(8)) . '.json';
        $path = $artifactDir . DIRECTORY_SEPARATOR . $filename;

        $payload = ['tenant_id' => $tenantId, 'entries' => $entries];
        file_put_contents(
            $path,
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX,
        );

        $clock = new SystemClock();
        $nowDt = $clock->now()->setTimezone(new DateTimeZone('UTC'));
        $heartbeat = $nowDt->modify("-{$heartbeatAgoSeconds} seconds")->format('Y-m-d H:i:s');
        // claimed_at predates the heartbeat so its preservation across a
        // re-claim is observable.
        $claimedAt = $nowDt->modify('-' . ($heartbeatAgoSeconds + 5) . ' seconds')->format('Y-m-d H:i:s');
        $manifestJson = $manifest === null ? null : json_encode($manifest, JSON_THROW_ON_ERROR);

        $stmt = $this->pdo->prepare(
            'INSERT INTO stardust_import_jobs'
            . ' (tenant_id, status, artifact_path, entry_count, manifest,'
            . '  worker_identity, claimed_at, heartbeat_at, created_at)'
            . " VALUES (?, 'processing', ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $tenantId,
            $filename,
            count($entries),
            $manifestJson,
            $workerIdentity,
            $claimedAt,
            $heartbeat,
            $claimedAt,
        ]);

        return [(int) $this->pdo->lastInsertId(), $path];
    }

    protected function makeBackfillExecutor(): BackfillExecutor
    {
        return new BackfillExecutor(
            pdo: $this->pdo,
            slotRowUpserter: new SlotRowUpserter($this->pdo),
        );
    }

    protected function makeDlqWriter(?\Psr\Log\LoggerInterface $logger = null): DlqWriter
    {
        return new DlqWriter(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: $logger ?? new NullLogger(),
        );
    }

    protected function makeSyncQueueWorkSource(
        ?\Psr\Log\LoggerInterface $logger = null,
        int $chunkSize = 500,
    ): SyncQueueWorkSource {
        $log = $logger ?? new NullLogger();
        return new SyncQueueWorkSource(
            pdo: $this->pdo,
            logger: $log,
            backfillExecutor: $this->makeBackfillExecutor(),
            dlqWriter: $this->makeDlqWriter($log),
            chunkSize: $chunkSize,
        );
    }

    protected function makeImportJobWorkSource(
        ?\Psr\Log\LoggerInterface $logger = null,
        ?string $artifactDir = null,
        int $chunkSize = 500,
        int $leaseTimeoutSeconds = 30,
        ?ClockInterface $clock = null,
    ): ImportJobWorkSource {
        $log = $logger ?? new NullLogger();
        $entryWriter = new EntryWriter(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: $log,
            slotRowUpserter: new SlotRowUpserter($this->pdo),
        );
        return new ImportJobWorkSource(
            pdo: $this->pdo,
            clock: $clock ?? new SystemClock(),
            logger: $log,
            entryWriter: $entryWriter,
            dlqWriter: $this->makeDlqWriter($log),
            artifactDir: $artifactDir ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stardust'),
            chunkSize: $chunkSize,
            leaseTimeoutSeconds: $leaseTimeoutSeconds,
        );
    }

    protected function makeReconciler(
        ?\Psr\Log\LoggerInterface $logger = null,
        ?string $artifactDir = null,
    ): Reconciler {
        return new Reconciler(
            workSources: [
                $this->makeSyncQueueWorkSource($logger),
                $this->makeImportJobWorkSource($logger, $artifactDir),
            ],
            capacityWaitMillis: 0,
            interChunkDelayMicros: 0,
            sleepFn: static fn (int $_micros) => null,
        );
    }

    protected function makeWatcher(
        ?\Psr\Log\LoggerInterface $logger = null,
        float $threshold = 0.20,
        int $lockTimeoutSeconds = 10,
        ?ClockInterface $clock = null,
        ?\Closure $jitterFn = null,
        int $cardinalityIntervalSeconds = 86_400,
        int $cardinalityJitterSeconds = 8_640,
    ): Watcher {
        $log = $logger ?? new NullLogger();
        return new Watcher(
            pdo: $this->pdo,
            clock: $clock ?? new SystemClock(),
            logger: $log,
            capacityReporter: new CapacityReporter($this->pdo),
            pageProvisioner: new PageProvisioner(
                pdo: $this->pdo,
                clock: new SystemClock(),
                logger: $log,
            ),
            cardinalitySampler: new CardinalitySampler(
                pdo: $this->pdo,
                logger: $log,
                selectivityThreshold: 0.01,
                rowFloor: 10_000,
                distinctFloor: 10,
            ),
            capacityThreshold: $threshold,
            cardinalityIntervalSeconds: $cardinalityIntervalSeconds,
            cardinalityJitterSeconds: $cardinalityJitterSeconds,
            jitterFn: $jitterFn,
            provisionLockTimeoutSeconds: $lockTimeoutSeconds,
        );
    }

    protected function makeDlqReplayer(): DlqReplayer
    {
        return new DlqReplayer(
            pdo: $this->pdo,
            clock: new SystemClock(),
        );
    }

    /** Convenience: insert a DLQ row directly so replay tests have a fixture. */
    protected function seedDlqRow(string $source, ?int $entryId, string $reason, string $correlationId): int
    {
        $now = (new SystemClock())->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO stardust_reconciler_dlq'
            . ' (source, entry_id, tenant_id, model_id, reason, error_message, failed_at, retry_count, chunk_correlation_id)'
            . ' VALUES (?, ?, 1, 1, ?, NULL, ?, 0, ?)'
        );
        $stmt->execute([$source, $entryId, $reason, $now, $correlationId]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Helper: peek at the most-recently created `entry_slots_page_X` row for an entry. */
    protected function fetchSlotRow(int $pageId, int $entryId): ?array
    {
        $stmt = $this->pdo->query(
            "SELECT table_name FROM stardust_pages WHERE id = " . $pageId
        );
        $tableName = $stmt->fetchColumn();
        if ($tableName === false) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT * FROM {$tableName} WHERE entry_id = ?");
        $stmt->execute([$entryId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
}
