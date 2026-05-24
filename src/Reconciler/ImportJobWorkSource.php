<?php

declare(strict_types=1);

namespace StarDust\Reconciler;

use DateTimeZone;
use JsonException;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Exception\ImportJobArtifactException;
use StarDust\Support\UuidV4;
use StarDust\Write\EntryPayload;
use StarDust\Write\EntryWriter;
use Throwable;

/**
 * Claims one `stardust_import_jobs` row at a time and applies the
 * batched-write contract of ADR 0011 + ADR 0028.
 *
 * Claim protocol:
 *   - `UPDATE … SET status='processing', worker_identity=?, claimed_at=NOW(),
 *     heartbeat_at=NOW() WHERE status='pending' ORDER BY id LIMIT 1`.
 *     `worker_identity` is `host:pid:uuid` so a stale lease can be
 *     detected and re-claimed in a later phase.
 *   - `SELECT … WHERE worker_identity = ?` retrieves the row this
 *     worker won. Other workers see no claim and move on.
 *
 * Read & process:
 *   - Reads `artifact_path` via `file_get_contents()` and decodes the
 *     single-document JSON per ADR 0028 (`tenant_id` + `entries[]`).
 *   - Iterates entries in `chunkSize` windows; each window opens its
 *     own transaction, calls
 *     {@see EntryWriter::writeWithinTransaction()} for every entry,
 *     and refreshes `heartbeat_at` inside the same transaction.
 *
 * Completion:
 *   - On success: `status='completed'`, `manifest` populated with
 *     per-chunk counts, `completed_at=NOW()`.
 *   - On artifact failure: `status='failed'`,
 *     `failed_reason='malformed_json'`, DLQ row inserted.
 *   - On per-entry failure: the whole chunk rolls back; the job moves
 *     to `failed` with `failed_reason='entry_write_failed'` and a DLQ
 *     row is inserted. Partial completion is not supported in Phase 5
 *     — the manifest reports the boundary so an operator can replay.
 */
final class ImportJobWorkSource implements ReconcilerWorkSource
{
    /**
     * @param callable(int):void|null $sleepFn Injected for tests; defaults to `usleep`.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly EntryWriter $entryWriter,
        private readonly DlqWriter $dlqWriter,
        private readonly string $artifactDir,
        private readonly int $chunkSize,
        private readonly int $interChunkDelayMicros = 0,
        private $sleepFn = null,
    ) {
        $this->sleepFn = $sleepFn ?? static fn (int $micros) => usleep($micros);
    }

    public function tickOne(string $chunkCorrelationId): TickOutcome
    {
        $workerIdentity = $this->workerIdentity();
        $jobId = $this->claim($workerIdentity);
        if ($jobId === null) {
            return TickOutcome::IDLE;
        }

        $job = $this->loadJob($jobId);
        if ($job === null) {
            // Vanishingly unlikely — another worker raced us to the
            // same row. Move on.
            return TickOutcome::IDLE;
        }

        $this->logger->info('import_job chunk claimed', [
            'event'          => 'chunk_claimed',
            'source'         => 'reconciler',
            'correlation_id' => $chunkCorrelationId,
            'queue'          => 'import_jobs',
            'job_id'         => (int) $job['id'],
            'tenant_id'      => (int) $job['tenant_id'],
        ]);

        try {
            $payload = $this->loadArtifact((string) $job['artifact_path']);
        } catch (ImportJobArtifactException $e) {
            $this->failJob(
                jobId: (int) $job['id'],
                tenantId: (int) $job['tenant_id'],
                failedReason: 'malformed_json',
                chunkCorrelationId: $chunkCorrelationId,
                errorMessage: $e->getMessage(),
            );
            return TickOutcome::WORK_DONE;
        }

        $manifest = $this->processEntries(
            jobId: (int) $job['id'],
            tenantId: (int) $job['tenant_id'],
            payload: $payload,
            chunkCorrelationId: $chunkCorrelationId,
        );

        if ($manifest === null) {
            return TickOutcome::WORK_DONE;
        }

        $this->completeJob(
            jobId: (int) $job['id'],
            manifest: $manifest,
            chunkCorrelationId: $chunkCorrelationId,
        );

        return TickOutcome::WORK_DONE;
    }

    private function workerIdentity(): string
    {
        $host = gethostname() ?: 'unknown';
        return $host . ':' . getmypid() . ':' . UuidV4::generate();
    }

    private function claim(string $workerIdentity): ?int
    {
        $now = $this->utcNow();

        $update = $this->pdo->prepare(
            'UPDATE stardust_import_jobs'
            . " SET status = 'processing',"
            . '     worker_identity = ?, claimed_at = ?, heartbeat_at = ?'
            . " WHERE status = 'pending'"
            . ' ORDER BY id LIMIT 1'
        );
        $update->execute([$workerIdentity, $now, $now]);
        if ($update->rowCount() === 0) {
            return null;
        }

        $select = $this->pdo->prepare(
            'SELECT id FROM stardust_import_jobs'
            . " WHERE status = 'processing' AND worker_identity = ?"
            . ' ORDER BY claimed_at DESC LIMIT 1'
        );
        $select->execute([$workerIdentity]);
        $id = $select->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * @return array{id: int|string, tenant_id: int|string, artifact_path: string}|null
     */
    private function loadJob(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, artifact_path FROM stardust_import_jobs WHERE id = ?'
        );
        $stmt->execute([$jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @return array{tenant_id: int, entries: list<array{tenant_id: int, model_id: int, fields: array<string, mixed>}>}
     */
    private function loadArtifact(string $artifactPath): array
    {
        // Artifact paths are relative to Config::$artifactDir per the
        // Phase 3 submitter; absolute paths are accepted for tests.
        $resolved = $this->resolveArtifactPath($artifactPath);

        if (!is_readable($resolved)) {
            throw new ImportJobArtifactException("Artifact missing or unreadable: {$resolved}");
        }
        $raw = @file_get_contents($resolved);
        if ($raw === false) {
            throw new ImportJobArtifactException("Artifact read failed: {$resolved}");
        }

        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ImportJobArtifactException("Artifact JSON decode failed: " . $e->getMessage());
        }

        if (!is_array($decoded) || !array_key_exists('tenant_id', $decoded)
            || !array_key_exists('entries', $decoded) || !is_array($decoded['entries'])) {
            throw new ImportJobArtifactException(
                "Artifact shape mismatch: expected {tenant_id, entries[]}."
            );
        }

        return $decoded;
    }

    private function resolveArtifactPath(string $artifactPath): string
    {
        if (preg_match('#^([A-Za-z]:[\\\\/]|/|\\\\\\\\)#', $artifactPath) === 1) {
            return $artifactPath;
        }
        return $this->artifactDir . DIRECTORY_SEPARATOR . $artifactPath;
    }

    /**
     * @param array{tenant_id: int, entries: list<array{tenant_id: int, model_id: int, fields: array<string, mixed>}>} $payload
     * @return array{chunks: int, entries_written: int}|null `null` ⇒ job was failed; caller must NOT call completeJob
     */
    private function processEntries(int $jobId, int $tenantId, array $payload, string $chunkCorrelationId): ?array
    {
        $entries = $payload['entries'];
        $totalEntries = count($entries);
        $entriesWritten = 0;
        $chunkCount = 0;

        for ($offset = 0; $offset < $totalEntries; $offset += $this->chunkSize) {
            // Apply inter-chunk delay BEFORE every chunk except the
            // first — matches BulkIngestor's "between chunks, never
            // before the first or after the last" semantics.
            if ($offset > 0 && $this->interChunkDelayMicros > 0) {
                ($this->sleepFn)($this->interChunkDelayMicros);
            }

            $chunk = array_slice($entries, $offset, $this->chunkSize);
            $chunkCount++;

            $this->pdo->beginTransaction();
            try {
                foreach ($chunk as $entry) {
                    $this->entryWriter->writeWithinTransaction(new EntryPayload(
                        tenantId: (int) $entry['tenant_id'],
                        modelId: (int) $entry['model_id'],
                        fields: (array) ($entry['fields'] ?? []),
                    ));
                    $entriesWritten++;
                }

                $heartbeat = $this->pdo->prepare(
                    'UPDATE stardust_import_jobs SET heartbeat_at = ? WHERE id = ?'
                );
                $heartbeat->execute([$this->utcNow(), $jobId]);

                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $this->failJob(
                    jobId: $jobId,
                    tenantId: $tenantId,
                    failedReason: 'entry_write_failed',
                    chunkCorrelationId: $chunkCorrelationId,
                    errorMessage: $e->getMessage(),
                );
                return null;
            }
        }

        return ['chunks' => $chunkCount, 'entries_written' => $entriesWritten];
    }

    /**
     * @param array{chunks: int, entries_written: int} $manifest
     */
    private function completeJob(int $jobId, array $manifest, string $chunkCorrelationId): void
    {
        $now = $this->utcNow();
        $manifestJson = json_encode($manifest, JSON_THROW_ON_ERROR);

        $stmt = $this->pdo->prepare(
            'UPDATE stardust_import_jobs'
            . " SET status = 'completed', manifest = ?, completed_at = ?, heartbeat_at = ?"
            . ' WHERE id = ?'
        );
        $stmt->execute([$manifestJson, $now, $now, $jobId]);

        $this->logger->info('import_job complete', [
            'event'           => 'chunk_complete',
            'source'          => 'reconciler',
            'correlation_id'  => $chunkCorrelationId,
            'queue'           => 'import_jobs',
            'job_id'          => $jobId,
            'chunks'          => $manifest['chunks'],
            'entries_written' => $manifest['entries_written'],
        ]);
    }

    private function failJob(
        int $jobId,
        int $tenantId,
        string $failedReason,
        string $chunkCorrelationId,
        string $errorMessage,
    ): void {
        $now = $this->utcNow();

        $stmt = $this->pdo->prepare(
            'UPDATE stardust_import_jobs'
            . " SET status = 'failed', failed_reason = ?, completed_at = ?, heartbeat_at = ?"
            . ' WHERE id = ?'
        );
        $stmt->execute([$failedReason, $now, $now, $jobId]);

        $this->dlqWriter->quarantine(new DlqEntry(
            source: 'bulk_import',
            entryId: null,
            tenantId: $tenantId,
            modelId: 0,
            reason: $failedReason === 'malformed_json' ? 'malformed_json' : 'other',
            errorMessage: substr($errorMessage, 0, 1024),
            chunkCorrelationId: $chunkCorrelationId,
        ));

        $this->logger->warning('import_job chunk failed', [
            'event'           => 'chunk_partial',
            'source'          => 'reconciler',
            'correlation_id'  => $chunkCorrelationId,
            'queue'           => 'import_jobs',
            'job_id'          => $jobId,
            'failed_reason'   => $failedReason,
        ]);
    }

    private function utcNow(): string
    {
        return $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
