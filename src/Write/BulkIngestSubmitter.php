<?php

declare(strict_types=1);

namespace StarDust\Write;

use DateTimeZone;
use PDO;
use PDOException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Phase 3 async bulk-ingest submission entry point (ADR 0011).
 *
 * `submit()` is the > 1 000-entity escape hatch: it validates the
 * batch, writes the serialized payload JSON to disk under
 * `$artifactDir`, inserts a `stardust_import_jobs` row with
 * `status='pending'`, and returns the row's id wrapped in an
 * {@see ImportJobId}. The Phase 5 Reconciler will claim and drain
 * the job; Phase 3 only persists.
 *
 * Idempotency-key semantics per ADR 0011:
 *   - When the caller supplies `$idempotencyKey`, a retry with the
 *     same `(tenant_id, idempotency_key)` pair MUST return the
 *     existing job id rather than create a duplicate row.
 *   - Enforcement is at the database level via
 *     `ux_import_jobs_tenant_idempotency`. The submitter catches the
 *     uniqueness violation and SELECTs the existing row's id.
 *   - When `$idempotencyKey` is null the column stays NULL; MySQL
 *     UNIQUE permits multiple NULL rows so unkeyed submissions never
 *     collide.
 *
 * Structured-log events (closed vocabulary, ADR 0020):
 *   - `bulk_accepted` (source: `bulk_api`) — emitted after the job
 *                       row is committed.
 */
final class BulkIngestSubmitter
{
    /** ADR 0011 says async path applies above the sync threshold. */
    public const ASYNC_LOWER_BOUND_INCLUSIVE = 1;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly string $artifactDir,
    ) {
    }

    /**
     * @param list<EntryPayload> $payloads
     */
    public function submit(
        int $tenantId,
        array $payloads,
        ?string $idempotencyKey = null,
    ): ImportJobId {
        TenantId::assertValid($tenantId);

        $count = count($payloads);
        if ($count < self::ASYNC_LOWER_BOUND_INCLUSIVE) {
            throw new RuntimeException(
                'BulkIngestSubmitter::submit() requires at least 1 entity; got 0.'
            );
        }

        // Validate per-payload tenant_id and that every payload's
        // tenant matches the submission's tenant — async submission
        // is single-tenant by contract (per-tenant idempotency key,
        // per-tenant queue depth).
        foreach ($payloads as $i => $p) {
            TenantId::assertValid($p->tenantId);
            if ($p->tenantId !== $tenantId) {
                throw new RuntimeException(
                    "BulkIngestSubmitter::submit(): payload at index {$i} has tenant_id "
                    . "{$p->tenantId} but submission tenant_id is {$tenantId}."
                );
            }
        }

        // Idempotency-key fast path: if a row already exists for this
        // (tenant_id, idempotency_key) pair, return its id without
        // re-writing the artifact. This both saves disk and matches
        // ADR 0011's semantics ("same key returns the original job's
        // status and manifest rather than re-processing").
        if ($idempotencyKey !== null) {
            $existingId = $this->findExistingJobId($tenantId, $idempotencyKey);
            if ($existingId !== null) {
                $this->logger->info('bulk submission idempotency hit', [
                    'event'           => 'bulk_accepted',
                    'source'          => 'bulk_api',
                    'tenant_id'       => $tenantId,
                    'job_id'          => $existingId,
                    'entry_count'     => $count,
                    'idempotency_key' => $idempotencyKey,
                ]);
                return new ImportJobId($existingId);
            }
        }

        if (!is_dir($this->artifactDir) && !@mkdir($this->artifactDir, 0o775, true) && !is_dir($this->artifactDir)) {
            throw new RuntimeException(
                "BulkIngestSubmitter: artifact directory '{$this->artifactDir}' is not writable."
            );
        }

        $artifactPath = $this->writeArtifact($tenantId, $payloads);

        $now = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO stardust_import_jobs'
                . ' (tenant_id, status, idempotency_key, artifact_path, entry_count, created_at)'
                . " VALUES (?, 'pending', ?, ?, ?, ?)"
            );
            $insert->execute([$tenantId, $idempotencyKey, $artifactPath, $count, $now]);
            $jobId = (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            // Concurrent retry with the same idempotency_key may race
            // past the fast-path check above and collide on the
            // UNIQUE index. Treat that race as the same logical
            // outcome — return the existing row's id and discard the
            // artifact we just wrote.
            if ($idempotencyKey !== null && $this->isDuplicateKeyError($e)) {
                @unlink($artifactPath);
                $existingId = $this->findExistingJobId($tenantId, $idempotencyKey);
                if ($existingId !== null) {
                    $this->logger->info('bulk submission idempotency race resolved', [
                        'event'           => 'bulk_accepted',
                        'source'          => 'bulk_api',
                        'tenant_id'       => $tenantId,
                        'job_id'          => $existingId,
                        'entry_count'     => $count,
                        'idempotency_key' => $idempotencyKey,
                    ]);
                    return new ImportJobId($existingId);
                }
            }
            // Any other PDO failure: discard the artifact (it has no
            // corresponding row and would otherwise leak on disk).
            @unlink($artifactPath);
            throw $e;
        } catch (Throwable $e) {
            @unlink($artifactPath);
            throw $e;
        }

        $this->logger->info('bulk submission accepted', [
            'event'           => 'bulk_accepted',
            'source'          => 'bulk_api',
            'tenant_id'       => $tenantId,
            'job_id'          => $jobId,
            'entry_count'     => $count,
            'idempotency_key' => $idempotencyKey,
        ]);

        return new ImportJobId($jobId);
    }

    private function findExistingJobId(int $tenantId, string $idempotencyKey): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM stardust_import_jobs'
            . ' WHERE tenant_id = ? AND idempotency_key = ? LIMIT 1'
        );
        $stmt->execute([$tenantId, $idempotencyKey]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * @param list<EntryPayload> $payloads
     */
    private function writeArtifact(int $tenantId, array $payloads): string
    {
        $serializable = array_map(
            static fn(EntryPayload $p): array => [
                'tenant_id' => $p->tenantId,
                'model_id'  => $p->modelId,
                'fields'    => $p->fields,
            ],
            $payloads,
        );

        $json = json_encode(
            ['tenant_id' => $tenantId, 'entries' => $serializable],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        // 36-char v4 UUID — re-use the generator pattern from
        // StdoutNdjsonLogger so the operational surface stays consistent.
        $uuid = self::generateUuidV4();
        $path = rtrim($this->artifactDir, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . "import_{$tenantId}_{$uuid}.json";

        $written = @file_put_contents($path, $json, LOCK_EX);
        if ($written === false || $written !== strlen($json)) {
            throw new RuntimeException(
                "BulkIngestSubmitter: failed to write artifact at '{$path}'."
            );
        }

        return $path;
    }

    /**
     * MySQL duplicate-key error code is 1062. PDOException::getCode()
     * is the SQLSTATE (e.g., '23000'); the driver-specific code lives
     * in errorInfo()[1].
     */
    private function isDuplicateKeyError(PDOException $e): bool
    {
        $info = $e->errorInfo ?? null;
        if (is_array($info) && isset($info[1]) && (int) $info[1] === 1062) {
            return true;
        }
        return false;
    }

    private static function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split(bin2hex($bytes), 4)
        );
    }
}
