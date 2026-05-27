<?php

declare(strict_types=1);

namespace StarDust;

use PDO;
use Psr\Log\LoggerInterface;
use StarDust\Bootstrap\Bootstrapper;
use StarDust\Chronicler\ArtifactStreamFactory;
use StarDust\Chronicler\Chronicler;
use StarDust\Chronicler\DiskPressureGate;
use StarDust\Chronicler\EntryDataPager;
use StarDust\Chronicler\ExportJobClaimer;
use StarDust\Chronicler\ExportJobProcessor;
use StarDust\Chronicler\GcSweeper;
use StarDust\Chronicler\HeaderResolver;
use StarDust\Config\Config;
use StarDust\Daemon\CompositeShutdownSignal;
use StarDust\Daemon\FlagFileShutdownSignal;
use StarDust\Daemon\PollLoop;
use StarDust\Daemon\ShutdownSignal;
use StarDust\Daemon\SignalShutdownSignal;
use StarDust\Export\ExportJob;
use StarDust\Export\ExportJobId;
use StarDust\Export\ExportJobRequest;
use StarDust\Export\ExportJobSubmitter;
use StarDust\Liberator\Liberator;
use StarDust\Liberator\SlotSweeper;
use StarDust\Liberator\TombstonedSlotRepository;
use StarDust\Page\PageProvisioner;
use StarDust\Read\Entry;
use StarDust\Read\EntryPage;
use StarDust\Read\EntryQuery;
use StarDust\Read\EntryReader;
use StarDust\Reconciler\DlqReplayer;
use StarDust\Reconciler\DlqWriter;
use StarDust\Reconciler\ImportJobWorkSource;
use StarDust\Reconciler\Reconciler;
use StarDust\Reconciler\SyncQueueWorkSource;
use StarDust\Retype\RetypeBackfillExecutor;
use StarDust\Retype\RetypeBackfillWorkSource;
use StarDust\Retype\RetypeCheckpointRepository;
use StarDust\Retype\RetypeInitiator;
use StarDust\Slot\SlotReserver;
use StarDust\Watcher\CapacityReporter;
use StarDust\Watcher\CardinalitySampler;
use StarDust\Watcher\Watcher;
use StarDust\Write\BackfillExecutor;
use StarDust\Write\BulkIngestOptions;
use StarDust\Write\BulkIngestResult;
use StarDust\Write\BulkIngestSubmitter;
use StarDust\Write\BulkIngestor;
use StarDust\Write\EntryPayload;
use StarDust\Write\EntryWriteResult;
use StarDust\Write\EntryWriter;
use StarDust\Write\ImportJobId;
use StarDust\Write\SlotRowUpserter;
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
    private ?EntryReader $entryReader = null;
    private ?SlotRowUpserter $slotRowUpserter = null;
    private ?BackfillExecutor $backfillExecutor = null;
    private ?PollLoop $pollLoop = null;
    private ?SlotReserver $slotReserver = null;
    private ?CardinalitySampler $cardinalitySampler = null;
    private ?RetypeInitiator $retypeInitiator = null;
    private ?ExportJobSubmitter $exportSubmitter = null;

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

    /**
     * Phase 4 bounded, tenant-isolated, cursor-paginated read.
     *
     * Runs the two-query read of ADR 0005 — a Paginated Probe that
     * selects up to `pageSize + 1` `entry_data.id` values, followed
     * by a Bounded Fetch that materialises only those rows plus
     * required indexed slot columns. Fields whose slot is
     * `backfilling`/`tombstoned`/unmapped fall back to the JSON
     * payload per ADR 0013. Filters against those same states are
     * rejected pre-flight per ADR 0004.
     *
     * Throws {@see \StarDust\Exception\InvalidTenantIdException},
     * {@see \StarDust\Exception\UnknownFieldException},
     * {@see \StarDust\Exception\FieldNotFilterableException},
     * {@see \StarDust\Exception\FieldNotIndexedException},
     * {@see \StarDust\Exception\InvalidCursorException},
     * {@see \StarDust\Exception\PageSizeOutOfRangeException}.
     */
    public function read(EntryQuery $query): EntryPage
    {
        TenantId::assertValid($query->tenantId);
        return $this->entryReader()->read($query);
    }

    /**
     * Phase 4 point read by `(tenant_id, entry_id)`. Returns the
     * decoded `entry_data.fields` payload as a {@see Entry}, or
     * `null` if the entry does not exist for this tenant (or is
     * soft-deleted). No slot joins are issued — the JSON payload is
     * the system of record per ADR 0013.
     */
    public function get(int $tenantId, int $entryId): ?Entry
    {
        TenantId::assertValid($tenantId);
        return $this->entryReader()->get($tenantId, $entryId);
    }

    /**
     * Phase 6b atomic retype initiation (ADR 0016).
     *
     * Updates the field's `declared_type`, tombstones its current
     * live slot, reserves a new `backfilling` slot of the target
     * type (or defers if no matching free slot exists), bumps
     * `stardust_schema_version`, and inserts a `running` row in
     * `backfill_checkpoints` keyed `retype_field_{$fieldId}` — all
     * in one transaction. Subsequent {@see Reconciler} ticks drain
     * the partition through {@see RetypeBackfillWorkSource}; on
     * completion the slot transitions `backfilling → ready` and a
     * post-backfill `cardinality_sampled` event fires.
     *
     * `int↔datetime` and `numeric↔datetime` retypes are rejected at
     * registry-write time with {@see \StarDust\Exception\IncompatibleRetypeException}
     * per ADR 0024 — bridge through a `string` intermediate field
     * if epoch-style migration is required.
     */
    public function retypeField(int $tenantId, int $fieldId, string $newDeclaredType): void
    {
        TenantId::assertValid($tenantId);
        $this->retypeInitiator()->initiate(
            tenantId: $tenantId,
            fieldId: $fieldId,
            newDeclaredType: $newDeclaredType,
            newIsFilterable: null,
        );
    }

    /**
     * Phase 6b filterability promotion (ADR 0016).
     *
     * Flips `stardust_fields.is_filterable: false → true` and runs
     * the same retype lifecycle so the field's data moves to an
     * indexed slot column. The new slot reservation requires a
     * page where the target `slot_column` carries the composite
     * `(tenant_id, slot_column)` index (filterable slot per
     * PageProvisioner); if none is free the reservation defers and
     * the work source retries on each tick until the Watcher
     * provisions a suitable page.
     *
     * Filterability remains suppressed (`JSON_EXTRACT` fallback)
     * for the entire backfill window; filter queries against the
     * field throw {@see \StarDust\Exception\FieldNotIndexedException}
     * while the slot is `backfilling` (Phase 4 read-path rule).
     */
    public function promoteFieldToFilterable(int $tenantId, int $fieldId): void
    {
        TenantId::assertValid($tenantId);
        $this->retypeInitiator()->initiate(
            tenantId: $tenantId,
            fieldId: $fieldId,
            newDeclaredType: null,
            newIsFilterable: true,
        );
    }

    /**
     * Phase 5 page-provisioning daemon (singleton). Construct + run via
     * `pollLoop()` from a CLI process; the {@see Watcher} expects a
     * process-level PID-file guard to already be held (handled by the
     * `bin/stardust watcher` entry point).
     */
    public function watcher(): Watcher
    {
        return new Watcher(
            pdo: $this->config->pdo,
            clock: $this->config->clock,
            logger: $this->config->logger,
            capacityReporter: new CapacityReporter($this->config->pdo),
            pageProvisioner: new PageProvisioner(
                pdo: $this->config->pdo,
                clock: $this->config->clock,
                logger: $this->config->logger,
            ),
            cardinalitySampler: new CardinalitySampler(
                pdo: $this->config->pdo,
                logger: $this->config->logger,
                selectivityThreshold: $this->config->cardinalitySelectivityThreshold,
                rowFloor: $this->config->cardinalityRowFloor,
                distinctFloor: $this->config->cardinalityDistinctFloor,
            ),
            capacityThreshold: $this->config->watcherCapacityThreshold,
            cardinalityIntervalSeconds: $this->config->cardinalityIntervalSeconds,
            provisionLockTimeoutSeconds: $this->config->watcherProvisionLockTimeoutSeconds,
        );
    }

    /**
     * Phase 5 reconciliation daemon (multi-worker safe). Drains
     * `stardust_sync_queue` and `stardust_import_jobs` via two
     * {@see \StarDust\Reconciler\ReconcilerWorkSource} implementations
     * ticked round-robin under one chunk correlation id per tick.
     */
    public function reconciler(): Reconciler
    {
        $dlqWriter = new DlqWriter(
            pdo: $this->config->pdo,
            clock: $this->config->clock,
            logger: $this->config->logger,
        );

        $syncQueue = new SyncQueueWorkSource(
            pdo: $this->config->pdo,
            logger: $this->config->logger,
            backfillExecutor: $this->backfillExecutor(),
            dlqWriter: $dlqWriter,
            chunkSize: $this->config->reconcilerChunkSize,
        );

        $importJobs = new ImportJobWorkSource(
            pdo: $this->config->pdo,
            clock: $this->config->clock,
            logger: $this->config->logger,
            entryWriter: $this->entryWriter(),
            dlqWriter: $dlqWriter,
            artifactDir: $this->config->artifactDir,
            chunkSize: $this->config->reconcilerChunkSize,
            interChunkDelayMicros: $this->config->reconcilerInterChunkDelayMicros,
        );

        $retypeBackfill = new RetypeBackfillWorkSource(
            pdo: $this->config->pdo,
            clock: $this->config->clock,
            logger: $this->config->logger,
            repository: new RetypeCheckpointRepository($this->config->pdo),
            executor: new RetypeBackfillExecutor(
                pdo: $this->config->pdo,
                slotRowUpserter: $this->slotRowUpserter(),
            ),
            slotReserver: $this->slotReserver(),
            cardinalitySampler: $this->cardinalitySampler(),
            chunkSize: $this->config->reconcilerChunkSize,
        );

        return new Reconciler(
            workSources: [$syncQueue, $importJobs, $retypeBackfill],
            capacityWaitMillis: $this->config->reconcilerCapacityWaitMillis,
            interChunkDelayMicros: $this->config->reconcilerInterChunkDelayMicros,
        );
    }

    /**
     * Phase 7 async export submission (ADR 0010).
     *
     * Validates the request, enforces the per-tenant active-job cap
     * (≤ `Config::$chroniclerPerTenantActiveCap`, default 3) via a
     * `SELECT … FOR UPDATE` + `INSERT` transaction, persists a
     * `pending` `stardust_export_jobs` row, and returns its id wrapped
     * in {@see ExportJobId}. A `bin/stardust chronicler` worker will
     * claim and drain the job; this method only persists.
     *
     * Throws {@see \StarDust\Exception\InvalidTenantIdException} for
     * an out-of-range `tenant_id` and
     * {@see \StarDust\Exception\ExportJobActiveCapExceededException}
     * when the tenant already has `cap` active jobs.
     */
    public function submitExport(ExportJobRequest $request): ExportJobId
    {
        TenantId::assertValid($request->tenantId);
        return $this->exportSubmitter()->submit($request);
    }

    /**
     * Phase 7 consumer-side status read. Loads a single
     * `stardust_export_jobs` row by `(tenant_id, job_id)`. Returns
     * `null` when the job does not exist OR belongs to a different
     * tenant — tenant isolation is enforced by the `WHERE` clause,
     * mirroring {@see \StarDust\Read\EntryReader::get()}.
     */
    public function getExportJob(int $tenantId, int $jobId): ?ExportJob
    {
        TenantId::assertValid($tenantId);
        return $this->exportSubmitter()->getJob($tenantId, $jobId);
    }

    /**
     * Phase 7 async export daemon (multi-worker). Polls
     * `stardust_export_jobs` for pending or abandoned claims under
     * `SELECT … FOR UPDATE SKIP LOCKED`, paginates `entry_data` for
     * the matched `(tenant_id, model_id)`, streams CSV / JSON
     * artifacts to disk under `Config::$artifactDir`, and refreshes
     * the lease on every chunk-commit. Idle ticks run the artifact
     * GC sweep (TTL'd completed jobs + orphaned failed-job partials).
     *
     * Multi-worker safe (no PID guard); horizontal scaling = more
     * processes. Failure semantics per ADR 0025: deadlock retry
     * budget (3), skip cap (1 000), artifact size cap (5 GB),
     * fixed DB-disconnect backoff `[1, 4, 16]`.
     */
    public function chronicler(): Chronicler
    {
        $streamFactory = new ArtifactStreamFactory($this->config->artifactDir);

        $processor = new ExportJobProcessor(
            pdo: $this->config->pdo,
            clock: $this->config->clock,
            logger: $this->config->logger,
            pager: new EntryDataPager($this->config->pdo),
            headerResolver: new HeaderResolver($this->config->pdo),
            streamFactory: $streamFactory,
            pageSize: $this->config->chroniclerPageSize,
            interChunkDelayMicros: $this->config->chroniclerInterChunkDelayMicros,
            deadlockRetryBudget: $this->config->chroniclerDeadlockRetryBudget,
            skipCountCap: $this->config->chroniclerSkipCountCap,
            artifactSizeCapBytes: $this->config->chroniclerArtifactSizeCapBytes,
            dbDisconnectBackoffSeconds: $this->config->chroniclerDbDisconnectBackoffSeconds,
        );

        return new Chronicler(
            logger: $this->config->logger,
            claimer: new ExportJobClaimer(
                pdo: $this->config->pdo,
                clock: $this->config->clock,
                leaseTimeoutSeconds: $this->config->chroniclerLeaseTimeoutSeconds,
            ),
            processor: $processor,
            diskGate: new DiskPressureGate(
                artifactDir: $this->config->artifactDir,
                lowDiskThresholdPct: $this->config->chroniclerLowDiskThresholdPct,
            ),
            gcSweeper: new GcSweeper(
                pdo: $this->config->pdo,
                logger: $this->config->logger,
                artifactTtlSeconds: $this->config->chroniclerArtifactTtlSeconds,
                orphanedPartialTtlSeconds: $this->config->chroniclerOrphanedPartialTtlSeconds,
            ),
        );
    }

    /**
     * Phase 6a slot-reclamation daemon (singleton). Polls
     * `stardust_slot_assignments` for `status='tombstoned'` rows and
     * sweeps each via chunked nullification of the slot column on
     * `entry_slots_page_X`; on the final chunk of a slot, transitions
     * `tombstoned → free` and bumps `stardust_schema_version` in the
     * same transaction (ADR 0009, ADR 0017 §4.6).
     *
     * Singleton enforcement is the CLI's job
     * ({@see \StarDust\Daemon\PidFileGuard} with
     * `LiberatorSingletonViolationException::class`); this factory
     * assumes it.
     */
    public function liberator(): Liberator
    {
        return new Liberator(
            logger: $this->config->logger,
            repository: new TombstonedSlotRepository(
                pdo: $this->config->pdo,
                batchSize: $this->config->liberatorBatchSize,
            ),
            sweeper: new SlotSweeper(
                pdo: $this->config->pdo,
                logger: $this->config->logger,
                chunkSize: $this->config->liberatorChunkSize,
                interChunkDelayMicros: $this->config->liberatorInterChunkDelayMicros,
                deadlockRetryBudget: $this->config->liberatorDeadlockRetryBudget,
            ),
        );
    }

    /**
     * Phase 5 operator-initiated DLQ replay. The CLI invokes
     * `replayById()` or `replayByReason()` from
     * `bin/stardust reconciler:dlq:replay`.
     */
    public function dlqReplayer(): DlqReplayer
    {
        return new DlqReplayer(
            pdo: $this->config->pdo,
            clock: $this->config->clock,
        );
    }

    /**
     * Persistent poll loop shared by every Phase 5 daemon. Re-injected
     * across daemon CLI invocations so the sleep slice (1 s) and
     * shutdown polling logic stay in one place.
     */
    public function pollLoop(): PollLoop
    {
        return $this->pollLoop ??= new PollLoop();
    }

    /**
     * Composite shutdown signal: POSIX SIGTERM/SIGINT (when ext-pcntl
     * is loaded) OR a `<pidFileDir>/<daemonName>.shutdown` flag file.
     * The CLI passes `$daemonName` per command.
     */
    public function shutdownSignal(string $daemonName): ShutdownSignal
    {
        return new CompositeShutdownSignal(
            new SignalShutdownSignal(),
            new FlagFileShutdownSignal($this->config->pidFileDir, $daemonName),
        );
    }

    private function entryWriter(): EntryWriter
    {
        return $this->entryWriter ??= new EntryWriter(
            pdo: $this->config->pdo,
            clock: $this->config->clock,
            logger: $this->config->logger,
            slotRowUpserter: $this->slotRowUpserter(),
        );
    }

    private function slotRowUpserter(): SlotRowUpserter
    {
        return $this->slotRowUpserter ??= new SlotRowUpserter($this->config->pdo);
    }

    private function backfillExecutor(): BackfillExecutor
    {
        return $this->backfillExecutor ??= new BackfillExecutor(
            pdo: $this->config->pdo,
            slotRowUpserter: $this->slotRowUpserter(),
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

    private function entryReader(): EntryReader
    {
        return $this->entryReader ??= new EntryReader(
            pdo: $this->config->pdo,
            logger: $this->config->logger,
        );
    }

    private function slotReserver(): SlotReserver
    {
        return $this->slotReserver ??= new SlotReserver(
            pdo: $this->config->pdo,
            clock: $this->config->clock,
            logger: $this->config->logger,
        );
    }

    private function cardinalitySampler(): CardinalitySampler
    {
        return $this->cardinalitySampler ??= new CardinalitySampler(
            pdo: $this->config->pdo,
            logger: $this->config->logger,
            selectivityThreshold: $this->config->cardinalitySelectivityThreshold,
            rowFloor: $this->config->cardinalityRowFloor,
            distinctFloor: $this->config->cardinalityDistinctFloor,
        );
    }

    private function retypeInitiator(): RetypeInitiator
    {
        return $this->retypeInitiator ??= new RetypeInitiator(
            pdo: $this->config->pdo,
            clock: $this->config->clock,
            logger: $this->config->logger,
            slotReserver: $this->slotReserver(),
            checkpointRepository: new RetypeCheckpointRepository($this->config->pdo),
        );
    }

    private function exportSubmitter(): ExportJobSubmitter
    {
        return $this->exportSubmitter ??= new ExportJobSubmitter(
            pdo: $this->config->pdo,
            clock: $this->config->clock,
            logger: $this->config->logger,
            perTenantActiveCap: $this->config->chroniclerPerTenantActiveCap,
        );
    }
}
