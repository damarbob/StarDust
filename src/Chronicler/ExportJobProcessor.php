<?php

declare(strict_types=1);

namespace StarDust\Chronicler;

use DateTimeZone;
use PDO;
use PDOException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Daemon\YieldSignal;
use StarDust\Exception\ChroniclerArtifactDiskFullException;
use StarDust\Exception\ChroniclerRowEncodingException;
use Throwable;

/**
 * Per-job chunk-commit loop. Owns ADR 0025's commitments end-to-end,
 * as corrected by ADR 0047's resume-anchor invariant:
 *
 *   - Cursor-paginated probe via {@see EntryDataPager} (LIMIT N+1 shape).
 *   - Per-row encode via {@see ArtifactStream} (CSV or JSON).
 *   - `open()` attempts a verified resume when the claim carried an
 *     anchor (ADR 0047); `resumedFromByte()` — not the claim kind —
 *     decides whether `last_cursor` is trusted for the first probe.
 *   - Atomic chunk-commit transaction with the lease-loss detector:
 *     `UPDATE … WHERE id = ? AND worker_identity = ?`; `rowCount() == 0`
 *     ⇒ re-claimer overwrote our row ⇒ emit `lease_lost`, close (but do
 *     NOT delete) the artifact — the re-claimer may already be resuming
 *     from it — return {@see JobOutcome::LeaseLost}.
 *   - `artifact_path` / `artifact_bytes` are written on every commit,
 *     after an explicit `flush()`, so the row is always a usable
 *     resume anchor for the next abandoned re-claim.
 *   - Deadlock retry budget (3 by default) per chunk. On exhaustion:
 *     emit `chunk_skipped`, advance cursor by `pageSize`, charge
 *     `skip_count += pageSize`, continue.
 *   - Per-row encoding failure → `row_skipped` + `skip_count++`.
 *   - `skip_count > skip_count_cap` → `failed:excessive_skips`.
 *   - Artifact bytes > size cap → `failed:artifact_size_exceeded`,
 *     emit `artifact_oversized` (NOT `job_failed`).
 *   - DB disconnect mid-pagination: fixed backoff schedule (default
 *     `[1, 4, 16]` s); on exhaustion → `failed:query_failure`,
 *     `last_cursor` preserved.
 *   - `ENOSPC` on append → `failed:disk_full`.
 *   - Every terminal-failure path deletes the artifact AND NULLs
 *     `artifact_path` / `artifact_bytes` in the same transaction — the
 *     anchor dies with the file (ADR 0047).
 *   - Cooperative yield (ADR 0050): after a committed **non-final**
 *     chunk, an optional {@see YieldSignal} is checked. A non-null
 *     cause folds into the SAME commit transaction — `status =
 *     'pending', worker_identity = NULL`, cursor/skip/anchor columns
 *     written exactly as any other chunk commit — and `process()`
 *     returns {@see JobOutcome::Yielded} rather than looping. Never
 *     checked before the first commit, so a yield always carries at
 *     least one chunk of durable progress; never checked on the final
 *     chunk, so the last chunk always completes the job. The anchor is
 *     captured (`$stream->path()` / `bytesWritten()`) BEFORE `close()`
 *     — {@see JsonArtifactStream::close()} writes the trailing `]`
 *     through the same `writeRaw()` that increments `bytesWritten()`,
 *     so a post-close capture would anchor the terminator byte and the
 *     next resume's `ftruncate` would keep it, corrupting the
 *     artifact. `close()` also runs before the yield commits, so the
 *     file's `flock` is released before the row becomes claimable —
 *     the resumer never sees `restart_cause: locked`, the inverse of
 *     an abandoned claim where a hung process may still hold it.
 *
 * The `skip_count` is persisted in every chunk-commit transaction so a
 * re-claimer continues charging from the previous worker's count
 * rather than starting fresh — otherwise a dying worker could let a
 * re-claimer charge another full cap before tripping
 * `excessive_skips`. On a fallback restart-from-zero (any anchor
 * verification failure), the same rows can be re-probed and
 * re-charged; this is accepted rather than special-cased, since it
 * fails closed (ADR 0047 Commitment 5).
 */
final class ExportJobProcessor
{
    /** @var callable(int):void */
    private $sleepFn;

    /**
     * `$pdo` and `$pager` are deliberately NOT readonly: a mid-pagination
     * reconnect ({@see self::reconnectWithBackoff()}) swaps both for a
     * fresh connection per ADR 0025 Commitment 6. Every other dependency
     * is immutable.
     *
     * @param list<int> $dbDisconnectBackoffSeconds  ADR 0025-fixed [1, 4, 16];
     *                                                injectable for tests.
     * @param callable(int):void|null $sleepFn       Injected for tests; defaults to `usleep`.
     * @param PdoConnector|null $connector           Reconnect seam; when null the
     *                                                processor cannot recover a dropped
     *                                                connection and degrades to
     *                                                `failed:query_failure`.
     */
    public function __construct(
        private PDO $pdo,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private EntryDataPager $pager,
        private readonly HeaderResolver $headerResolver,
        private readonly ArtifactStreamFactory $streamFactory,
        private readonly int $pageSize,
        private readonly int $interChunkDelayMicros,
        private readonly int $deadlockRetryBudget,
        private readonly int $skipCountCap,
        private readonly int $artifactSizeCapBytes,
        private readonly array $dbDisconnectBackoffSeconds,
        ?callable $sleepFn = null,
        private readonly ?PdoConnector $connector = null,
    ) {
        $this->sleepFn = $sleepFn ?? static fn (int $micros) => usleep($micros);
    }

    public function process(ClaimedJob $job, string $correlationId, ?YieldSignal $yield = null): JobOutcome
    {
        $startTime = microtime(true);
        $header  = $this->headerResolver->resolve($job->tenantId, $job->modelId);
        $aliases = $this->headerResolver->resolveAliases($job->tenantId, $job->modelId);
        $stream  = $this->streamFactory->from($job, $header, $aliases);

        // open() may write the format prelude (CSV header / JSON `[`),
        // or — with a resume anchor — attempt to verify and re-open the
        // prior attempt's artifact in place. Either way it can trip
        // ENOSPC; treat header-write disk-full identically to per-row
        // disk-full per ADR 0025. A resumed cursor is only trustworthy
        // once the stream itself confirms it adopted the anchor (ADR
        // 0047) — resumedFromByte() > 0 — not from the claim kind or
        // the stored last_cursor alone.
        try {
            $stream->open();
        } catch (ChroniclerArtifactDiskFullException) {
            $cursor = $job->lastCursor ?? 0;
            return $this->failDiskFull($job, $stream, $correlationId, $cursor, $job->skipCount, $startTime);
        }

        $cursor = $stream->resumedFromByte() > 0 ? ($job->lastCursor ?? 0) : 0;

        $this->logger->info('chronicler artifact resumed', [
            'event'              => 'artifact_resumed',
            'source'             => 'chronicler',
            'correlation_id'     => $correlationId,
            'tenant_id'          => $job->tenantId,
            'job_id'             => $job->id,
            'worker_identity'    => $job->workerIdentity,
            'resumed_from_byte'  => $stream->resumedFromByte(),
            'last_cursor'        => $cursor,
            'restart_cause'      => $stream->restartCause(),
        ]);

        $skipCount       = $job->skipCount;
        $rowsTotal       = 0;
        $bytesBaseline   = $stream->bytesWritten();

        while (true) {
            $chunkStart = microtime(true);
            // `$fetched` rather than a nullable `$rows`: every non-success
            // path below returns, throws, or continues, so `$rows` is always
            // populated by the time the loop exits — but that is invisible to
            // static analysis through the nested try/catch, and a nullable
            // would push a permanently-false null check downstream.
            $rows = [];
            $fetched = false;
            $retry = 0;

            // === Bounded probe with deadlock + disconnect handling ===
            while (! $fetched) {
                try {
                    $rows = $this->pager->fetchChunk($job->tenantId, $job->modelId, $cursor, $this->pageSize);
                    $fetched = true;
                } catch (PDOException $e) {
                    if ($this->isDeadlock($e)) {
                        $retry++;
                        $this->logger->warning('chronicler deadlock retry', [
                            'event'           => 'deadlock_retry',
                            'source'          => 'chronicler',
                            'correlation_id'  => $correlationId,
                            'tenant_id'       => $job->tenantId,
                            'job_id'          => $job->id,
                            'worker_identity' => $job->workerIdentity,
                            'retry_count'     => $retry,
                            'last_cursor'     => $cursor,
                        ]);

                        if ($retry >= $this->deadlockRetryBudget) {
                            $startCursor = $cursor;
                            $cursor += $this->pageSize;
                            $skipCount += $this->pageSize;
                            $this->logger->warning('chronicler chunk skipped', [
                                'event'           => 'chunk_skipped',
                                'source'          => 'chronicler',
                                'correlation_id'  => $correlationId,
                                'tenant_id'       => $job->tenantId,
                                'job_id'          => $job->id,
                                'worker_identity' => $job->workerIdentity,
                                'start_cursor'    => $startCursor,
                                'end_cursor'      => $cursor,
                                'cause'           => 'deadlock_budget_exhausted',
                            ]);
                            if ($skipCount > $this->skipCountCap) {
                                return $this->failExcessiveSkips(
                                    $job, $stream, $correlationId, $cursor, $skipCount, $startTime,
                                );
                            }
                            ($this->sleepFn)($this->interChunkDelayMicros);
                            $retry = 0;
                            continue 2; // next outer loop iteration: probe past skipped range
                        }
                        ($this->sleepFn)($this->interChunkDelayMicros);
                        continue;
                    }
                    if ($this->isDisconnect($e)) {
                        if ($this->reconnectWithBackoff($job, $correlationId)) {
                            continue;
                        }
                        return $this->failQueryFailure($job, $stream, $correlationId, $cursor, $skipCount, $startTime);
                    }
                    // Unknown PDO failure — fail loudly.
                    $stream->delete();
                    throw $e;
                }
            }

            // pageSize+1 contract: a trailing row means more pages remain.
            $isFinal = count($rows) <= $this->pageSize;
            $rows = array_slice($rows, 0, $this->pageSize);

            $rowsStreamed = 0;
            $newCursor = $cursor;

            foreach ($rows as $row) {
                try {
                    $stream->appendRow($row);
                } catch (ChroniclerRowEncodingException $ex) {
                    $skipCount++;
                    $this->logger->warning('chronicler row skipped', [
                        'event'           => 'row_skipped',
                        'source'          => 'chronicler',
                        'correlation_id'  => $correlationId,
                        'tenant_id'       => $job->tenantId,
                        'job_id'          => $job->id,
                        'worker_identity' => $job->workerIdentity,
                        'entry_id'        => $row->id,
                        'reason'          => $ex->reason,
                    ]);
                    if ($skipCount > $this->skipCountCap) {
                        return $this->failExcessiveSkips(
                            $job, $stream, $correlationId, $newCursor, $skipCount, $startTime,
                        );
                    }
                    // Skipped rows still advance the cursor so we don't
                    // re-probe them after a chunk retry.
                    $newCursor = $row->id;
                    continue;
                } catch (ChroniclerArtifactDiskFullException) {
                    return $this->failDiskFull($job, $stream, $correlationId, $newCursor, $skipCount, $startTime);
                }

                if ($stream->bytesWritten() > $this->artifactSizeCapBytes) {
                    return $this->failArtifactOversized(
                        $job, $stream, $correlationId, $stream->bytesWritten(),
                    );
                }
                $rowsStreamed++;
                $rowsTotal++;
                $newCursor = $row->id;
            }

            // === Atomic chunk commit + lease-loss detector ===
            // Flush before the DB write, not after: the row must never
            // promise bytes the filesystem has not actually taken, or a
            // future re-claim's verification/ftruncate would work
            // against bytes that only ever existed in a PHP stream
            // buffer (ADR 0047).
            $stream->flush();

            // ADR 0050: never offer a yield on the final chunk (the
            // last chunk always completes the job), and never before
            // this point (a yield always carries at least one chunk of
            // committed progress).
            $yieldCause = (!$isFinal && $yield !== null) ? $yield->yieldCause() : null;

            // Capture the anchor BEFORE any close() below.
            // JsonArtifactStream::close() writes the trailing ']'
            // through writeRaw(), which increments bytesWritten() — a
            // post-close capture would anchor the terminator byte, and
            // the next resume's ftruncate would keep it and append the
            // next row after it, corrupting the artifact.
            $artifactPath  = $stream->path();
            $artifactBytes = $stream->bytesWritten();

            if ($yieldCause !== null) {
                // Release the lock and handle before the row becomes
                // claimable, so a resumer never sees restart_cause:
                // 'locked' — the inverse of an abandoned claim, where a
                // hung process may still hold it.
                try {
                    $stream->close();
                } catch (ChroniclerArtifactDiskFullException) {
                    // The trailing terminator didn't fit. The anchor
                    // already captured above describes bytes the
                    // filesystem genuinely took, so the yield still
                    // commits cleanly; the next resume's ftruncate
                    // trims past them and the following appendRow()
                    // will trip disk-full properly.
                }
            }

            $outcome = $this->commitChunk(
                jobId: $job->id,
                workerIdentity: $job->workerIdentity,
                newCursor: $newCursor,
                rowsStreamed: $rowsStreamed,
                skipCount: $skipCount,
                isFinal: $isFinal,
                artifactPath: $artifactPath,
                artifactBytes: $artifactBytes,
                yield: $yieldCause !== null,
            );

            if ($outcome->leaseLost) {
                $this->logger->warning('chronicler lease lost', [
                    'event'           => 'lease_lost',
                    'source'          => 'chronicler',
                    'correlation_id'  => $correlationId,
                    'tenant_id'       => $job->tenantId,
                    'job_id'          => $job->id,
                    'worker_identity' => $job->workerIdentity,
                    'last_cursor'     => $outcome->newCursor,
                ]);
                // Per ADR 0047, a lease-losing worker does NOT delete
                // its artifact — the re-claimer now owns the row and
                // may already be resuming from these exact bytes.
                // close() releases the file lock and handle only.
                // (A no-op if the yield branch above already closed it.)
                $stream->close();
                return JobOutcome::LeaseLost;
            }

            $chunkElapsedMs = (int) round((microtime(true) - $chunkStart) * 1000);
            $this->logger->info('chronicler chunk written', [
                'event'            => 'chunk_written',
                'source'           => 'chronicler',
                'correlation_id'   => $correlationId,
                'tenant_id'        => $job->tenantId,
                'job_id'           => $job->id,
                'worker_identity'  => $job->workerIdentity,
                'last_cursor'      => $outcome->newCursor,
                'rows_streamed'    => $outcome->rowsStreamed,
                'bytes_written'    => $artifactBytes - $bytesBaseline,
                'chunk_elapsed_ms' => $chunkElapsedMs,
            ]);

            if ($outcome->yielded) {
                $elapsedMs = (int) round((microtime(true) - $startTime) * 1000);
                $this->logger->info('chronicler job yielded', [
                    'event'               => 'job_yielded',
                    'source'              => 'chronicler',
                    'correlation_id'      => $correlationId,
                    'tenant_id'           => $job->tenantId,
                    'job_id'              => $job->id,
                    'worker_identity'     => $job->workerIdentity,
                    'last_cursor'         => $outcome->newCursor,
                    'artifact_bytes'      => $artifactBytes,
                    'rows_streamed_total' => $rowsTotal,
                    'skip_count'          => $skipCount,
                    'cause'               => $yieldCause,
                    'elapsed_ms'          => $elapsedMs,
                ]);
                return JobOutcome::Yielded;
            }

            if ($outcome->isFinal) {
                $stream->close();
                $elapsedMs = (int) round((microtime(true) - $startTime) * 1000);
                $this->logger->info('chronicler job complete', [
                    'event'                => 'job_complete',
                    'source'               => 'chronicler',
                    'correlation_id'       => $correlationId,
                    'tenant_id'            => $job->tenantId,
                    'job_id'               => $job->id,
                    'worker_identity'      => $job->workerIdentity,
                    'artifact_path'        => $stream->path(),
                    'rows_streamed_total'  => $rowsTotal,
                    'bytes_written_total'  => $stream->bytesWritten(),
                    'skip_count'           => $skipCount,
                    'elapsed_ms'           => $elapsedMs,
                ]);
                return JobOutcome::Completed;
            }

            $cursor = $newCursor;
            ($this->sleepFn)($this->interChunkDelayMicros);
        }
    }

    /**
     * Commit one chunk's progress. The returned {@see ChunkOutcome}
     * carries the new cursor, rows streamed, finality flag, the
     * yield flag, AND the lease-loss verdict (`UPDATE … WHERE
     * worker_identity = self` affecting zero rows ⇒ another worker
     * overwrote our row).
     *
     * `artifactPath` / `artifactBytes` are written on EVERY commit, not
     * only the final one — per ADR 0047 this is what makes an in-flight
     * job's row a usable resume anchor for an abandoned re-claim, and
     * incidentally makes a mid-flight crash's partial discoverable by
     * {@see GcSweeper}'s orphan bucket for the first time.
     *
     * `$yield` (ADR 0050) folds the yield into the SAME transaction as
     * the ordinary chunk commit rather than a second write: `status`
     * flips back to `pending` and `worker_identity` clears, while
     * `claimed_at` / `correlation_id` are untouched (no `UPDATE`
     * clause touches them) so an operator can still see when the job
     * was first claimed and a re-claim continues under the same
     * correlation id. Mutually exclusive with `$isFinal` — the caller
     * never sets both.
     */
    private function commitChunk(
        int $jobId,
        string $workerIdentity,
        int $newCursor,
        int $rowsStreamed,
        int $skipCount,
        bool $isFinal,
        string $artifactPath,
        int $artifactBytes,
        bool $yield = false,
    ): ChunkOutcome {
        $now = $this->utcNow();
        $this->pdo->beginTransaction();
        try {
            if ($isFinal) {
                $stmt = $this->pdo->prepare(
                    'UPDATE stardust_export_jobs'
                    . " SET last_cursor = ?, heartbeat_at = ?, skip_count = ?,"
                    . "     status = 'completed', artifact_path = ?, artifact_bytes = ?, completed_at = ?"
                    . ' WHERE id = ? AND worker_identity = ?'
                );
                $stmt->execute([
                    $newCursor, $now, $skipCount, $artifactPath, $artifactBytes, $now, $jobId, $workerIdentity,
                ]);
            } elseif ($yield) {
                $stmt = $this->pdo->prepare(
                    'UPDATE stardust_export_jobs'
                    . " SET last_cursor = ?, heartbeat_at = ?, skip_count = ?,"
                    . "     artifact_path = ?, artifact_bytes = ?,"
                    . "     status = 'pending', worker_identity = NULL"
                    . ' WHERE id = ? AND worker_identity = ?'
                );
                $stmt->execute([
                    $newCursor, $now, $skipCount, $artifactPath, $artifactBytes, $jobId, $workerIdentity,
                ]);
            } else {
                $stmt = $this->pdo->prepare(
                    'UPDATE stardust_export_jobs'
                    . ' SET last_cursor = ?, heartbeat_at = ?, skip_count = ?,'
                    . '     artifact_path = ?, artifact_bytes = ?'
                    . ' WHERE id = ? AND worker_identity = ?'
                );
                $stmt->execute([
                    $newCursor, $now, $skipCount, $artifactPath, $artifactBytes, $jobId, $workerIdentity,
                ]);
            }
            $affected = $stmt->rowCount();
            $this->pdo->commit();
            return new ChunkOutcome(
                newCursor: $newCursor,
                rowsStreamed: $rowsStreamed,
                isFinal: $isFinal,
                leaseLost: $affected === 0,
                yielded: $yield && $affected > 0,
            );
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function failExcessiveSkips(
        ClaimedJob $job,
        ArtifactStream $stream,
        string $correlationId,
        int $lastCursor,
        int $skipCount,
        float $startTime,
    ): JobOutcome {
        $bytesWritten = $stream->bytesWritten();
        $stream->delete();
        $this->markFailed($job, 'excessive_skips', preserveCursor: true, lastCursor: $lastCursor, skipCount: $skipCount);
        $this->emitJobFailed($job, $correlationId, 'excessive_skips', $lastCursor, $bytesWritten, $startTime);
        return JobOutcome::FailedExcessiveSkips;
    }

    private function failQueryFailure(
        ClaimedJob $job,
        ArtifactStream $stream,
        string $correlationId,
        int $lastCursor,
        int $skipCount,
        float $startTime,
    ): JobOutcome {
        $bytesWritten = $stream->bytesWritten();
        $stream->delete();
        // `last_cursor` is intentionally preserved per ADR 0025 so an
        // operator can restart the job from where the disconnect hit.
        $this->markFailed($job, 'query_failure', preserveCursor: true, lastCursor: $lastCursor, skipCount: $skipCount);
        $this->emitJobFailed($job, $correlationId, 'db_disconnect_exhausted', $lastCursor, $bytesWritten, $startTime);
        return JobOutcome::FailedQueryFailure;
    }

    private function failDiskFull(
        ClaimedJob $job,
        ArtifactStream $stream,
        string $correlationId,
        int $lastCursor,
        int $skipCount,
        float $startTime,
    ): JobOutcome {
        $bytesWritten = $stream->bytesWritten();
        $stream->delete();
        $this->markFailed($job, 'disk_full', preserveCursor: true, lastCursor: $lastCursor, skipCount: $skipCount);
        $this->emitJobFailed($job, $correlationId, 'disk_full', $lastCursor, $bytesWritten, $startTime);
        return JobOutcome::FailedDiskFull;
    }

    private function failArtifactOversized(
        ClaimedJob $job,
        ArtifactStream $stream,
        string $correlationId,
        int $bytesWritten,
    ): JobOutcome {
        $stream->delete();
        $this->markFailed($job, 'artifact_size_exceeded', preserveCursor: true);
        // Distinct event from job_failed — operators expect the size
        // cap to fire as its own dashboard signal (ADR 0025).
        $this->logger->warning('chronicler artifact oversized', [
            'event'           => 'artifact_oversized',
            'source'          => 'chronicler',
            'correlation_id'  => $correlationId,
            'tenant_id'       => $job->tenantId,
            'job_id'          => $job->id,
            'worker_identity' => $job->workerIdentity,
            'bytes_written'   => $bytesWritten,
            'cap_bytes'       => $this->artifactSizeCapBytes,
        ]);
        return JobOutcome::FailedArtifactSizeExceeded;
    }

    /**
     * Marks the job terminally `failed`. Every caller has already
     * called `$stream->delete()` before reaching here — this UPDATE
     * also NULLs `artifact_path` / `artifact_bytes` in the same
     * transaction, so a `failed` row never advertises a resume anchor
     * to bytes that no longer exist on disk (ADR 0047).
     */
    private function markFailed(
        ClaimedJob $job,
        string $failedReason,
        bool $preserveCursor,
        ?int $lastCursor = null,
        ?int $skipCount = null,
    ): void {
        $now = $this->utcNow();
        $this->pdo->beginTransaction();
        try {
            if ($preserveCursor && $lastCursor !== null && $skipCount !== null) {
                $stmt = $this->pdo->prepare(
                    'UPDATE stardust_export_jobs'
                    . " SET status = 'failed', failed_reason = ?, completed_at = ?,"
                    . '     heartbeat_at = ?, last_cursor = ?, skip_count = ?,'
                    . '     artifact_path = NULL, artifact_bytes = NULL'
                    . ' WHERE id = ? AND worker_identity = ?'
                );
                $stmt->execute([
                    $failedReason, $now, $now, $lastCursor, $skipCount, $job->id, $job->workerIdentity,
                ]);
            } else {
                $stmt = $this->pdo->prepare(
                    'UPDATE stardust_export_jobs'
                    . " SET status = 'failed', failed_reason = ?, completed_at = ?, heartbeat_at = ?,"
                    . '     artifact_path = NULL, artifact_bytes = NULL'
                    . ' WHERE id = ? AND worker_identity = ?'
                );
                $stmt->execute([$failedReason, $now, $now, $job->id, $job->workerIdentity]);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function emitJobFailed(
        ClaimedJob $job,
        string $correlationId,
        string $reason,
        int $lastCursor,
        int $bytesWritten,
        float $startTime,
    ): void {
        $elapsedMs = (int) round((microtime(true) - $startTime) * 1000);
        $this->logger->error('chronicler job failed', [
            'event'           => 'job_failed',
            'source'          => 'chronicler',
            'correlation_id'  => $correlationId,
            'tenant_id'       => $job->tenantId,
            'job_id'          => $job->id,
            'worker_identity' => $job->workerIdentity,
            'reason'          => $reason,
            'last_cursor'     => $lastCursor,
            'bytes_written'   => $bytesWritten,
            'elapsed_ms'      => $elapsedMs,
        ]);
    }

    /**
     * Re-establish the database connection with the configured backoff
     * schedule (ADR 0025 Commitment 6). PHP's PDO never auto-reconnects
     * a dead handle, so recovery means building a *fresh* connection via
     * the injected {@see PdoConnector} and re-pointing BOTH `$this->pdo`
     * (used by the chunk-commit / mark-failed transactions) and
     * `$this->pager` (used by the next bounded probe). A successful
     * `connect()` is itself the liveness check — it throws on failure.
     *
     * Returns true once a fresh connection is in place; false when no
     * connector is wired (cannot reconnect) or the schedule is
     * exhausted — both fall through to the unchanged `failQueryFailure()`
     * terminal with `last_cursor` preserved.
     */
    private function reconnectWithBackoff(ClaimedJob $job, string $correlationId): bool
    {
        if ($this->connector === null) {
            return false;
        }
        foreach ($this->dbDisconnectBackoffSeconds as $delay) {
            // Backoff is in whole seconds; $sleepFn is usleep-shaped.
            ($this->sleepFn)((int) $delay * 1_000_000);
            try {
                $fresh = $this->connector->connect();
            } catch (PDOException) {
                continue; // still down — next delay
            }
            $this->pdo = $fresh;
            $this->pager = new EntryDataPager($fresh);
            return true;
        }
        return false;
    }

    private function isDeadlock(PDOException $e): bool
    {
        $info = $e->errorInfo;
        if (is_array($info) && isset($info[0]) && $info[0] === '40001') {
            return true;
        }
        if (is_array($info) && isset($info[1]) && (int) $info[1] === 1213) {
            return true;
        }
        return false;
    }

    private function isDisconnect(PDOException $e): bool
    {
        $info = $e->errorInfo;
        // MySQL "server has gone away" (2006) / "lost connection" (2013)
        // plus PDO-level connection-aborted SQLSTATEs (08*).
        if (is_array($info) && isset($info[1])) {
            $code = (int) $info[1];
            if ($code === 2006 || $code === 2013) {
                return true;
            }
        }
        if (is_array($info) && isset($info[0]) && is_string($info[0])
            && str_starts_with($info[0], '08')) {
            return true;
        }
        return false;
    }

    private function utcNow(): string
    {
        return $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
