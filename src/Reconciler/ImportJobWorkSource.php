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
 * Claim protocol (dual-path, mirroring {@see \StarDust\Chronicler\ExportJobClaimer}):
 *   - Pending path: `UPDATE … SET status='processing', worker_identity=?,
 *     claimed_at=NOW(), heartbeat_at=NOW() WHERE status='pending'
 *     ORDER BY id LIMIT 1`, then `SELECT … WHERE worker_identity = ?`
 *     retrieves the row this worker won.
 *   - Abandoned path (only when no pending row exists): re-claims a
 *     `processing` job whose `heartbeat_at` has lapsed past the lease
 *     timeout via `SELECT … FOR UPDATE SKIP LOCKED` + `UPDATE … SET
 *     worker_identity=?, heartbeat_at=?` — `claimed_at` is PRESERVED so
 *     operators see the original claim time. `worker_identity` is
 *     `host:pid:uuid` so the prior worker self-aborts on the mismatch.
 *
 * Read & process:
 *   - Reads `artifact_path` via `file_get_contents()` and decodes the
 *     single-document JSON per ADR 0028 (`tenant_id` + `entries[]`).
 *   - Resumes from the `manifest` checkpoint: `entries_written` is the
 *     count of already-committed entries, so a re-claimed abandoned job
 *     restarts at `offset = manifest.entries_written` and never
 *     re-processes a committed chunk (no duplicate `entry_data` rows).
 *   - Iterates entries in `chunkSize` windows; each window opens its
 *     own transaction, calls
 *     {@see EntryWriter::writeWithinTransaction()} for every entry, and
 *     writes the running `manifest` + `heartbeat_at` inside the same
 *     transaction. That UPDATE carries `WHERE … AND worker_identity = self`:
 *     a `rowCount() === 0` means a re-claimer overwrote our identity, so
 *     the worker rolls back the chunk, emits `lease_lost`, and stops
 *     WITHOUT marking the row failed — the re-claimer owns terminal state.
 *
 * Completion:
 *   - On success: `status='completed'`, `manifest` populated with
 *     per-chunk counts, `completed_at=NOW()`.
 *   - On artifact failure: `status='failed'`,
 *     `failed_reason='malformed_json'`, DLQ row inserted.
 *   - On per-entry failure: the whole chunk rolls back; the job moves
 *     to `failed` with `failed_reason='entry_write_failed'` and a DLQ
 *     row is inserted. Partial completion is not supported — the
 *     manifest reports the boundary so an operator can replay.
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
        private readonly int $leaseTimeoutSeconds = 30,
        private $sleepFn = null,
    ) {
        $this->sleepFn = $sleepFn ?? static fn (int $micros) => usleep($micros);
    }

    public function tickOne(string $chunkCorrelationId): TickOutcome
    {
        $workerIdentity = $this->workerIdentity();
        $claim = $this->claim($workerIdentity);
        if ($claim === null) {
            return TickOutcome::IDLE;
        }
        [$jobId, $claimKind] = $claim;

        $job = $this->loadJob($jobId);
        if ($job === null) {
            // Vanishingly unlikely — another worker raced us to the
            // same row. Move on.
            return TickOutcome::IDLE;
        }

        // Resume from the manifest checkpoint. A pending claim has a NULL
        // manifest (offset 0); an abandoned re-claim resumes from the
        // committed boundary so no chunk is processed twice.
        $checkpoint = $this->decodeManifest($job['manifest'] ?? null);
        $resumeOffset = $checkpoint['entries_written'];
        $priorChunks = $checkpoint['chunks'];

        $this->logger->info('import_job chunk claimed', [
            'event'          => 'chunk_claimed',
            'source'         => 'reconciler',
            'correlation_id' => $chunkCorrelationId,
            'queue'          => 'import_jobs',
            'job_id'         => (int) $job['id'],
            'tenant_id'      => (int) $job['tenant_id'],
            'claim_kind'     => $claimKind,
            'resume_offset'  => $resumeOffset,
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
            workerIdentity: $workerIdentity,
            resumeOffset: $resumeOffset,
            priorChunks: $priorChunks,
        );

        if ($manifest === null) {
            // Job was failed, or the lease was lost mid-chunk and the
            // re-claimer owns terminal state. Either way, don't complete.
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

    /**
     * Claims one job — a fresh `pending` row, or (failing that) an
     * abandoned `processing` row whose lease lapsed.
     *
     * @return array{0: int, 1: 'pending'|'abandoned'}|null
     */
    private function claim(string $workerIdentity): ?array
    {
        $pending = $this->claimPending($workerIdentity);
        if ($pending !== null) {
            return [$pending, 'pending'];
        }
        $abandoned = $this->claimAbandoned($workerIdentity);
        if ($abandoned !== null) {
            return [$abandoned, 'abandoned'];
        }
        return null;
    }

    private function claimPending(string $workerIdentity): ?int
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
     * Re-claims one abandoned `processing` job whose `heartbeat_at`
     * lapsed past the lease timeout. `claimed_at` is preserved; only
     * `worker_identity` + `heartbeat_at` are overwritten, exactly as
     * {@see \StarDust\Chronicler\ExportJobClaimer::claimAbandoned()}.
     */
    private function claimAbandoned(string $workerIdentity): ?int
    {
        $now = $this->utcNow();

        $this->pdo->beginTransaction();
        try {
            // UTC_TIMESTAMP() (not NOW()) because heartbeat_at is stored
            // in UTC; a session local-time comparison could falsely flag
            // fresh leases as abandoned.
            $select = $this->pdo->prepare(
                'SELECT id FROM stardust_import_jobs'
                . " WHERE status = 'processing'"
                . '   AND heartbeat_at IS NOT NULL'
                . '   AND heartbeat_at < (UTC_TIMESTAMP() - INTERVAL ' . $this->leaseTimeoutSeconds . ' SECOND)'
                . ' ORDER BY heartbeat_at ASC'
                . ' LIMIT 1 FOR UPDATE SKIP LOCKED'
            );
            $select->execute();
            $id = $select->fetchColumn();
            if ($id === false) {
                $this->pdo->commit();
                return null;
            }

            $jobId = (int) $id;
            $update = $this->pdo->prepare(
                'UPDATE stardust_import_jobs'
                . ' SET worker_identity = ?, heartbeat_at = ?'
                . ' WHERE id = ?'
            );
            $update->execute([$workerIdentity, $now, $jobId]);
            $this->pdo->commit();

            return $jobId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array{id: int|string, tenant_id: int|string, artifact_path: string, manifest: string|null}|null
     */
    private function loadJob(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, artifact_path, manifest FROM stardust_import_jobs WHERE id = ?'
        );
        $stmt->execute([$jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Decodes the `{chunks, entries_written}` checkpoint manifest. A
     * NULL or malformed manifest yields the zero checkpoint (start from
     * the top).
     *
     * @return array{chunks: int, entries_written: int}
     */
    private function decodeManifest(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return ['chunks' => 0, 'entries_written' => 0];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['chunks' => 0, 'entries_written' => 0];
        }
        return [
            'chunks'          => isset($decoded['chunks']) ? (int) $decoded['chunks'] : 0,
            'entries_written' => isset($decoded['entries_written']) ? (int) $decoded['entries_written'] : 0,
        ];
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
     * @return array{chunks: int, entries_written: int}|null `null` ⇒ job was failed OR the lease was lost; caller must NOT call completeJob
     */
    private function processEntries(
        int $jobId,
        int $tenantId,
        array $payload,
        string $chunkCorrelationId,
        string $workerIdentity,
        int $resumeOffset,
        int $priorChunks,
    ): ?array {
        $entries = $payload['entries'];
        $totalEntries = count($entries);
        // Resume from the committed boundary. entries_written counts
        // entries, not chunks, so this is exact even if chunkSize changed
        // since the prior worker (entries are position-indexed).
        $entriesWritten = $resumeOffset;
        $chunkCount = $priorChunks;

        for ($offset = $resumeOffset; $offset < $totalEntries; $offset += $this->chunkSize) {
            // Apply inter-chunk delay BEFORE every chunk except the
            // first — matches BulkIngestor's "between chunks, never
            // before the first or after the last" semantics.
            if ($offset > $resumeOffset && $this->interChunkDelayMicros > 0) {
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

                // Checkpoint the running manifest + heartbeat in the same
                // transaction as the chunk's writes. The
                // `worker_identity = self` predicate is the lease-loss
                // detector: 0 rows matched ⇒ a re-claimer overwrote our
                // identity. entries_written strictly increases, so a
                // matched row is always *changed* — rowCount()===0 can
                // only mean the identity no longer matches, never a
                // no-op update.
                $manifest = json_encode(
                    ['chunks' => $chunkCount, 'entries_written' => $entriesWritten],
                    JSON_THROW_ON_ERROR,
                );
                $checkpoint = $this->pdo->prepare(
                    'UPDATE stardust_import_jobs'
                    . ' SET heartbeat_at = ?, manifest = ?'
                    . ' WHERE id = ? AND worker_identity = ?'
                );
                $checkpoint->execute([$this->utcNow(), $manifest, $jobId, $workerIdentity]);
                if ($checkpoint->rowCount() === 0) {
                    // Lease lost — roll back this chunk's writes so the
                    // re-claimer's copy is authoritative, and stop WITHOUT
                    // failing the row (the re-claimer owns terminal state,
                    // per schema_reference §5.5 / ADR 0025).
                    $this->pdo->rollBack();
                    $this->logger->warning('import_job lease lost', [
                        'event'          => 'lease_lost',
                        'source'         => 'reconciler',
                        'correlation_id' => $chunkCorrelationId,
                        'queue'          => 'import_jobs',
                        'job_id'         => $jobId,
                        'tenant_id'      => $tenantId,
                    ]);
                    return null;
                }

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
