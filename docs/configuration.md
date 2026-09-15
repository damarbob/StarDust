# Construction & schema bootstrap

Constructing the engine, bootstrapping its schema, reading the registry back, and every optional `Config` parameter the daemons are tuned with.

```php
use StarDust\Config\Config;
use StarDust\StarDust;

$pdo = new PDO('mysql:host=127.0.0.1;dbname=app', $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$engine = new StarDust(new Config(pdo: $pdo));

// $engine->logger() returns StarDust\Logging\StdoutNdjsonLogger
// (NDJSON to stdout) unless you inject your own
// PSR-3 logger via Config. Optional Config::$artifactDir overrides
// where async bulk-ingest payloads are persisted (defaults to
// sys_get_temp_dir() . '/stardust').

// Phase 1: idempotently provision every physical table the engine
// needs (data plane, schema registry, operational/coordination).
// Safe to call on an already-bootstrapped database.
$engine->bootstrap();
```

Phase 2's page provisioner and slot reserver remain internal classes (`StarDust\Page\PageProvisioner`, `StarDust\Slot\SlotReserver`); Phase 5's Watcher daemon (`bin/stardust watcher`) wires them automatically.

> ℹ️ **Defining models and fields.** Use `StarDust::schemaBuilder()` to register models and fields without hand-writing registry SQL. It's get-or-create (safe to re-run) and returns the ids you'll need:
>
> ```php
> use StarDust\Schema\FieldDefinition;
>
> $model = $engine->schemaBuilder()->createModel(tenantId: 1, name: 'company', fields: [
>     new FieldDefinition('name',      'string', isFilterable: true),
>     new FieldDefinition('employees', 'int',    isFilterable: true),
> ]);
> // $model->modelId, $model->fieldId('name')
> ```
>
> This is a stopgap, not the first-class definition API. Registering a field isn't enough to filter on it — its value has to reach an indexed slot column. The Watcher daemon provisions that capacity in a running deployment; for one-off setup, call `PageProvisioner` + `SlotReserver` (see the [Complete example](../README.md#complete-example)). Until a field has a reserved indexed slot it's still stored and point-readable from the JSON payload — just not on the indexed filter path.

To read the registry back — for a settings screen, a field picker, or anything else that has to render a tenant's schema — use the introspection pair rather than querying `stardust_fields` yourself:

```php
foreach ($engine->listModels(tenantId: 1) as $model) {
    echo "{$model->modelId}: {$model->name}\n";
}

// null when the model doesn't exist, or isn't this tenant's — the two
// are deliberately indistinguishable.
$company = $engine->describeModel(tenantId: 1, modelId: $modelId);

foreach ($company?->fields ?? [] as $field) {
    echo "{$field->name} ({$field->declaredType})"
       . ($field->isIndexed ? " — filterable now\n" : "\n");
}

// Only the fields a filter will actually accept today:
$company?->indexedFields();
```

Each field reports **two** flags, and the difference matters. `isFilterable` is the declared intent recorded in the registry; `isIndexed` is whether a filter against the field will work *right now*. They diverge for the whole of a promotion or retype backfill, and while a newly registered filterable field is still waiting on capacity. Build your filter UI against `isIndexed` and you will never offer a filter the engine rejects.

Phases 5, 6a, 7, model deletion, index headroom, and the combined tick add thirty-nine optional `Config` parameters for daemon tuning:

```php
$engine = new StarDust(new Config(
    pdo:                                 $pdo,
    watcherPollIntervalSeconds:          60,        // default
    watcherCapacityThreshold:            0.20,      // spare-capacity floor; a field waiting on an
                                                    // index provisions regardless of this
    watcherProvisionLockTimeoutSeconds:  10,        // GET_LOCK wait — production stays at 10
    cardinalityIntervalSeconds:          86_400,    // 24 h cadence
    cardinalityJitterSeconds:            8_640,     // randomized ± window around the cadence (de-correlates a fleet)
    cardinalitySelectivityThreshold:     0.01,
    cardinalityRowFloor:                 10_000,
    cardinalityDistinctFloor:            10,
    reconcilerChunkSize:                 500,       // SKIP LOCKED LIMIT N
    reconcilerInterChunkDelayMicros:     0,         // pace drain throughput (0 = no pacing)
    reconcilerCapacityWaitMillis:        5_000,     // sleep after a capacity_wait tick
    reconcilerImportLeaseTimeoutSeconds: 30,        // import-job abandoned-claim sweep threshold
    pidFileDir:                          '/var/run/stardust',  // watcher.pid, liberator.pid + *.shutdown flag files
    liberatorIdleIntervalSeconds:        10,        // poll interval when nothing is tombstoned
    liberatorBatchSize:                  50,        // max tombstoned slots per Liberator tick
    liberatorChunkSize:                  500,       // per-chunk LIMIT on the slot-column nullification
    liberatorInterChunkDelayMicros:      0,         // pace sweep throughput (0 = no pacing)
    liberatorDeadlockRetryBudget:        3,         // consecutive 40001 retries before sweep_gap path
    chroniclerIdleIntervalSeconds:       10,        // PollLoop sleep when no claim available
    chroniclerLeaseTimeoutSeconds:       30,        // abandoned-claim sweep threshold
    chroniclerPageSize:                  500,       // entry_data pagination chunk
    chroniclerInterChunkDelayMicros:     0,         // between-chunk pacing
    chroniclerDeadlockRetryBudget:       3,         // per-chunk 40001 retries before skip
    chroniclerSkipCountCap:              1_000,     // combined per-row + per-chunk skip cap
    chroniclerArtifactSizeCapBytes:      5 * 1024 * 1024 * 1024,  // 5 GB per-artifact cap
    chroniclerArtifactTtlSeconds:        86_400,    // 24 h GC TTL for completed artifacts
    chroniclerOrphanedPartialTtlSeconds: 3_600,     // 1 h GC TTL for failed-job partials
    chroniclerLowDiskThresholdPct:       0.10,      // pre-claim disk gate (0..1)
    chroniclerPerTenantActiveCap:        3,         // submission cap on pending+processing
    chroniclerDbDisconnectBackoffSeconds:[1, 4, 16],// fixed backoff schedule
    pdoConnector:                        null,      // reconnect factory for mid-export DB drops (CLI wires one automatically)
    spreadExcessPageThreshold:           2,         // avoidable pages before a model is flagged as over-spread
    modelPurgeChunkSize:                 200,       // entry_data deletes per model-purge transaction (own knob — see below)
    modelPurgeLockRetryBudget:           3,         // consecutive 1205/1213 retries before the purge rethrows
    reconcilerLockRetryBudget:           3,         // consecutive 1205/1213 retries on the other five work sources
    reconcilerLockRetryDelayMicros:      0,         // pace between those retries (0 = no pacing)
    pageIndexHeadroom:                   4,         // indexed columns per slot family on each new page —
                                                    // fixed when the page is created and never widened after
    tickBudgetSeconds:                   50,        // StarDust::tick() run length — see deployment.md
    tickBudgetMarginSeconds:             5,         // subtracted from max_execution_time when the SAPI reports one
));
```
