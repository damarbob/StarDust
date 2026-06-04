<?php

declare(strict_types=1);

namespace StarDust\Chronicler;

use DateTimeZone;
use PDO;
use PDOException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Exception\ChroniclerArtifactDiskFullException;
use StarDust\Exception\ChroniclerRowEncodingException;
use Throwable;

/**
 * Per-job chunk-commit loop. Owns ADR 0025's commitments end-to-end:
 *
 *   - Cursor-paginated probe via {@see EntryDataPager} (LIMIT N+1 shape).
 *   - Per-row encode via {@see ArtifactStream} (CSV or JSON).
 *   - Atomic chunk-commit transaction with the lease-loss detector:
 *     `UPDATE … WHERE id = ? AND worker_identity = ?`; `rowCount() == 0`
 *     ⇒ re-claimer overwrote our row ⇒ emit `lease_lost`, delete
 *     partial artifact, return {@see JobOutcome::LeaseLost}.
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
 *
 * The `skip_count` is persisted in every chunk-commit transaction so a
 * re-claimer continues charging from the previous worker's count
 * rather than starting fresh — otherwise a dying worker could let a
 * re-claimer charge another full cap before tripping
 * `excessive_skips`.
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

    public function process(ClaimedJob $job, string $correlationId): JobOutcome
    {
        $startTime = microtime(true);
        $header = $this->headerResolver->resolve($job->tenantId, $job->modelId);
        $stream = $this->streamFactory->from($job, $header);
        // open() may write the format prelude (CSV header / JSON `[`),
        // which can trip ENOSPC. Treat header-write disk-full
        // identically to per-row disk-full per ADR 0025.
        try {
            $stream->open();
        } catch (ChroniclerArtifactDiskFullException) {
            return $this->failDiskFull($job, $stream, $correlationId, $job->lastCursor ?? 0, $job->skipCount, $startTime);
        }

        $cursor          = $job->lastCursor ?? 0;
        $skipCount       = $job->skipCount;
        $rowsTotal       = 0;
        $bytesBaseline   = $stream->bytesWritten();

        while (true) {
            $chunkStart = microtime(true);
            $rows = null;
            $retry = 0;

            // === Bounded probe with deadlock + disconnect handling ===
            while ($rows === null) {
                try {
                    $rows = $this->pager->fetchChunk($job->tenantId, $job->modelId, $cursor, $this->pageSize);
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
            $outcome = $this->commitChunk(
                jobId: $job->id,
                workerIdentity: $job->workerIdentity,
                newCursor: $newCursor,
                rowsStreamed: $rowsStreamed,
                skipCount: $skipCount,
                isFinal: $isFinal,
                artifactPath: $isFinal ? $stream->path() : null,
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
                $stream->close();
                $stream->delete();
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
                'bytes_written'    => $stream->bytesWritten() - $bytesBaseline,
                'chunk_elapsed_ms' => $chunkElapsedMs,
            ]);

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
     * carries the new cursor, rows streamed, finality flag, AND the
     * lease-loss verdict (`UPDATE … WHERE worker_identity = self`
     * affecting zero rows ⇒ another worker overwrote our row).
     */
    private function commitChunk(
        int $jobId,
        string $workerIdentity,
        int $newCursor,
        int $rowsStreamed,
        int $skipCount,
        bool $isFinal,
        ?string $artifactPath,
    ): ChunkOutcome {
        $now = $this->utcNow();
        $this->pdo->beginTransaction();
        try {
            if ($isFinal) {
                $stmt = $this->pdo->prepare(
                    'UPDATE stardust_export_jobs'
                    . " SET last_cursor = ?, heartbeat_at = ?, skip_count = ?,"
                    . "     status = 'completed', artifact_path = ?, completed_at = ?"
                    . ' WHERE id = ? AND worker_identity = ?'
                );
                $stmt->execute([
                    $newCursor, $now, $skipCount, $artifactPath, $now, $jobId, $workerIdentity,
                ]);
            } else {
                $stmt = $this->pdo->prepare(
                    'UPDATE stardust_export_jobs'
                    . ' SET last_cursor = ?, heartbeat_at = ?, skip_count = ?'
                    . ' WHERE id = ? AND worker_identity = ?'
                );
                $stmt->execute([
                    $newCursor, $now, $skipCount, $jobId, $workerIdentity,
                ]);
            }
            $affected = $stmt->rowCount();
            $this->pdo->commit();
            return new ChunkOutcome(
                newCursor: $newCursor,
                rowsStreamed: $rowsStreamed,
                isFinal: $isFinal,
                leaseLost: $affected === 0,
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
                    . '     heartbeat_at = ?, last_cursor = ?, skip_count = ?'
                    . ' WHERE id = ? AND worker_identity = ?'
                );
                $stmt->execute([
                    $failedReason, $now, $now, $lastCursor, $skipCount, $job->id, $job->workerIdentity,
                ]);
            } else {
                $stmt = $this->pdo->prepare(
                    'UPDATE stardust_export_jobs'
                    . " SET status = 'failed', failed_reason = ?, completed_at = ?, heartbeat_at = ?"
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
