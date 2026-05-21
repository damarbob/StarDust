<?php

declare(strict_types=1);

namespace StarDust;

use PDO;
use Psr\Log\LoggerInterface;
use StarDust\Bootstrap\Bootstrapper;
use StarDust\Config\Config;
use StarDust\Write\BulkIngestOptions;
use StarDust\Write\BulkIngestResult;
use StarDust\Write\BulkIngestSubmitter;
use StarDust\Write\BulkIngestor;
use StarDust\Write\EntryPayload;
use StarDust\Write\EntryWriteResult;
use StarDust\Write\EntryWriter;
use StarDust\Write\ImportJobId;
use StarDust\Write\TenantId;

/**
 * Engine entry-point class.
 *
 * Holds the injected Config and exposes typed accessors plus the
 * Phase 1 bootstrap entry point and the Phase 3 write-path methods.
 * Later phases append additional entry points here without breaking
 * this surface.
 */
final class StarDust
{
    public const VERSION = '0.3.0-alpha.1';

    private ?EntryWriter $entryWriter = null;
    private ?BulkIngestor $bulkIngestor = null;
    private ?BulkIngestSubmitter $bulkSubmitter = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function pdo(): PDO
    {
        return $this->config->pdo;
    }

    public function logger(): LoggerInterface
    {
        return $this->config->logger;
    }

    /**
     * Idempotent Phase 1 bootstrap: creates the data plane, schema
     * registry, and operational tables and seeds the singleton
     * stardust_schema_version row. Safe to invoke on an
     * already-bootstrapped database.
     */
    public function bootstrap(): void
    {
        (new Bootstrapper($this->config->pdo))->run();
    }

    /**
     * Phase 3 single-entry write. Atomically writes the JSON payload
     * to `entry_data` and each mapped slot to `entry_slots_page_N`;
     * falls back to `stardust_sync_queue` enqueue (in the same
     * transaction) when one or more fields lack a live slot
     * (ADR 0007 exhaustion fallback). Throws
     * {@see \StarDust\Exception\InvalidTenantIdException} for an
     * out-of-range `tenant_id`.
     */
    public function write(EntryPayload $payload): EntryWriteResult
    {
        TenantId::assertValid($payload->tenantId);
        return $this->entryWriter()->write($payload);
    }

    /**
     * Phase 3 synchronous chunked bulk ingest (≤ 1 000 entities per
     * call). Throws
     * {@see \StarDust\Exception\PayloadTooLargeException} above the
     * threshold — callers must use {@see self::submitBulkWrite()}
     * for larger batches.
     *
     * @param list<EntryPayload> $payloads
     */
    public function bulkWrite(array $payloads, ?BulkIngestOptions $options = null): BulkIngestResult
    {
        foreach ($payloads as $p) {
            TenantId::assertValid($p->tenantId);
        }
        return $this->bulkIngestor()->ingest($payloads, $options);
    }

    /**
     * Phase 3 async bulk-ingest submission (> 1 000 entities, or
     * smaller batches the caller wants processed asynchronously).
     * Validates the payload, writes the serialized JSON artifact to
     * `Config::$artifactDir`, inserts a `stardust_import_jobs` row
     * with `status='pending'`, and returns the row's
     * {@see ImportJobId}. Phase 5's Reconciler drains the job.
     *
     * The `$idempotencyKey` is scoped per `tenant_id` — a retry
     * with the same key returns the existing job's id.
     *
     * @param list<EntryPayload> $payloads
     */
    public function submitBulkWrite(
        int $tenantId,
        array $payloads,
        ?string $idempotencyKey = null,
    ): ImportJobId {
        TenantId::assertValid($tenantId);
        return $this->bulkSubmitter()->submit($tenantId, $payloads, $idempotencyKey);
    }

    private function entryWriter(): EntryWriter
    {
        return $this->entryWriter ??= new EntryWriter(
            pdo: $this->config->pdo,
            clock: $this->config->clock,
            logger: $this->config->logger,
        );
    }

    private function bulkIngestor(): BulkIngestor
    {
        return $this->bulkIngestor ??= new BulkIngestor(
            pdo: $this->config->pdo,
            entryWriter: $this->entryWriter(),
            logger: $this->config->logger,
        );
    }

    private function bulkSubmitter(): BulkIngestSubmitter
    {
        return $this->bulkSubmitter ??= new BulkIngestSubmitter(
            pdo: $this->config->pdo,
            clock: $this->config->clock,
            logger: $this->config->logger,
            artifactDir: $this->config->artifactDir,
        );
    }
}
