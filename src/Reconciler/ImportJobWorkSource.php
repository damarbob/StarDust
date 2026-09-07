<?php

declare(strict_types=1);

namespace StarDust\Reconciler;

use Closure;
use DateTimeZone;
use JsonException;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Exception\ImportJobArtifactException;
use StarDust\Support\UuidV4;
use StarDust\Write\EntryPayload;
use StarDust\Write\EntryWriter;
use StarDust\Write\ImportChunkRecord;
use Throwable;
use PDOException;
use StarDust\Support\RetryableLockFailure;

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
 * Manifest (ADR 0011 §26, shaped by ADR 0040):
 *   - `{chunks, entries_written}` is the resume checkpoint and is read
 *     arithmetically by the abandoned-claim path above.
 *   - `chunk_manifest` is the per-chunk record list §26 requires:
 *     one `committed` record per chunk carrying its entity ID range,
 *     plus at most one terminal `failed` record. `rolled_back` never
 *     appears here — that outcome belongs to {@see \StarDust\Write\BulkIngestor},
 *     which skips a failed chunk and continues, whereas the first
 *     failure on this path is terminal for the job.
 *
 * Completion:
 *   - On success: `status='completed'`, `manifest` populated with
 *     per-chunk counts and records, `completed_at=NOW()`.
 *   - On artifact failure: `status='failed'`,
 *     `failed_reason='malformed_json'`, DLQ row inserted, and the
 *     manifest left NULL — §26 scopes it to jobs that produced a chunk,
 *     and this fails before the first window opens.
 *   - On per-entry failure: the whole chunk rolls back; the job moves
 *     to `failed` with `failed_reason='entry_write_failed'`, a `failed`
 *     record is appended, and a DLQ row is inserted. Partial completion
 *     is not supported — the manifest reports the boundary so an
 *     operator can replay.
 */
final class ImportJobWorkSource implements ReconcilerWorkSource
{
    /**
     * Outcomes of one window transaction. Strings rather than an enum:
     * they never leave this class, and a private enum for four internal
     * branches is ceremony the rest of the package does not use.
     */
    private const WINDOW_COMMITTED = 'committed';
    private const WINDOW_RETRY     = 'retry';
    private const WINDOW_LOCK_WAIT = 'lock_wait';
    private const WINDOW_LEASE_LOST = 'lease_lost';
    private const WINDOW_FAILED    = 'failed';

    /**
     * Normalised to a `Closure` rather than left as `?callable`: the
     * constructor always supplies a default, so the property is never
     * actually null.
     *
     * @var Closure(int): void
     */
    private readonly Closure $sleepFn;

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
        ?callable $sleepFn = null,
        private readonly int $lockRetryBudget = 3,
        private readonly int $lockRetryDelayMicros = 0,
    ) {
        $this->sleepFn = $sleepFn !== null
            ? Closure::fromCallable($sleepFn)
            : static fn (int $micros) => usleep($micros);
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
        // The prior worker's per-chunk records (ADR 0011 §26 / ADR 0040).
        // Carried forward so a re-claim appends to the manifest rather
        // than restarting it — the committed chunks it describes are
        // durable regardless of which worker wrote them.
        $priorRecords = $checkpoint['chunk_manifest'];

        // The submitting call's id, when the job carries one. It rides
        // as a COMPANION field rather than replacing `correlation_id`:
        // these are chunk events, and a chunk genuinely is their
        // operation — the same rule that keeps the Reconciler's other
        // work sources on their own chunk ids. There is no per-job
        // completion event on this path to take the job id directly,
        // which is exactly why the companion is the whole mechanism.
        $jobCorrelationId = $job['correlation_id'] === null
            ? null
            : (string) $job['correlation_id'];

        $this->logger->info('import_job chunk claimed', [
            'event'                => 'chunk_claimed',
            'source'               => 'reconciler',
            'correlation_id'       => $chunkCorrelationId,
            'job_correlation_id'   => $jobCorrelationId,
            'queue'                => 'import_jobs',
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
                jobCorrelationId: $jobCorrelationId,
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
            priorRecords: $priorRecords,
            jobCorrelationId: $jobCorrelationId,
        );

        if ($manifest instanceof TickOutcome) {
            // A window exhausted its lock-retry budget. The job stays
            // claimed and checkpointed; the lease timeout hands it to
            // whichever worker picks it up next.
            return $manifest;
        }

        if ($manifest === null) {
            // Job was failed, or the lease was lost mid-chunk and the
            // re-claimer owns terminal state. Either way, don't complete.
            return TickOutcome::WORK_DONE;
        }

        $this->completeJob(
            jobId: (int) $job['id'],
            manifest: $manifest,
            chunkCorrelationId: $chunkCorrelationId,
            jobCorrelationId: $jobCorrelationId,
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
     * @return array{id: int|string, tenant_id: int|string, artifact_path: string, manifest: string|null, correlation_id: string|null}|null
     */
    private function loadJob(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, artifact_path, manifest, correlation_id'
            . ' FROM stardust_import_jobs WHERE id = ?'
        );
        $stmt->execute([$jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Decodes the manifest into the resume checkpoint plus the
     * per-chunk records accumulated so far. A NULL or malformed
     * manifest yields the zero checkpoint (start from the top) with no
     * records.
     *
     * `chunk_manifest` is absent from any manifest written before ADR
     * 0040 shipped, and from every `{chunks, entries_written}` row an
     * in-flight job carries across the upgrade. Such a job resumes
     * correctly and simply has no records for the chunks its prior
     * worker committed — the counters are what the resume depends on,
     * and they are untouched.
     *
     * @return array{chunks: int, entries_written: int, chunk_manifest: list<array<string, mixed>>}
     */
    private function decodeManifest(mixed $raw): array
    {
        $zero = ['chunks' => 0, 'entries_written' => 0, 'chunk_manifest' => []];

        if (!is_string($raw) || $raw === '') {
            return $zero;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $zero;
        }
        return [
            'chunks'          => isset($decoded['chunks']) ? (int) $decoded['chunks'] : 0,
            'entries_written' => isset($decoded['entries_written']) ? (int) $decoded['entries_written'] : 0,
            'chunk_manifest'  => $this->decodeChunkRecords($decoded['chunk_manifest'] ?? null),
        ];
    }

    /**
     * Normalises the stored `chunk_manifest` array. Every element is
     * re-encoded verbatim into the next checkpoint, so this only has to
     * guarantee it is a list of arrays — anything else is dropped
     * rather than propagated into a manifest a consumer will read.
     *
     * @return list<array<string, mixed>>
     */
    private function decodeChunkRecords(mixed $raw): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            return [];
        }

        $records = [];
        foreach ($raw as $record) {
            if (is_array($record)) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * `fields` is optional because this shape is whatever `json_decode` returned
     * from a file on disk, not something the type system verified. A malformed
     * artifact is an expected failure mode here — it has its own
     * `failed_reason='malformed_json'` path — so the consumer's `?? []` at the
     * write call is a real guard, not dead code.
     *
     * The declared shape is only what the checks below actually verify —
     * that the top-level keys exist and `entries` is a list. Element
     * contents stay `mixed` deliberately: validating every entry would
     * mean a second full pass over a file that can hold tens of thousands
     * of them, and the write path already coerces per field.
     *
     * @return array{tenant_id: mixed, entries: list<mixed>}
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
            || !array_key_exists('entries', $decoded) || !is_array($decoded['entries'])
            || !array_is_list($decoded['entries'])) {
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
     * Three outcomes, because a lock failure is neither success nor a
     * reason to fail the job:
     *
     *   - a manifest ⇒ every window committed; the caller completes.
     *   - `null` ⇒ the job was failed, or the lease was lost; the caller
     *     must NOT call `completeJob()`.
     *   - `TickOutcome::LOCK_WAIT` ⇒ a window lost a lock
     *     `lockRetryBudget` times running. Windows already committed are
     *     checkpointed in the manifest, so nothing is lost and nothing is
     *     re-applied; the job stays `processing` under this worker's
     *     identity until `reconcilerImportLeaseTimeoutSeconds` lapses and
     *     the abandoned-claim path resumes it from
     *     `manifest.entries_written`.
     *
     * @param array{tenant_id: int, entries: list<array{tenant_id: int, model_id: int, fields?: array<string, mixed>}>} $payload
     * @param list<array<string, mixed>> $priorRecords
     * @return array{chunks: int, entries_written: int, chunk_manifest: list<array<string, mixed>>}|TickOutcome|null
     */
    private function processEntries(
        int $jobId,
        int $tenantId,
        array $payload,
        string $chunkCorrelationId,
        string $workerIdentity,
        int $resumeOffset,
        int $priorChunks,
        array $priorRecords,
        ?string $jobCorrelationId = null,
    ): array|TickOutcome|null {
        $entries = $payload['entries'];
        $totalEntries = count($entries);
        // Resume from the committed boundary. entries_written counts
        // entries, not chunks, so this is exact even if chunkSize changed
        // since the prior worker (entries are position-indexed).
        $entriesWritten = $resumeOffset;
        $chunkCount = $priorChunks;
        $records = $priorRecords;

        for ($offset = $resumeOffset; $offset < $totalEntries; $offset += $this->chunkSize) {
            // Apply inter-chunk delay BEFORE every chunk except the
            // first — matches BulkIngestor's "between chunks, never
            // before the first or after the last" semantics.
            if ($offset > $resumeOffset && $this->interChunkDelayMicros > 0) {
                ($this->sleepFn)($this->interChunkDelayMicros);
            }

            $chunk = array_slice($entries, $offset, $this->chunkSize);
            $chunkCount++;

            // Per-window lock retry. It cannot wrap the whole tick the
            // way the other work sources do: the claim is an
            // autocommitted UPDATE that ran before any transaction, so
            // re-running tickOne() would re-claim the job and re-read the
            // artifact.
            //
            // `$windowStart` is what makes a retry safe — the counter is
            // incremented per entry inside the transaction, so a rolled
            // back attempt must rewind it before the next one.
            //
            // `$records` needs no such rewind: writeWindow() appends to a
            // local copy and only assigns it back *after* commit()
            // returns, so a rolled back attempt cannot have grown it.
            $windowStart = $entriesWritten;

            for ($attempt = 1; ; $attempt++) {
                $entriesWritten = $windowStart;
                $windowOutcome = $this->writeWindow(
                    chunk: $chunk,
                    jobId: $jobId,
                    tenantId: $tenantId,
                    chunkCorrelationId: $chunkCorrelationId,
                    workerIdentity: $workerIdentity,
                    chunkCount: $chunkCount,
                    entriesWritten: $entriesWritten,
                    attempt: $attempt,
                    records: $records,
                    jobCorrelationId: $jobCorrelationId,
                );

                if ($windowOutcome !== self::WINDOW_RETRY) {
                    break;
                }

                ($this->sleepFn)($this->lockRetryDelayMicros);
            }

            if ($windowOutcome !== self::WINDOW_COMMITTED) {
                return $windowOutcome === self::WINDOW_LOCK_WAIT ? TickOutcome::LOCK_WAIT : null;
            }
        }

        return [
            'chunks'         => $chunkCount,
            'entries_written' => $entriesWritten,
            'chunk_manifest' => $records,
        ];
    }

    /**
     * @param array{chunks: int, entries_written: int, chunk_manifest: list<array<string, mixed>>} $manifest
     */
    private function completeJob(
        int $jobId,
        array $manifest,
        string $chunkCorrelationId,
        ?string $jobCorrelationId = null,
    ): void {
        $now = $this->utcNow();
        $manifestJson = json_encode($manifest, JSON_THROW_ON_ERROR);

        $stmt = $this->pdo->prepare(
            'UPDATE stardust_import_jobs'
            . " SET status = 'completed', manifest = ?, completed_at = ?, heartbeat_at = ?"
            . ' WHERE id = ?'
        );
        $stmt->execute([$manifestJson, $now, $now, $jobId]);

        $this->logger->info('import_job complete', [
            'event'              => 'chunk_complete',
            'source'             => 'reconciler',
            'correlation_id'     => $chunkCorrelationId,
            'job_correlation_id' => $jobCorrelationId,
            'queue'              => 'import_jobs',
            'job_id'          => $jobId,
            'chunks'          => $manifest['chunks'],
            'entries_written' => $manifest['entries_written'],
            // A context field on an existing event, not a new event
            // name — ADR 0020's vocabulary is unchanged. It is below
            // `chunks` when a job resumed across the ADR 0040 upgrade
            // and its prior worker left no records.
            'chunk_records'   => count($manifest['chunk_manifest']),
        ]);
    }

    /**
     * One window's transaction: write the chunk's entries, checkpoint
     * the manifest and heartbeat together, commit.
     *
     * Split out of {@see self::processEntries()} so a lock failure can be
     * retried without re-claiming the job. Returns one of the `WINDOW_*`
     * constants rather than a bool, because the caller has to
     * distinguish "committed" from "retry me", and from the two terminal
     * decisions already made in here.
     *
     * `$entriesWritten` is by reference and is incremented per entry
     * *inside* the transaction, so a caller retrying this method must
     * rewind it to the window's starting value first.
     *
     * `$records` is also by reference but needs no such rewind: the
     * appended list is built locally and assigned back only *after*
     * `commit()` returns, so a rolled back attempt leaves the caller's
     * copy untouched.
     *
     * @param list<array{tenant_id: int, model_id: int, fields?: array<string, mixed>}> $chunk
     * @param list<array<string, mixed>> $records
     */
    private function writeWindow(
        array $chunk,
        int $jobId,
        int $tenantId,
        string $chunkCorrelationId,
        string $workerIdentity,
        int $chunkCount,
        int &$entriesWritten,
        int $attempt,
        array &$records,
        ?string $jobCorrelationId = null,
    ): string {
        $this->pdo->beginTransaction();
        try {
            $entryIdFirst = null;
            $entryIdLast  = null;

            foreach ($chunk as $entry) {
                $result = $this->entryWriter->writeWithinTransaction(new EntryPayload(
                    tenantId: (int) $entry['tenant_id'],
                    modelId: (int) $entry['model_id'],
                    fields: (array) ($entry['fields'] ?? []),
                ));
                // ADR 0011 §26's "entity ID range". The write already
                // returns the id; this only stops discarding it, so the
                // record costs no extra query.
                $entryIdFirst ??= $result->entryId;
                $entryIdLast    = $result->entryId;
                $entriesWritten++;
            }

            // Append to a LOCAL copy — see the docblock. `$records` is
            // only advanced past the commit below.
            $appendedRecords = [...$records, [
                'index'          => $chunkCount,
                'size'           => count($chunk),
                'outcome'        => ImportChunkRecord::OUTCOME_COMMITTED,
                'entry_id_first' => $entryIdFirst,
                'entry_id_last'  => $entryIdLast,
            ]];

            // Checkpoint the running manifest + heartbeat in the same
            // transaction as the chunk's writes. The
            // `worker_identity = self` predicate is the lease-loss
            // detector: 0 rows matched ⇒ a re-claimer overwrote our
            // identity. entries_written strictly increases, so a matched
            // row is always *changed* — rowCount()===0 can only mean the
            // identity no longer matches, never a no-op update. (The
            // appended record makes the row differ too, but the counter
            // is what the guarantee rests on: it is monotonic by
            // construction, while a record's contents are not.)
            $manifest = json_encode(
                [
                    'chunks'          => $chunkCount,
                    'entries_written' => $entriesWritten,
                    'chunk_manifest'  => $appendedRecords,
                ],
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

                return self::WINDOW_LEASE_LOST;
            }

            $this->pdo->commit();

            // Past the commit, so the record now describes durable rows.
            $records = $appendedRecords;

            return self::WINDOW_COMMITTED;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            // A retryable lock failure must never reach failJob().
            // `entry_write_failed` is terminal — the job is marked
            // failed, a DLQ row is written, and nothing retries it — so
            // transient contention with a Liberator sweep would
            // permanently destroy a consumer's import over a condition
            // that clears on its own.
            if ($e instanceof PDOException && RetryableLockFailure::matches($e)) {
                if ($attempt < $this->lockRetryBudget) {
                    $this->logger->warning('import_job lock retry', [
                        'event'          => 'deadlock_retry',
                        'source'         => 'reconciler',
                        'correlation_id' => $chunkCorrelationId,
                        'queue'          => 'import_jobs',
                        'job_id'         => $jobId,
                        'attempt'        => $attempt,
                        'errno'          => RetryableLockFailure::errnoOf($e),
                    ]);

                    return self::WINDOW_RETRY;
                }

                $this->logger->warning('import_job lock wait', [
                    'event'          => 'lock_wait',
                    'source'         => 'reconciler',
                    'correlation_id' => $chunkCorrelationId,
                    'queue'          => 'import_jobs',
                    'job_id'         => $jobId,
                    'attempts'       => $attempt,
                    'errno'          => RetryableLockFailure::errnoOf($e),
                ]);

                return self::WINDOW_LOCK_WAIT;
            }

            $this->failJob(
                jobId: $jobId,
                tenantId: $tenantId,
                failedReason: 'entry_write_failed',
                chunkCorrelationId: $chunkCorrelationId,
                errorMessage: $e->getMessage(),
                jobCorrelationId: $jobCorrelationId,
                // The terminal record. Its id range is null because the
                // window rolled back above, so the chunk owns no
                // `entry_data` rows and the ids it would have taken were
                // never durable.
                failedRecord: [
                    'index'          => $chunkCount,
                    'size'           => count($chunk),
                    'outcome'        => ImportChunkRecord::OUTCOME_FAILED,
                    'entry_id_first' => null,
                    'entry_id_last'  => null,
                    'failure_reason' => 'entry_write_failed',
                ],
            );

            return self::WINDOW_FAILED;
        }
    }

    /**
     * `$failedRecord` is the terminal chunk record to append, or null
     * when the job failed before producing any chunk at all.
     *
     * **The `malformed_json` path passes null, and must.** ADR 0011 §26
     * scopes the manifest to "jobs that have produced any chunks", and
     * an artifact failure trips before the first window opens — so the
     * column stays NULL and `getImportJob()` keeps reporting `null`
     * rather than `0`, a distinction `ImportJob` documents as
     * load-bearing.
     *
     * The append never touches `chunks` or `entries_written`: they are
     * the durable boundary a consumer resumes from, and a failure must
     * not move it.
     *
     * @param array<string, mixed>|null $failedRecord
     */
    private function failJob(
        int $jobId,
        int $tenantId,
        string $failedReason,
        string $chunkCorrelationId,
        string $errorMessage,
        ?array $failedRecord = null,
        ?string $jobCorrelationId = null,
    ): void {
        $now = $this->utcNow();

        if ($failedRecord === null) {
            $stmt = $this->pdo->prepare(
                'UPDATE stardust_import_jobs'
                . " SET status = 'failed', failed_reason = ?, completed_at = ?, heartbeat_at = ?"
                . ' WHERE id = ?'
            );
            $stmt->execute([$failedReason, $now, $now, $jobId]);
        } else {
            // Re-read rather than carry the manifest down: the window
            // that just rolled back never advanced the caller's copy,
            // and the committed counters live only in the row.
            $job = $this->loadJob($jobId);
            $checkpoint = $this->decodeManifest($job['manifest'] ?? null);
            $records = [...$checkpoint['chunk_manifest'], $failedRecord];

            // A manifest is only ever written after a chunk commits, so
            // `chunks >= 1` iff something is durable. When the FIRST
            // chunk is the one that failed, the counters are omitted
            // entirely rather than written as 0 — `getImportJob()` reads
            // an absent key as `null`, and `null` ("nothing committed")
            // versus `0` ("ran, wrote nothing") is the distinction
            // `ImportJob` documents as load-bearing.
            $payload = $checkpoint['chunks'] > 0
                ? [
                    'chunks'          => $checkpoint['chunks'],
                    'entries_written' => $checkpoint['entries_written'],
                    'chunk_manifest'  => $records,
                ]
                : ['chunk_manifest' => $records];

            $manifest = json_encode($payload, JSON_THROW_ON_ERROR);

            $stmt = $this->pdo->prepare(
                'UPDATE stardust_import_jobs'
                . " SET status = 'failed', failed_reason = ?, manifest = ?,"
                . '     completed_at = ?, heartbeat_at = ?'
                . ' WHERE id = ?'
            );
            $stmt->execute([$failedReason, $manifest, $now, $now, $jobId]);
        }

        $this->dlqWriter->quarantine(new DlqEntry(
            source: 'bulk_import',
            entryId: null,
            tenantId: $tenantId,
            modelId: 0,
            reason: $failedReason === 'malformed_json' ? 'malformed_json' : 'other',
            errorMessage: substr($errorMessage, 0, 1024),
            chunkCorrelationId: $chunkCorrelationId,
            originCorrelationId: $jobCorrelationId,
        ));

        $this->logger->warning('import_job chunk failed', [
            'event'              => 'chunk_partial',
            'source'             => 'reconciler',
            'correlation_id'     => $chunkCorrelationId,
            'job_correlation_id' => $jobCorrelationId,
            'queue'              => 'import_jobs',
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
