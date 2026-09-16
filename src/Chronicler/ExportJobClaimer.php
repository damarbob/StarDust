<?php

declare(strict_types=1);

namespace StarDust\Chronicler;

use DateTimeZone;
use PDO;
use Psr\Clock\ClockInterface;
use StarDust\Support\WorkerIdentity;
use Throwable;

/**
 * Atomic claim of one `stardust_export_jobs` row — either a fresh
 * `pending` job (per-tenant round-robin) or an abandoned `processing`
 * job whose heartbeat lapsed.
 *
 * Per-tenant round-robin is computed at claim time as
 * `MIN(created_at) GROUP BY tenant_id` over `status='pending'` rows
 * (chronicler_daemon.md §4 AC#3); it is NOT materialized as a column.
 * The result is that any tenant with a pending job always sees its
 * oldest one prioritised; a single-tenant burst cannot starve another
 * tenant's queued submission.
 *
 * Both claim paths use one transaction:
 *   BEGIN;
 *   SELECT … FOR UPDATE SKIP LOCKED LIMIT 1;
 *   UPDATE … SET status='processing', worker_identity=…, …;
 *   COMMIT;
 *
 * `SKIP LOCKED` is the multi-worker safety net — two concurrent
 * claimers never block on the same row, they each take the next
 * available one. On the pending path the `UPDATE` writes `claimed_at`;
 * on the abandoned path `claimed_at` is intentionally preserved so
 * operators can see the original claim time, with the
 * `worker_identity` and `heartbeat_at` overwritten in place.
 *
 * Per ADR 0047, the abandoned path does NOT unlink the prior partial
 * artifact — it hands `artifact_path` / `artifact_bytes` forward on the
 * returned {@see ClaimedJob} so the processor's {@see ArtifactStream}
 * can attempt a verified re-open. Deleting here, before the new worker
 * even exists, is exactly the defect this ADR closes: the file and the
 * cursor that describes it must be discarded or adopted together, and
 * only the stream that is about to write to the path is positioned to
 * make that call.
 *
 * Per ADR 0050, the SAME is true of the pending path: a `pending` row
 * carrying a usable anchor (`artifact_path` + a positive
 * `artifact_bytes`) is not a fresh submission — it is a job the
 * processor previously yielded back to `pending` at a chunk boundary.
 * `claimPending()` therefore selects and forwards the anchor exactly
 * as `claimAbandoned()` does, and reports {@see ClaimKind::Resumed}
 * rather than `Pending` when the anchor is usable. Omitting this was
 * the roadmap sketch's original mistake: a yielded job re-claimed with
 * no anchor gets a fresh artifact path from
 * {@see ArtifactStreamFactory} and restarts from byte zero on every
 * tick, orphaning one partial per tick and never converging on a job
 * larger than one budget.
 *
 * The claimer intentionally exposes no constructor for
 * `workerIdentity` — each `claimPendingOrAbandoned()` invocation
 * mints a fresh identity via {@see WorkerIdentity::mint()} so a
 * single Chronicler process can claim more than one job in its
 * lifetime without inheriting a stale uuid suffix.
 */
final class ExportJobClaimer
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock,
        private readonly int $leaseTimeoutSeconds,
    ) {
    }

    public function claimPendingOrAbandoned(): ?ClaimedJob
    {
        $claim = $this->claimPending();
        if ($claim !== null) {
            return $claim;
        }
        return $this->claimAbandoned();
    }

    private function claimPending(): ?ClaimedJob
    {
        $workerIdentity = WorkerIdentity::mint();
        $now = $this->utcNow();

        $this->pdo->beginTransaction();
        try {
            // Per-tenant round-robin (chronicler_daemon.md §4 AC#3):
            // outer ORDER BY uses each tenant's MIN(created_at) over
            // pending rows so the tenant whose oldest pending submission
            // is the oldest in the entire pool is served first. Within
            // a tenant the inner created_at preserves FIFO.
            $select = $this->pdo->prepare(
                'SELECT j.id, j.tenant_id, j.filter, j.format,'
                . '       j.last_cursor, j.skip_count, j.artifact_path, j.artifact_bytes, j.correlation_id'
                . '  FROM stardust_export_jobs j'
                . " WHERE j.status = 'pending'"
                . ' ORDER BY ('
                . '   SELECT MIN(j2.created_at) FROM stardust_export_jobs j2'
                . "    WHERE j2.status = 'pending' AND j2.tenant_id = j.tenant_id"
                . ' ) ASC, j.created_at ASC'
                . ' LIMIT 1 FOR UPDATE SKIP LOCKED'
            );
            $select->execute();
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                $this->pdo->commit();
                return null;
            }

            $jobId = (int) $row['id'];
            $update = $this->pdo->prepare(
                'UPDATE stardust_export_jobs'
                . " SET status = 'processing',"
                . '     worker_identity = ?, claimed_at = ?, heartbeat_at = ?'
                . ' WHERE id = ?'
            );
            $update->execute([$workerIdentity, $now, $now, $jobId]);
            $this->pdo->commit();

            // ADR 0050: a usable anchor on a `pending` row means this is
            // a previously-yielded job, not a fresh submission — same
            // predicate ArtifactStreamFactory::from() applies, so the
            // reported kind can never claim an anchor the factory then
            // discards.
            $priorArtifact = $row['artifact_path'];
            $anchorBytes = $row['artifact_bytes'] === null ? null : (int) $row['artifact_bytes'];
            $hasAnchor = is_string($priorArtifact) && $priorArtifact !== ''
                && $anchorBytes !== null && $anchorBytes > 0;

            return new ClaimedJob(
                id: $jobId,
                tenantId: (int) $row['tenant_id'],
                modelId: $this->extractModelId($row['filter']),
                format: (string) $row['format'],
                filter: $this->decodeFilter($row['filter']),
                lastCursor: $row['last_cursor'] === null ? null : (int) $row['last_cursor'],
                workerIdentity: $workerIdentity,
                claimKind: $hasAnchor ? ClaimKind::Resumed : ClaimKind::Pending,
                skipCount: (int) $row['skip_count'],
                correlationId: $row['correlation_id'] === null
                    ? null
                    : (string) $row['correlation_id'],
                artifactPath: $hasAnchor ? $priorArtifact : null,
                artifactBytes: $hasAnchor ? $anchorBytes : null,
            );
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function claimAbandoned(): ?ClaimedJob
    {
        $workerIdentity = WorkerIdentity::mint();
        $now = $this->utcNow();

        $this->pdo->beginTransaction();
        try {
            // Use UTC_TIMESTAMP() rather than NOW() because the
            // Chronicler/Submitter/test fixtures all stamp
            // heartbeat_at in UTC. MySQL's NOW() returns the session
            // local time which can drift from the UTC-stored values
            // and falsely identify fresh leases as abandoned.
            $select = $this->pdo->prepare(
                'SELECT j.id, j.tenant_id, j.filter, j.format,'
                . '       j.last_cursor, j.skip_count, j.artifact_path, j.artifact_bytes, j.correlation_id'
                . '  FROM stardust_export_jobs j'
                . " WHERE j.status = 'processing'"
                . '   AND j.heartbeat_at IS NOT NULL'
                . '   AND j.heartbeat_at < (UTC_TIMESTAMP() - INTERVAL ' . $this->leaseTimeoutSeconds . ' SECOND)'
                . ' ORDER BY j.heartbeat_at ASC'
                . ' LIMIT 1 FOR UPDATE SKIP LOCKED'
            );
            $select->execute();
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                $this->pdo->commit();
                return null;
            }

            $jobId = (int) $row['id'];
            // claimed_at is intentionally PRESERVED on re-claim so
            // operators can see the original claim time; only
            // worker_identity and heartbeat_at are overwritten.
            $update = $this->pdo->prepare(
                'UPDATE stardust_export_jobs'
                . ' SET worker_identity = ?, heartbeat_at = ?'
                . ' WHERE id = ?'
            );
            $update->execute([$workerIdentity, $now, $jobId]);
            $this->pdo->commit();

            $priorArtifact = $row['artifact_path'];

            return new ClaimedJob(
                id: $jobId,
                tenantId: (int) $row['tenant_id'],
                modelId: $this->extractModelId($row['filter']),
                format: (string) $row['format'],
                filter: $this->decodeFilter($row['filter']),
                lastCursor: $row['last_cursor'] === null ? null : (int) $row['last_cursor'],
                workerIdentity: $workerIdentity,
                claimKind: ClaimKind::Abandoned,
                skipCount: (int) $row['skip_count'],
                correlationId: $row['correlation_id'] === null
                    ? null
                    : (string) $row['correlation_id'],
                artifactPath: (is_string($priorArtifact) && $priorArtifact !== '') ? $priorArtifact : null,
                artifactBytes: $row['artifact_bytes'] === null ? null : (int) $row['artifact_bytes'],
            );
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Returns the consumer's original QueryFilter (the inner shape
     * inside the stored `{model_id, filter}` envelope). The engine's
     * model_id stamping is invisible to the rest of the pipeline.
     *
     * @return array<string,mixed>
     */
    private function decodeFilter(mixed $raw): array
    {
        $envelope = $this->decodeEnvelope($raw);
        $inner = $envelope['filter'] ?? null;
        return is_array($inner) ? $inner : [];
    }

    private function extractModelId(mixed $raw): int
    {
        $envelope = $this->decodeEnvelope($raw);
        if (!isset($envelope['model_id']) || !is_int($envelope['model_id'])) {
            // Fall back to 0 — the pager will return no rows for an
            // invalid model_id, so the job completes empty rather than
            // crashing the worker. The submitter always stamps a valid
            // model_id; this branch is defensive.
            return 0;
        }
        return $envelope['model_id'];
    }

    /** @return array<string,mixed> */
    private function decodeEnvelope(mixed $raw): array
    {
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function utcNow(): string
    {
        return $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
