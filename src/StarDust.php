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
use StarDust\Compaction\CompactionPlan;
use StarDust\Compaction\CompactionRepository;
use StarDust\Compaction\CompactionService;
use StarDust\Exception\CompactionCapacityException;
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
use StarDust\Read\SchemaVersionCache;
use StarDust\Delete\DeleteCheckpointRepository;
use StarDust\Delete\DeleteFieldInitiator;
use StarDust\Delete\DeletePurgeExecutor;
use StarDust\Delete\DeleteModelInitiator;
use StarDust\Delete\DeletePurgeWorkSource;
use StarDust\Delete\ModelDeleteCheckpointRepository;
use StarDust\Delete\ModelPurgeExecutor;
use StarDust\Delete\ModelPurgeWorkSource;
use StarDust\Reconciler\DlqReplayer;
use StarDust\Reconciler\DlqWriter;
use StarDust\Reconciler\ImportJobWorkSource;
use StarDust\Reconciler\Reconciler;
use StarDust\Reconciler\SyncQueueWorkSource;
use StarDust\Reconciler\UnmappedFieldReserver;
use StarDust\Retype\RetypeBackfillExecutor;
use StarDust\Rename\RenameBackfillExecutor;
use StarDust\Rename\RenameBackfillWorkSource;
use StarDust\Rename\ModelRenamer;
use StarDust\Rename\RenameCheckpointRepository;
use StarDust\Rename\RenameInitiator;
use StarDust\Retype\RetypeBackfillWorkSource;
use StarDust\Retype\RetypeCheckpointRepository;
use StarDust\Retype\RetypeInitiator;
use StarDust\Schema\ModelDescription;
use StarDust\Schema\ModelSummary;
use StarDust\Schema\SchemaBuilder;
use StarDust\Schema\SchemaReader;
use StarDust\Search\EntrySearchInterface;
use StarDust\Search\Mysql\MysqlNativeDriver;
use StarDust\Search\PreFlight\CapabilityChecker;
use StarDust\Search\PreFlight\FieldRefResolver;
use StarDust\Search\PreFlight\PreFlightPipeline;
use StarDust\Search\PreFlight\SortValidator;
use StarDust\Search\PreFlight\ValueTypeValidator;
use StarDust\Search\SearchRequest;
use StarDust\Search\SearchResult;
use StarDust\Search\SearchService;
use StarDust\Slot\IndexedFreeCapacityReader;
use StarDust\Slot\LiveSlotTombstoner;
use StarDust\Slot\SlotReserver;
use StarDust\Watcher\CapacityReporter;
use StarDust\Watcher\CardinalitySampler;
use StarDust\Watcher\FlatIndexHeadroom;
use StarDust\Watcher\PendingDemandReader;
use StarDust\Watcher\SpreadSampler;
use StarDust\Watcher\Watcher;
use StarDust\Write\BackfillExecutor;
use StarDust\Write\BulkIngestOptions;
use StarDust\Write\BulkIngestResult;
use StarDust\Write\BulkIngestSubmitter;
use StarDust\Write\BulkIngestor;
use StarDust\Write\EntryDeleter;
use StarDust\Write\EntryPayload;
use StarDust\Write\EntryWriteResult;
use StarDust\Write\EntryWriter;
use StarDust\Write\ImportJob;
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
    private ?EntryDeleter $entryDeleter = null;
    private ?BulkIngestor $bulkIngestor = null;
    private ?BulkIngestSubmitter $bulkSubmitter = null;
    private ?SearchService $searchService = null;
    private ?EntrySearchInterface $resolvedSearchDriver = null;
    private ?SchemaVersionCache $schemaVersionCache = null;
    private ?SlotRowUpserter $slotRowUpserter = null;
    private ?BackfillExecutor $backfillExecutor = null;
    private ?PollLoop $pollLoop = null;
    private ?SlotReserver $slotReserver = null;
    private ?LiveSlotTombstoner $liveSlotTombstoner = null;
    private ?CardinalitySampler $cardinalitySampler = null;
    private ?SpreadSampler $spreadSampler = null;
    private ?RetypeInitiator $retypeInitiator = null;
    private ?RenameInitiator $renameInitiator = null;
    private ?ModelRenamer $modelRenamer = null;
    private ?DeleteFieldInitiator $deleteFieldInitiator = null;
    private ?DeleteModelInitiator $deleteModelInitiator = null;
    private ?CompactionService $compactionService = null;
    private ?ExportJobSubmitter $exportSubmitter = null;
    private ?SchemaBuilder $schemaBuilder = null;
    private ?SchemaReader $schemaReader = null;

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
     * Convenience helper for registering models and fields without
     * hand-writing `stardust_models` / `stardust_fields` SQL.
     *
     * This is a stopgap for ergonomic setup, not the first-class
     * definition API (still on the roadmap). It registers registry
     * rows only — making a filterable field genuinely queryable still
     * requires a provisioned page and a reserved slot (the Watcher
     * daemon, or {@see \StarDust\Page\PageProvisioner} +
     * {@see \StarDust\Slot\SlotReserver} for one-off setup).
     */
    public function schemaBuilder(): SchemaBuilder
    {
        return $this->schemaBuilder ??= new SchemaBuilder(
            pdo: $this->config->pdo,
            clock: $this->config->clock,
            logger: $this->config->logger,
        );
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
     * Consumer-side status read for an async bulk import: resolves the
     * {@see ImportJobId} returned by {@see self::submitBulkWrite()}
     * back to a full {@see ImportJob}. Returns `null` when the job
     * does not exist OR belongs to a different tenant — tenant
     * isolation is enforced by the `WHERE` clause, mirroring
     * {@see self::getExportJob()}.
     *
     * `ImportJob::$entriesWritten` against `$entryCount` is the
     * progress fraction; on a `failed` job the former is the replay
     * boundary, since the manifest is checkpointed inside each chunk
     * transaction and the failure path does not overwrite it. Both it
     * and `$chunks` are `null` until the first chunk commits.
     */
    public function getImportJob(int $tenantId, int $jobId): ?ImportJob
    {
        TenantId::assertValid($tenantId);
        return $this->bulkSubmitter()->getJob($tenantId, $jobId);
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
        $request = SearchRequest::fromEntryQuery($query);
        return $this->searchService()->execute($request)->toEntryPage();
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
        return $this->searchDriver()->get($tenantId, $entryId);
    }

    /**
     * Phase 8 driver-backed search entry point.
     *
     * Runs the pre-flight pipeline (field-ref resolution, capability
     * check, value-type validation) and dispatches to the active
     * {@see EntrySearchInterface}. The active driver defaults to
     * {@see MysqlNativeDriver}; inject a custom driver via
     * {@see Config::$searchDriver} to swap backends without modifying
     * call-site code.
     *
     * Throws the Phase 4 exceptions for shared cases
     * ({@see \StarDust\Exception\UnknownFieldException},
     * {@see \StarDust\Exception\FieldNotFilterableException},
     * {@see \StarDust\Exception\PageSizeOutOfRangeException}) plus
     * {@see \StarDust\Filter\QueryFilterValidationException} for the
     * Phase 8 wire-format codes that have no Phase 4 equivalent.
     */
    public function search(SearchRequest $request): SearchResult
    {
        TenantId::assertValid($request->tenantId);
        return $this->searchService()->execute($request);
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
     * The inverse of {@see self::promoteFieldToFilterable()}: flips
     * `stardust_fields.is_filterable: true → false`.
     *
     * Cheaper than promotion, and asymmetric with it by design. A
     * demoted field becomes JSON-only (ADR 0034), so there is no data
     * to move and no backfill window — the lifecycle tombstones the
     * field's slot and returns immediately, leaving the Liberator to
     * reclaim the column on its own schedule. The value itself is never
     * at risk: `entry_data.fields` has been the system of record all
     * along, and the read path falls back to it the moment the slot
     * stops being live.
     *
     * Filters against the field are rejected from this point on with
     * {@see \StarDust\Exception\FieldNotFilterableException}.
     */
    public function demoteFieldFromFilterable(int $tenantId, int $fieldId): void
    {
        TenantId::assertValid($tenantId);
        $this->retypeInitiator()->initiate(
            tenantId: $tenantId,
            fieldId: $fieldId,
            newDeclaredType: null,
            newIsFilterable: false,
        );
    }

    /**
     * Rename a field. Returns as soon as the registry is updated; the
     * payload catch-up is asynchronous and **needs a running
     * `bin/stardust reconciler`** to finish.
     *
     * `entry_data.fields` is keyed by field name, so a rename is a
     * rewrite of every entry in the model rather than a registry write
     * (ADR 0036). This call flips the name, records the old one, and
     * queues the rewrite; the Reconciler drains it in chunks.
     *
     * **What is true during the drain:**
     *
     * - Reads return the value under the **new** name for every row,
     *   migrated or not — the read path falls back to the old key.
     * - Writes may use either name; a payload still carrying the old one
     *   is rewritten to the new before it is persisted, so a client that
     *   has not yet redeployed loses nothing.
     * - Filters on the **new** name work immediately (a rename never
     *   touches the slot). Filters on the old name are rejected with
     *   {@see \StarDust\Exception\UnknownFieldException} — deliberately,
     *   since a rejected query loses nothing while a rejected write
     *   would lose data.
     * - The field cannot be retyped, promoted, demoted, or relocated by
     *   {@see self::compactModel()} until the rename completes.
     *
     * Renaming a field to its current name is a no-op. Idempotent to
     * re-issue after a previous rename has completed.
     *
     * @throws \StarDust\Exception\FieldNotFoundException      unknown field, or another tenant's
     * @throws \StarDust\Exception\FieldNameConflictException  the name is taken by a sibling field,
     *                                                        including one whose own rename is
     *                                                        still draining
     * @throws \StarDust\Exception\RenameInProgressException   this field is already being renamed
     * @throws \StarDust\Exception\RetypeInProgressException   this field has a retype in flight
     */
    public function renameField(int $tenantId, int $fieldId, string $newName): void
    {
        TenantId::assertValid($tenantId);
        $this->renameInitiator()->initiate($tenantId, $fieldId, $newName);
    }

    /**
     * Rename a model. Synchronous and complete on return — unlike
     * {@see self::renameField()}, this needs no running Reconciler.
     *
     * A model's name is a label, not an identity: entries, slots,
     * exports and filters all reference `model_id`, and no cache holds
     * the name. So the rename is one UPDATE with no backfill, no window
     * during which reads and writes disagree, and nothing to wait for.
     * Renaming a model to its current name is a no-op.
     *
     * **One caveat worth knowing.** `schemaBuilder()`'s `createModel()`
     * and `defineModel()` are get-or-create keyed on `(tenant_id, name)`,
     * so a setup or seed script still naming the *old* model will not
     * find it and will create a **second** model instead. Update such
     * scripts in step with the rename.
     *
     * @throws \StarDust\Exception\ModelNotFoundException      unknown model, or another tenant's
     * @throws \StarDust\Exception\ModelNameConflictException  the name is already used by another
     *                                                        model in this tenant
     */
    public function renameModel(int $tenantId, int $modelId, string $newName): void
    {
        TenantId::assertValid($tenantId);
        $this->modelRenamer()->rename($tenantId, $modelId, $newName);
    }

    /**
     * Delete a field. Returns as soon as the registry is updated; the
     * removal of the field's values from stored entries is asynchronous
     * and **needs a running Reconciler** (`bin/stardust reconciler`).
     *
     * The field is gone from every first-class surface the moment this
     * returns: `read()`, `search()`, `get()` and `describeModel()` stop
     * reporting it, filters against it raise `UnknownFieldException`,
     * new CSV exports omit its column, and writes still sending its name
     * silently drop the value. Any index slot it held is released for
     * reuse on the Liberator's own schedule.
     *
     * What lags is the stored data. Each entry's JSON payload is keyed
     * by field name, so removing a field is a rewrite of every entry in
     * the model rather than a registry update. Until the Reconciler
     * finishes that pass the values are still physically present in
     * `entry_data` — invisible through the API, but visible in a raw
     * table dump and in the JSON artifact of an export that runs during
     * the window. The field's registry row is deleted last, as the final
     * step of the rewrite, which is the signal that the deletion is
     * complete.
     *
     * **The field's name is not reusable until then.** Registering a new
     * field with the same name on the same model raises
     * {@see \StarDust\Exception\FieldDeletionInProgressException} rather
     * than silently adopting the old one.
     *
     * A field cannot be deleted while it is being renamed or retyped —
     * finish or fail that first. Conversely, once deletion starts, the
     * field cannot be renamed, retyped, promoted, demoted, or compacted.
     *
     * There is no undelete.
     *
     * @return bool `true` iff this call initiated a deletion; `false`
     *              when there was nothing to do — the field does not
     *              exist, belongs to another tenant, or is already being
     *              deleted. Deliberately indistinguishable, and
     *              deliberately not an exception: a repeated delete has
     *              already achieved what the caller wanted. Matches
     *              {@see self::deleteEntry()}.
     *
     * @throws \StarDust\Exception\InvalidTenantIdException
     * @throws \StarDust\Exception\RenameInProgressException a rename backfill is running
     * @throws \StarDust\Exception\RetypeInProgressException a retype backfill is running
     */
    public function deleteField(int $tenantId, int $fieldId): bool
    {
        TenantId::assertValid($tenantId);
        return $this->deleteFieldInitiator()->initiate($tenantId, $fieldId);
    }

    /**
     * The ADR 0038 deletion entry point: remove a model, its fields, and
     * every entry belonging to it.
     *
     * **This returns before anything has been deleted, and it needs a
     * running Reconciler to finish.** The call commits one registry
     * transaction that severs the model from every first-class surface,
     * then returns. The Reconciler destroys the entries in bounded chunks
     * and its final chunk drops the `stardust_models` row, cascading the
     * field rows away with it. Without a Reconciler the model stays
     * permanently severed-but-unpurged — invisible through the API, still
     * occupying the largest table in the engine. That is a safe resting
     * state rather than a corrupt one, and re-issuing the delete resumes
     * rather than crashes, but it is not finished.
     *
     * From the moment this returns:
     *
     * - `listModels()` and `describeModel()` omit the model.
     * - `read()`, `search()` and `get()` go **dark** — an empty page and
     *   `null` respectively, indistinguishable from a model that never
     *   existed.
     * - `write()`, `updateEntry()`, `bulkWrite()` and `submitBulkWrite()`
     *   **throw** `ModelDeletionInProgressException`. This is the one
     *   place the engine rejects a write rather than accepting it: a row
     *   created here would either be destroyed seconds later or become a
     *   permanent orphan, so rejection costs the caller nothing real.
     * - `deleteEntry()` returns `false` — soft-deleting a row about to be
     *   hard-deleted achieves nothing.
     * - `compactModel()` and `submitExport()` are refused.
     * - The model's **name is not reusable** until the purge lands;
     *   `defineModel()` raises `ModelDeletionInProgressException`.
     *
     * Not bridged, deliberately: an export job already claimed by the
     * Chronicler produces a zero-column artifact, dead-letter rows naming
     * the model outlive it, and a raw `entry_data` dump shows the rows
     * that have not been reached yet.
     *
     * **There is no undelete.** Entries, extension rows, sync-queue rows,
     * field definitions and the model itself are all destroyed, and
     * nothing in the engine retains a copy. Export before you call this.
     *
     * Returns `false` — rather than throwing — when there is nothing to
     * do: the model does not exist, belongs to another tenant, or is
     * already being deleted. Matching `deleteField()` and `deleteEntry()`,
     * and the tenant-isolation rule that a caller must not be able to
     * probe another tenant's ids. A typo in a model id is therefore
     * silent.
     *
     * @throws \StarDust\Exception\InvalidTenantIdException
     * @throws \StarDust\Exception\RenameInProgressException        a field of this model is mid-rename
     * @throws \StarDust\Exception\RetypeInProgressException        a field of this model is mid-retype
     * @throws \StarDust\Exception\FieldDeletionInProgressException a field of this model is mid-delete
     */
    public function deleteModel(int $tenantId, int $modelId): bool
    {
        TenantId::assertValid($tenantId);
        return $this->deleteModelInitiator()->initiate($tenantId, $modelId);
    }

    /**
     * Every model registered for this tenant.
     *
     * Read-only, lock-free, and safe to call per request.
     *
     * @return list<ModelSummary>
     */
    public function listModels(int $tenantId): array
    {
        TenantId::assertValid($tenantId);
        return $this->schemaReader()->listModels($tenantId);
    }

    /**
     * One model and its fields, or `null` when no such model exists
     * for this tenant.
     *
     * Each returned field reports both `isFilterable` (the registry's
     * declared intent) and `isIndexed` (whether a filter against it
     * will work *right now*). They differ throughout a promotion or
     * retype backfill, and while a new filterable field waits on
     * capacity — so a UI offering "filter by this field" should gate on
     * `isIndexed`. {@see ModelDescription::indexedFields()} is the
     * shorthand.
     */
    public function describeModel(int $tenantId, int $modelId): ?ModelDescription
    {
        TenantId::assertValid($tenantId);
        return $this->schemaReader()->describeModel($tenantId, $modelId);
    }

    private function schemaReader(): SchemaReader
    {
        return $this->schemaReader ??= new SchemaReader($this->config->pdo);
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
            pendingDemandReader: new PendingDemandReader($this->config->pdo),
            pageProvisioner: new PageProvisioner(
                pdo: $this->config->pdo,
                clock: $this->config->clock,
                logger: $this->config->logger,
            ),
            cardinalitySampler: $this->cardinalitySampler(),
            spreadSampler: $this->spreadSampler(),
            headroomPolicy: new FlatIndexHeadroom($this->config->pageIndexHeadroom),
            capacityThreshold: $this->config->watcherCapacityThreshold,
            cardinalityIntervalSeconds: $this->config->cardinalityIntervalSeconds,
            cardinalityJitterSeconds: $this->config->cardinalityJitterSeconds,
            provisionLockTimeoutSeconds: $this->config->watcherProvisionLockTimeoutSeconds,
        );
    }

    /**
     * Phase 5 reconciliation daemon (multi-worker safe). Drains
     * `stardust_sync_queue`, `stardust_import_jobs`, retype backfills,
     * ADR 0036 rename rewrites, ADR 0037 field-deletion purges and ADR
     * 0038 model-deletion purges via six
     * {@see \StarDust\Reconciler\ReconcilerWorkSource} implementations
     * ticked round-robin under one chunk correlation id per tick.
     *
     * The order is observable in event streams, so **new sources append
     * rather than insert**. Ordering between the three field-backfill
     * sources is immaterial: each field lifecycle refuses to start while
     * another is running, so no two can ever contend for the same field.
     * The model purge is last on purpose — it and the sync-queue drain
     * both touch `stardust_sync_queue`.
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
            unmappedFieldReserver: new UnmappedFieldReserver(
                pdo: $this->config->pdo,
                slotReserver: $this->slotReserver(),
            ),
            chunkSize: $this->config->reconcilerChunkSize,
            lockRetryBudget: $this->config->reconcilerLockRetryBudget,
            retryDelayMicros: $this->config->reconcilerLockRetryDelayMicros,
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
            leaseTimeoutSeconds: $this->config->reconcilerImportLeaseTimeoutSeconds,
            lockRetryBudget: $this->config->reconcilerLockRetryBudget,
            lockRetryDelayMicros: $this->config->reconcilerLockRetryDelayMicros,
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
            spreadSampler: $this->spreadSampler(),
            chunkSize: $this->config->reconcilerChunkSize,
            lockRetryBudget: $this->config->reconcilerLockRetryBudget,
            retryDelayMicros: $this->config->reconcilerLockRetryDelayMicros,
        );

        // Appended as the fourth source rather than inserted: the
        // existing round-robin order is observable in event streams, and
        // ordering between the two backfill sources is immaterial since
        // the cross-guards make them mutually exclusive per field.
        $renameBackfill = new RenameBackfillWorkSource(
            pdo: $this->config->pdo,
            clock: $this->config->clock,
            logger: $this->config->logger,
            repository: new RenameCheckpointRepository($this->config->pdo),
            executor: new RenameBackfillExecutor(pdo: $this->config->pdo),
            chunkSize: $this->config->reconcilerChunkSize,
            lockRetryBudget: $this->config->reconcilerLockRetryBudget,
            retryDelayMicros: $this->config->reconcilerLockRetryDelayMicros,
        );

        // Fifth, appended for the same reason the fourth was: the
        // round-robin order is observable in event streams, and the
        // backfill sources are mutually exclusive per field anyway
        // because each lifecycle refuses to start while another runs.
        $deletePurge = new DeletePurgeWorkSource(
            pdo: $this->config->pdo,
            clock: $this->config->clock,
            logger: $this->config->logger,
            repository: new DeleteCheckpointRepository($this->config->pdo),
            executor: new DeletePurgeExecutor(pdo: $this->config->pdo),
            chunkSize: $this->config->reconcilerChunkSize,
            lockRetryBudget: $this->config->reconcilerLockRetryBudget,
            retryDelayMicros: $this->config->reconcilerLockRetryDelayMicros,
        );

        // Sixth, appended. Last in the tick on purpose: it and the
        // sync-queue drain both touch `stardust_sync_queue`, so putting
        // the purge after the drain gives the drain its turn first.
        $modelPurge = new ModelPurgeWorkSource(
            pdo: $this->config->pdo,
            clock: $this->config->clock,
            logger: $this->config->logger,
            repository: new ModelDeleteCheckpointRepository($this->config->pdo),
            executor: new ModelPurgeExecutor(pdo: $this->config->pdo),
            chunkSize: $this->config->modelPurgeChunkSize,
            lockRetryBudget: $this->config->modelPurgeLockRetryBudget,
            retryDelayMicros: $this->config->reconcilerInterChunkDelayMicros,
        );

        return new Reconciler(
            workSources: [
                $syncQueue,
                $importJobs,
                $retypeBackfill,
                $renameBackfill,
                $deletePurge,
                $modelPurge,
            ],
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
            connector: $this->config->pdoConnector,
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

    private function searchService(): SearchService
    {
        return $this->searchService ??= new SearchService(
            driver:    $this->searchDriver(),
            cache:     $this->schemaVersionCache(),
            preFlight: new PreFlightPipeline(
                fieldRefResolver:   new FieldRefResolver($this->config->logger),
                capabilityChecker:  new CapabilityChecker($this->config->logger),
                valueTypeValidator: new ValueTypeValidator($this->config->logger, $this->config->queryFilterLimits),
                sortValidator:      new SortValidator($this->config->logger),
            ),
            logger:    $this->config->logger,
            clock:     $this->config->clock,
        );
    }

    private function searchDriver(): EntrySearchInterface
    {
        return $this->resolvedSearchDriver ??= $this->config->searchDriver
            ?? new MysqlNativeDriver(
                pdo:    $this->config->pdo,
                logger: $this->config->logger,
                cache:  $this->schemaVersionCache(),
            );
    }

    private function schemaVersionCache(): SchemaVersionCache
    {
        return $this->schemaVersionCache ??= new SchemaVersionCache(
            $this->config->pdo,
            $this->config->logger,
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

    /**
     * The ADR 0031 spread advisory. Shared by the Watcher (periodic
     * trigger), the retype work source (post-relocation one-shot), and
     * `bin/stardust spread:report` (on demand).
     */
    public function spreadSampler(): SpreadSampler
    {
        return $this->spreadSampler ??= new SpreadSampler(
            pdo: $this->config->pdo,
            logger: $this->config->logger,
            excessPageThreshold: $this->config->spreadExcessPageThreshold,
            capacityReader: new IndexedFreeCapacityReader($this->config->pdo),
        );
    }

    /**
     * ADR 0033 operator-initiated model compaction.
     *
     * Relocates the model's live filterable slots onto a minimal page
     * set and returns the plan that ran. `$dryRun` plans and returns
     * without mutating anything or emitting any event.
     *
     * **Long-running and operator-initiated.** It relocates one field at
     * a time and blocks until the Reconciler drains each, so it needs a
     * running `bin/stardust reconciler` to make progress. Never call it
     * from a request path.
     *
     * Re-running is always safe: already-relocated fields are no-ops, so
     * an interrupted operation converges on a second run.
     *
     * @throws CompactionCapacityException when no smaller page set can absorb
     *                                     the moves, before any mutation
     */
    public function compactModel(int $tenantId, int $modelId, bool $dryRun = false): CompactionPlan
    {
        TenantId::assertValid($tenantId);

        return $dryRun
            ? $this->compactionService()->plan($tenantId, $modelId)
            : $this->compactionService()->compact($tenantId, $modelId);
    }

    /**
     * Full-replace update of an existing entry.
     *
     * `$fields` becomes the entry's complete payload — this is PUT, not
     * PATCH. A field present on the entry but absent from `$fields` is
     * removed from the JSON payload *and* has its indexed slot column
     * cleared, so a filter can never match a value the entry no longer
     * carries.
     *
     * `model_id` is immutable: it is read from the existing row, and an
     * update can never move an entry between models. Coercion, the
     * ADR 0007 exhaustion fallback, and slot targeting all behave
     * exactly as they do on {@see self::write()} — an update that
     * introduces a filterable field with no live slot succeeds and
     * queues for backfill rather than failing.
     *
     * @param array<string, mixed> $fields
     *
     * @throws \StarDust\Exception\EntryNotFoundException when the entry
     *         does not exist, belongs to another tenant, or is already
     *         soft-deleted
     * @throws \StarDust\Exception\InvalidTenantIdException
     * @throws \StarDust\Exception\UncoercibleSlotValueException
     */
    public function updateEntry(int $tenantId, int $entryId, array $fields): EntryWriteResult
    {
        TenantId::assertValid($tenantId);
        return $this->entryWriter()->update($tenantId, $entryId, $fields);
    }

    /**
     * Soft-delete an entry by stamping `entry_data.deleted_at`.
     *
     * Every read surface already excludes soft-deleted rows, so the
     * entry disappears from {@see self::read()}, {@see self::get()},
     * {@see self::search()}, and export pagination as soon as this
     * commits. Slot columns are left in place — nothing can reach them
     * without joining through a live `entry_data` row.
     *
     * Idempotent: returns `false` rather than throwing when the entry
     * does not exist, belongs to another tenant, or was already
     * deleted. There is no hard delete and no restore.
     *
     * @return bool `true` iff this call performed the transition
     *
     * @throws \StarDust\Exception\InvalidTenantIdException
     */
    public function deleteEntry(int $tenantId, int $entryId): bool
    {
        TenantId::assertValid($tenantId);
        return $this->entryDeleter()->delete($tenantId, $entryId);
    }

    private function entryDeleter(): EntryDeleter
    {
        return $this->entryDeleter ??= new EntryDeleter(
            pdo: $this->config->pdo,
            clock: $this->config->clock,
            logger: $this->config->logger,
        );
    }

    private function compactionService(): CompactionService
    {
        return $this->compactionService ??= new CompactionService(
            repository: new CompactionRepository($this->config->pdo),
            retypeInitiator: $this->retypeInitiator(),
            checkpointRepository: new RetypeCheckpointRepository($this->config->pdo),
            logger: $this->config->logger,
            clock: $this->config->clock,
        );
    }

    private function retypeInitiator(): RetypeInitiator
    {
        return $this->retypeInitiator ??= new RetypeInitiator(
            pdo: $this->config->pdo,
            clock: $this->config->clock,
            logger: $this->config->logger,
            slotReserver: $this->slotReserver(),
            tombstoner: $this->liveSlotTombstoner(),
            checkpointRepository: new RetypeCheckpointRepository($this->config->pdo),
            renameCheckpointRepository: new RenameCheckpointRepository($this->config->pdo),
        );
    }

    private function renameInitiator(): RenameInitiator
    {
        return $this->renameInitiator ??= new RenameInitiator(
            pdo: $this->config->pdo,
            clock: $this->config->clock,
            logger: $this->config->logger,
            renameCheckpoints: new RenameCheckpointRepository($this->config->pdo),
            retypeCheckpoints: new RetypeCheckpointRepository($this->config->pdo),
        );
    }

    private function deleteFieldInitiator(): DeleteFieldInitiator
    {
        return $this->deleteFieldInitiator ??= new DeleteFieldInitiator(
            pdo: $this->config->pdo,
            clock: $this->config->clock,
            logger: $this->config->logger,
            tombstoner: $this->liveSlotTombstoner(),
            deleteCheckpoints: new DeleteCheckpointRepository($this->config->pdo),
            renameCheckpoints: new RenameCheckpointRepository($this->config->pdo),
            retypeCheckpoints: new RetypeCheckpointRepository($this->config->pdo),
        );
    }

    private function deleteModelInitiator(): DeleteModelInitiator
    {
        return $this->deleteModelInitiator ??= new DeleteModelInitiator(
            pdo: $this->config->pdo,
            clock: $this->config->clock,
            logger: $this->config->logger,
            tombstoner: $this->liveSlotTombstoner(),
            modelCheckpoints: new ModelDeleteCheckpointRepository($this->config->pdo),
        );
    }

    private function liveSlotTombstoner(): LiveSlotTombstoner
    {
        return $this->liveSlotTombstoner ??= new LiveSlotTombstoner($this->config->pdo);
    }

    private function modelRenamer(): ModelRenamer
    {
        return $this->modelRenamer ??= new ModelRenamer(
            pdo: $this->config->pdo,
            logger: $this->config->logger,
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
