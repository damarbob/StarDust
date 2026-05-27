<?php

declare(strict_types=1);

namespace StarDust\Export;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Exception\ExportJobActiveCapExceededException;
use StarDust\Write\TenantId;
use Throwable;

/**
 * Phase 7 async export submission entry point (ADR 0010).
 *
 * `submit()` runs the per-tenant active-job cap check + INSERT in one
 * transaction:
 *   - `SELECT id FROM stardust_export_jobs WHERE tenant_id = ?
 *      AND status IN ('pending','processing') FOR UPDATE`
 *     — the `FOR UPDATE` holds index range locks on the
 *     `(tenant_id, status)` composite, preventing a concurrent
 *     submitter from racing past the count check.
 *   - If `count >= cap` → throw
 *     {@see ExportJobActiveCapExceededException} (caller surfaces
 *     a 429-style response).
 *   - Otherwise INSERT a `pending` row with `worker_identity = NULL`
 *     and `last_cursor = NULL`. The Chronicler will pick it up via
 *     {@see \StarDust\Chronicler\ExportJobClaimer}.
 *
 * The submitter does NOT support an idempotency key — the per-tenant
 * cap is the duplicate-submission control. A key could be appended
 * non-breakingly in a later phase if consumer needs change.
 *
 * Events:
 *   - `export_accepted` (source: `export_api`) — emitted after the
 *     INSERT commits. Carries `tenant_id`, `job_id`, `format`,
 *     `model_id`.
 */
final class ExportJobSubmitter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly int $perTenantActiveCap,
    ) {
    }

    public function submit(ExportJobRequest $request): ExportJobId
    {
        TenantId::assertValid($request->tenantId);

        // model_id is stamped into the stored filter so the Chronicler
        // can hydrate it on claim without a separate column. We do
        // this here rather than in the DTO so the request shape stays
        // intentional (model_id is a typed first-class field).
        $storedFilter = array_merge($request->filter, ['model_id' => $request->modelId]);
        $filterJson = (string) json_encode(
            $storedFilter,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        $now = $this->utcNow();

        $this->pdo->beginTransaction();
        try {
            // Lock the tenant's pending/processing range. InnoDB
            // gap locks on the (tenant_id, status) composite block a
            // concurrent INSERT for the same tenant from completing
            // until we commit, closing the TOCTOU window.
            $count = $this->pdo->prepare(
                'SELECT id FROM stardust_export_jobs'
                . " WHERE tenant_id = ? AND status IN ('pending','processing')"
                . ' FOR UPDATE'
            );
            $count->execute([$request->tenantId]);
            $existing = $count->rowCount();
            if ($existing >= $this->perTenantActiveCap) {
                $this->pdo->rollBack();
                throw new ExportJobActiveCapExceededException(
                    tenantId: $request->tenantId,
                    activeCount: $existing,
                    cap: $this->perTenantActiveCap,
                );
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO stardust_export_jobs'
                . ' (tenant_id, status, filter, format, created_at)'
                . " VALUES (?, 'pending', ?, ?, ?)"
            );
            $insert->execute([$request->tenantId, $filterJson, $request->format, $now]);
            $jobId = (int) $this->pdo->lastInsertId();

            $this->pdo->commit();
        } catch (ExportJobActiveCapExceededException $e) {
            throw $e;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->logger->info('export submission accepted', [
            'event'     => 'export_accepted',
            'source'    => 'export_api',
            'tenant_id' => $request->tenantId,
            'job_id'    => $jobId,
            'model_id'  => $request->modelId,
            'format'    => $request->format,
        ]);

        return new ExportJobId($jobId);
    }

    /**
     * Tenant-isolated read of one `stardust_export_jobs` row. Returns
     * null when the job does not exist OR belongs to a different
     * tenant — never throws on not-found, mirroring
     * {@see \StarDust\Read\EntryReader::get()}.
     */
    public function getJob(int $tenantId, int $jobId): ?ExportJob
    {
        TenantId::assertValid($tenantId);

        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, status, filter, format, last_cursor,'
            . '       artifact_path, failed_reason, skip_count,'
            . '       worker_identity, claimed_at, heartbeat_at,'
            . '       created_at, completed_at'
            . '  FROM stardust_export_jobs'
            . ' WHERE id = ? AND tenant_id = ?'
        );
        $stmt->execute([$jobId, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $filter = [];
        if (is_string($row['filter']) && $row['filter'] !== '') {
            $decoded = json_decode($row['filter'], true);
            if (is_array($decoded)) {
                $filter = $decoded;
            }
        }

        return new ExportJob(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            status: (string) $row['status'],
            filter: $filter,
            format: (string) $row['format'],
            lastCursor: $row['last_cursor'] === null ? null : (int) $row['last_cursor'],
            artifactPath: $row['artifact_path'] === null ? null : (string) $row['artifact_path'],
            failedReason: $row['failed_reason'] === null ? null : (string) $row['failed_reason'],
            skipCount: (int) $row['skip_count'],
            workerIdentity: $row['worker_identity'] === null ? null : (string) $row['worker_identity'],
            claimedAt: $this->parseDateTime($row['claimed_at']),
            heartbeatAt: $this->parseDateTime($row['heartbeat_at']),
            createdAt: $this->parseDateTime($row['created_at']) ?? new DateTimeImmutable('now', new DateTimeZone('UTC')),
            completedAt: $this->parseDateTime($row['completed_at']),
        );
    }

    private function parseDateTime(mixed $raw): ?DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        // MySQL DATETIME columns are stored without a timezone marker;
        // every column the Chronicler writes is normalised to UTC, so
        // parsing as UTC is correct.
        try {
            return new DateTimeImmutable($raw, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    private function utcNow(): string
    {
        return $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
