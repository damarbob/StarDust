# StarDust

**Schemaless dynamic fields, queried at native SQL index speed — no separate search cluster, no EAV join swamp.**

Give every tenant their own fields, then filter them like first-class columns:

```php
// "industry" and "employees" are user-defined fields, not table columns —
// yet this compiles to an indexed range scan, not a table-scan-and-pray.
$page = $engine->read(new EntryQuery(
    tenantId: 1,
    modelId:  $companyModelId,
    filter:   new AndNode([
        LeafNode::local('industry',  'eq', 'software'),
        LeafNode::local('employees', 'gt', 100),
    ]),
    selectFields: ['name', 'employees'],
));

foreach ($page->rows as $company) {
    echo "{$company->fields['name']} — {$company->fields['employees']}\n";
}
```

If you've ever reached for an EAV table and then watched the self-joins melt your database, StarDust is the engine you wanted instead. The complete JSON payload is always the system of record; filterable fields are mirrored into pre-provisioned, indexed slot columns — so reads hit real indexes while writes stay available even when capacity runs low.

## Try it in five minutes

```bash
docker compose up
```

This brings up MySQL, bootstraps the schema, seeds a sample `company` model, runs the query above, and starts the four background daemons. The seeded results print in the `init` service logs:

```bash
docker compose logs init
```

Want to tinker? [`docker/seed.php`](docker/seed.php) is the whole flow — define a model, make fields filterable, write entries, query — as readable, copy-pasteable example code.

> **Heads up — this is a v0.3.0 pre-release.** `main` and the `0.3.x` tags are a breaking architectural migration (**Vertical Schema Partitioning**) away from the legacy 0.2.x line, motivated by scalability limits and OOM vulnerabilities in the old Virtual Column design. If you need something production-ready today, stay on `^0.2.0-alpha.x` — critical 0.2.x fixes land on the `support/v0.2` branch. Otherwise, read on; the honest caveats live in [Is StarDust a fit?](#is-stardust-a-fit) and [Status](#status), not buried in the fine print.

StarDust ships as a **framework-neutral Composer library** with zero runtime framework dependencies — only the `psr/log` and `psr/clock` interfaces. Framework adapters (CodeIgniter 4 first) are opt-in companion packages, never core requirements.

---

## Contents

- [Architecture at a glance](#architecture-at-a-glance)
- [Is StarDust a fit?](#is-stardust-a-fit)
- [Status](#status)
- [Requirements](#requirements)
- [Deployment Requirements](#deployment-requirements)
- [Installation](#installation)
- [Complete example](#complete-example)
- [Construction & schema bootstrap](#construction--schema-bootstrap)
- [Writing entries](#writing-entries)
- [Updating and deleting entries](#updating-and-deleting-entries)
- [Reading entries](#reading-entries)
- [Searching with the JSON wire format](#searching-with-the-json-wire-format)
- [Custom search drivers](#custom-search-drivers)
- [Changing a field's type or filterability](#changing-a-fields-type-or-filterability)
- [Async exports](#async-exports)
- [Errors](#errors)
- [CLI](#cli)
- [Testing](#testing)
- [Contributing](#contributing)
- [Legacy](#legacy)
- [License](#license)

---

## Architecture at a glance

Every entry's full payload is stored as JSON in `entry_data` — that's the system of record, and it always holds the complete record. Filterable fields are *mirrored* into typed, indexed slot columns on an extension page, so a filter query reads an index instead of scanning JSON:

```text
                       write(EntryPayload)
                                │
                                ▼
   ┌─────────────────────────────────────────────────────────────┐
   │  entry_data            (system of record — full payload)    │
   │  id │ tenant_id │ model_id │ fields (JSON)                  │
   │   7 │     1     │    42    │ {"name":"Acme","employees":340,│
   │     │           │          │  "city":"Berlin"}              │
   └─────────────────────────────────────────────────────────────┘
                                │  mirror the filterable fields
                                │  into typed slot columns
                                ▼
   ┌─────────────────────────────────────────────────────────────┐
   │  entry_slots_page_1    (indexed 1:1 extension page)         │
   │  entry_id │ i_str_01 │ i_int_01 │ …  (60 typed slots)       │
   │     7     │  "Acme"  │   340    │                           │
   │           │ (name)   │(employees)                           │
   └─────────────────────────────────────────────────────────────┘
        ▲ composite index (tenant_id, i_str_01), (tenant_id, i_int_01), …

   "city" was never made filterable → it never occupies a slot
   column at all. It lives in JSON only: still readable, just not
   indexed. A filterable field that outruns slot capacity also
   stays in JSON and is queued for backfill — the write never
   fails for lack of a slot.
```

Four background daemons keep the slot machinery healthy. They never talk to each other directly — MySQL is the only coordination point:

```text
        ┌──────────── MySQL — sole coordination point ──────────────┐
        │   entry_data · entry_slots_page_N · stardust_* registry   │
        └───────────────────────────────────────────────────────────┘
             ▲             ▲              ▲                ▲
   provisions│     drains  │    reclaims  │      streams   │
   capacity  │     queues  │    freed     │      exports   │
             │             │    slots     │                │
      ┌──────────┐  ┌────────────┐  ┌────────────┐  ┌────────────┐
      │ Watcher  │  │ Reconciler │  │ Liberator  │  │ Chronicler │
      │ singleton│  │multi-worker│  │ singleton  │  │multi-worker│
      └──────────┘  └────────────┘  └────────────┘  └────────────┘
   adds indexed   backfills the    sweeps tombstoned  writes async
   pages when     sync queue,      slot columns back  CSV/JSON
   capacity is    async imports,   to free for reuse  export
   low            and retypes                         artifacts
```

---

## Is StarDust a fit?

**A good fit if you:**

- Need user-defined or per-tenant dynamic fields that are still **filterable at native SQL index speed**, without standing up a separate search cluster.
- Already run **MySQL 8.0.13+ (or Percona)** and can keep persistent background processes alive (systemd, supervisor, or containers).
- Want a **framework-neutral** engine you can drop into any PHP app via Composer — no ORM, query builder, or framework pulled in.
- Can tolerate a newly defined or retyped filterable field becoming queryable **shortly after** the fact rather than instantly.

**Probably not a fit if you:**

- Can only deploy to **cron-only or shell-less shared hosting.** The Watcher, Reconciler, Liberator, and Chronicler must run as long-lived processes. Without the Watcher in particular, slot capacity is never replenished and new filterable writes silently fall back to the (unindexed) JSON payload.
- Are tied to **MariaDB or MySQL ≤ 5.7** — both are actively rejected (see [Requirements](#requirements)).
- Need **strong read-after-write consistency on filters immediately after a retype or filterability promotion.** The field is served from the JSON payload (and is not filterable) until its backfill completes.
- Need **full-text, fuzzy, or substring search** out of the box. The default MySQL driver ships exact-match, comparison, range, set-membership, and *anchored*-prefix (`LIKE 'x%'`) operators — but no substring/suffix matching, no fuzzy matching, and no relevance ranking. Fuzzy/full-text is a capability you'd supply via a custom driver.

---

## Status

**This is a v0.3.0 pre-release.** Phases 0 (operating-environment verification and the package skeleton), 1 (schema registry and core data plane), 2 (slot & page system), 3 (write path), 4 (read path), 5 (resilience daemons: Watcher + Reconciler), 6a (slot reclamation: Liberator), 6b (field retype & filterability-promotion pipeline), 7 (async exports: Chronicler), and 8 (search driver: JSON query-filter wire format, filter AST, and a swappable execution adapter) are implemented.

**What works today:**

- **Schema bootstrap** — idempotent, non-destructive provisioning of every table the engine needs.
- **Slot & page system** — auto-allocated `entry_slots_page_N` extension pages, indexed according to each field's `is_filterable` flag, with atomic free-slot reservation. Reservation keeps a model's filterable fields together on as few pages as it can, so filtered queries stay at fewer joins as a model grows.
- **Writes** — single-entry, synchronous chunked bulk (≤ 1 000 per call), and async submission for larger batches. Writes stay available even when slot capacity is exhausted: the value still lands in the JSON payload and is queued for backfill.
- **Entry updates and deletes** — `updateEntry()` replaces an entry's fields wholesale, rewriting both the JSON payload and the indexed slot columns, and clearing the slot of any field the new payload omits so a filter can never match a stale value. `deleteEntry()` soft-deletes: one timestamp, after which the entry is gone from reads, filters, point-reads, and exports alike.
- **Reads** — cursor-paginated, two-query bounded read; tenant-isolated SQL on every `WHERE` and `JOIN`; an in-process schema-version cache.
- **Search** — a unified `search()` surface; JSON wire format decoded into a closed filter AST (twelve operators, full AND/OR/NOT); three-stage pre-flight validation; a swappable driver (MySQL-native default keeps pure-AND filters on indexed joins and switches to `EXISTS` subqueries for OR/NOT — inject your own to delegate to an external search service).
- **Background daemons** (all runnable via `bin/stardust`): the **Watcher** keeps slot capacity provisioned and indexes each new page for the fields currently waiting on one, the **Reconciler** drains the sync queue / async imports / retype backfills (claiming a slot for any filterable field still waiting on one, with a dead-letter queue and operator replay, and auto-recovery of import jobs abandoned by a crashed worker — resumed from the last committed checkpoint), the **Liberator** reclaims tombstoned slots, and the **Chronicler** streams CSV/JSON exports to disk.
- **Field lifecycle** — online field retype, and filterability promotion and demotion, through a type-coercion matrix, with JSON-payload fallback throughout the backfill window. Demotion is registry-only and takes effect immediately: the slot is tombstoned for the Liberator to reclaim, and reads fall straight back to the payload.
- **Schema introspection** — `listModels()` and `describeModel()` report a tenant's models and each field's declared type, so a UI can render the schema without hand-written registry SQL. Every field reports both whether it is *declared* filterable and whether it is *currently* indexed — the two differ during a backfill, and gating on the latter is what stops a UI from offering a filter the engine would reject.
- **Slot maintenance** — `spread:report` shows how many extension pages each model's filterable fields occupy versus the fewest they could, so avoidable joins are visible before they cost you. `compact:model` acts on that: it relocates a fragmented model's fields onto a minimal page set, one field at a time so only one field is unfilterable at any moment, and `--dry-run` prints the plan without touching anything.

**Not yet available:**

- **No rename or delete for models and fields.** The rest of the definition layer is covered — `schemaBuilder()` registers, `listModels()` / `describeModel()` introspect, `retypeField()` changes a field's type online, and `promoteFieldToFilterable()` / `demoteFieldFromFilterable()` turn indexing on and off — but there is no entry point for renaming a model or field, or removing one. The practical workaround is to demote the old field (which releases its slot) and register the replacement; a JSON-only field occupies no slot, so leaving it behind costs you nothing but a key in the payload. Editing `stardust_models` / `stardust_fields` by hand is not supported, though foreign keys will at least refuse to drop a field that still holds a slot, so a mistake there fails loudly rather than corrupting your slot inventory. A first-class definition API covering the full lifecycle is on the roadmap.
- **Export predicate filtering is not applied.** `submitExport()` accepts a `filter` array, validates only the `format`, stores the filter verbatim, and the Chronicler then writes *every* non-deleted entry for the model. A filtered export request is not rejected — it silently produces a full extract, so treat the filter argument as reserved until this lands.
- **No async import-job status reads.** `submitBulkWrite()` returns an `ImportJobId`, but there is no `getImportJob()` to resolve it (exports do have `getExportJob()`). The job itself does reach a terminal state — `completed` with a manifest, or `failed` with a `failed_reason` and a dead-letter row — so the information exists; there is simply no supported way to read it back. Query `stardust_import_jobs` directly if you need it before this lands.

The remaining build sequence toward the v0.3.0 GA contract is documented in the project's design notes (maintained separately). Each phase is a gate with explicit exit criteria.

If you need a working library today, stay on `^0.2.0-alpha.x`.

---

## Requirements

- **PHP:** 8.1 or later
- **PHP extensions:** `ext-pdo`, `ext-pdo_mysql`
- **Database:** MySQL 8.0.13+ **or** Percona Server 8.0.13+

The 8.0.13 floor is firm: StarDust leans on functional/conditional unique indexes and common table expressions, and neither exists below 8.0.13. We'd rather refuse to start than corrupt your registry on an engine that silently does the wrong thing.

**Not supported:**

- **MariaDB** — its partial-index syntax and `SKIP LOCKED` semantics diverge from MySQL's in ways that would break the slot registry and the daemon claim model. StarDust detects this and refuses to run, and CI keeps us honest with a dedicated job that *expects* the smoke suite to fail on MariaDB. You find out at boot, not in production.
- **MySQL 5.7 and older** — no partial-unique-index feature, which the schema registry depends on.

---

## Deployment Requirements

StarDust v0.3.0 ships with four background daemons (Watcher, Reconciler, Liberator, Chronicler), all implemented and runnable today via `bin/stardust`. A supported deployment target MUST provide all of the following.

1. **Persistent background processes or long-running containers** — systemd, supervisor, Docker / Kubernetes / ECS, or equivalent. Cron-only invocation is not supported in v1; a future `--once` mode is under consideration but not committed.
2. **MySQL 8.0.13+ or Percona 8.0.13+** (also covered by the Requirements section above).
3. **PHP 8.x with CLI access** for the `bin/stardust` entry point.
4. **Local filesystem write access** for the Chronicler's async export artifacts (a mounted volume in container deployments).
5. **PID-file or orchestrator-level singleton enforcement for the Watcher and Liberator** — for the Watcher the in-database advisory lock is a safety net, not the primary enforcement mechanism. The Liberator relies on the PID file alone (it issues DML only, never DDL).

**Supported deployment tiers:**

| Tier | Verdict |
| :--- | :--- |
| Free shared hosting (no shell, no cron, no persistent processes) | Unsupported. |
| Paid shared hosting (cron only) | Unsupported in v1; a future `--once` mode is under consideration. |
| VPS with systemd / supervisor | Supported — reference deployment. |
| Containerized (Docker Compose, Kubernetes, ECS) | Supported — recommended for production at scale. |

---

## Installation

```bash
composer require damarbob/stardust
```

The package's only runtime dependencies are `psr/log` and `psr/clock` (both interface-only packages). It does not pull in a framework, an ORM, a query builder, or a logging implementation.

---

## Complete example

A minimal end-to-end walkthrough: bootstrap the schema, define a model, make its fields filterable, write a few entries, filter, and page through results. (This is the same flow as [`docker/seed.php`](docker/seed.php).)

### 1 — Bootstrap

```php
use StarDust\Config\Config;
use StarDust\StarDust;

$pdo = new PDO('mysql:host=127.0.0.1;dbname=app', $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$engine = new StarDust(new Config(pdo: $pdo));
$engine->bootstrap(); // idempotent — safe to call on every deploy
```

### 2 — Define the model and its fields

`schemaBuilder()` registers the model and its fields — no raw `INSERT`s. It's get-or-create, so re-running is safe, and it returns the model id plus a field-name → id map.

```php
use StarDust\Schema\FieldDefinition;

$company = $engine->schemaBuilder()->createModel(tenantId: 1, name: 'company', fields: [
    new FieldDefinition('name',      'string', isFilterable: true),
    new FieldDefinition('employees', 'int',    isFilterable: true),
]);

$modelId = $company->modelId;
```

### 3 — Make the filterable fields queryable

Registering a field records *intent*; the field becomes filterable once its value lands in an indexed slot column.

**In a running deployment this is automatic.** The Watcher notices fields waiting on a slot and provisions pages indexed for them; the Reconciler then claims a slot for any registered filterable field it finds still unmapped while draining the sync queue. Write an entry touching the field and it becomes queryable a moment later, without you reserving anything.

The manual route below is for one-off setup — a seed script, a test fixture, or a deployment where you want the slot in place before the first write. Provision a page (60 typed slots) and reserve one slot per field:

```php
use StarDust\Page\PageProvisioner;
use StarDust\Slot\SlotReserver;

// Provision a page, indexing the two slots the filterable fields will use.
(new PageProvisioner($pdo, $engine->config()->clock, $engine->logger()))
    ->provision(filterableSlots: ['i_str_01', 'i_int_01']);

// Reserve one slot per field (free → assigned). Reservation takes the
// lowest-numbered free slot of each type, so 'name' lands on i_str_01 and
// 'employees' on i_int_01 — exactly the slots we just indexed.
$reserver = new SlotReserver($pdo, $engine->config()->clock, $engine->logger());
$reserver->reserve($company->fieldId('name'));
$reserver->reserve($company->fieldId('employees'));
```

### 4 — Write entries

The same entry can be built two ways — pick whichever fits the caller. A
JSON/array envelope (`{tenantId, modelId, fields}`, camelCase) is handy when
entries arrive off a wire (CMS, HTTP body, queue); the typed constructor gives
you IDE validation. Both flow through the **identical** write path: field values
coerce the same way (same `UncoercibleSlotValueException`), and `tenant_id` is
validated (`>= 1`) at the boundary regardless of how the payload was built.

```php
use StarDust\Write\EntryPayload;

// Typed — IDE-validated:
$engine->write(new EntryPayload(tenantId: 1, modelId: $modelId,
    fields: ['name' => 'Acme Corp', 'employees' => 340]));

// From a PHP array — e.g. you already decoded a request body:
$engine->write(EntryPayload::fromArray([
    'tenantId' => 1,
    'modelId'  => $modelId,
    'fields'   => ['name' => 'Globex', 'employees' => 85],
]));

// From a raw JSON string — feed an HTTP request body straight in:
$body = '{"tenantId": 1, "modelId": ' . $modelId . ',
          "fields": {"name": "Initech", "employees": 510}}';
$engine->write(EntryPayload::fromJson($body));

// Bulk: a JSON array of envelopes straight into bulkWrite():
//   $engine->bulkWrite(EntryPayload::listFromJson($jsonArrayBody));
```

### 5 — Filter and paginate

```php
use StarDust\Filter\Ast\LeafNode;
use StarDust\Filter\Json\JsonFilterDecoder;
use StarDust\Read\EntryQuery;

// Build the filter — two equivalent ways (the JSON form is used below):
//
//  (a) Typed — IDE-validated:
//      $filter = LeafNode::local('employees', 'gt', 100);
//
//  (b) From a JSON wire payload — e.g. decoded straight from an HTTP
//      request. Returns the same FilterNode the typed form produces;
//      rejections carry a closed error code + RFC 6901 pointer.
$filter = (new JsonFilterDecoder($engine->config()->queryFilterLimits))->decode(
    '{"filter": {"op": "gt",
        "field": {"model": "company", "name": "employees"}, "value": 100}}'
);

// Fetch companies with more than 100 employees, 2 per page.
$page = $engine->read(new EntryQuery(
    tenantId:     1,
    modelId:      $modelId,
    filter:       $filter,
    selectFields: ['name', 'employees'],
    pageSize:     2,
));

foreach ($page->rows as $entry) {
    echo $entry->fields['name'] . ' — ' . $entry->fields['employees'] . "\n";
}
// Acme Corp — 340
// Initech — 510

// Page through to exhaustion (this dataset fits in one page, so
// nextCursor is null — the loop exits immediately after page 1).
$cursor = $page->nextCursor;
while ($cursor !== null) {
    $page   = $engine->read(new EntryQuery(
        tenantId: 1, modelId: $modelId, pageSize: 2, cursor: $cursor,
    ));
    foreach ($page->rows as $entry) { /* ... */ }
    $cursor = $page->nextCursor;
}
```

### 6 — Point read

```php
$firstId = $page->rows[0]->id;
$entry   = $engine->get(tenantId: 1, entryId: $firstId);
echo $entry?->fields['name']; // Acme Corp
```

---

## Construction & schema bootstrap

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
> This is a stopgap, not the first-class definition API. Registering a field isn't enough to filter on it — its value has to reach an indexed slot column. The Watcher daemon provisions that capacity in a running deployment; for one-off setup, call `PageProvisioner` + `SlotReserver` (see the [Complete example](#complete-example)). Until a field has a reserved indexed slot it's still stored and point-readable from the JSON payload — just not on the indexed filter path.

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

Phases 5, 6a, and 7 add twenty-nine optional `Config` parameters for daemon tuning:

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
));
```

## Writing entries

```php
use StarDust\Write\BulkIngestOptions;
use StarDust\Write\EntryPayload;

// Single-entry write. Atomic INSERT into entry_data + per-page
// INSERT … ON DUPLICATE KEY UPDATE into entry_slots_page_N for
// each field with a live slot; falls back to stardust_sync_queue
// (in the same transaction) if any *filterable* field lacks a
// live slot (exhaustion fallback — the call still succeeds).
// Non-filterable fields live in the JSON payload only, so they
// never occupy a slot and never queue.
$result = $engine->write(new EntryPayload(
    tenantId: 42,
    modelId:  $modelId,
    fields:   ['name' => 'Acme', 'employees' => 120],
));
// $result->entryId, $result->enqueuedForBackfill, $result->slotsWritten

// Synchronous chunked bulk ingest (≤ 1 000 entities). Each chunk
// (default 500) commits in its own transaction so InnoDB lock
// duration stays bounded. Returns a per-chunk manifest.
$bulk = $engine->bulkWrite(
    payloads: $listOfEntryPayloads,
    options:  new BulkIngestOptions(chunkSize: 500, interChunkDelayMicros: 0),
);

// Async submission (> 1 000 entities, or smaller batches you want
// processed off-thread). Writes the payload to Config::$artifactDir,
// inserts a stardust_import_jobs row, returns the Import Job ID.
// A running Reconciler (bin/stardust reconciler) drains the job.
$jobId = $engine->submitBulkWrite(
    tenantId:        42,
    payloads:        $largeBatch,
    idempotencyKey:  'monthly-import-2026-05',
);

// Build payloads from JSON / arrays instead of the typed constructor —
// handy when entries arrive off a wire (CMS, HTTP, queue). The envelope
// is {tenantId, modelId, fields} (camelCase). These are *convergent*
// factories: they validate envelope shape and return an ordinary
// EntryPayload, so the value flows through the identical write path.
$engine->write(EntryPayload::fromArray([
    'tenantId' => 42, 'modelId' => $modelId,
    'fields'   => ['name' => 'Acme', 'employees' => 120],
]));
$engine->write(EntryPayload::fromJson($rawJsonObjectBody));

// Bulk: a JSON array (or PHP list) of envelopes:
$engine->bulkWrite(EntryPayload::listFromJson($rawJsonArrayBody));
$engine->submitBulkWrite(tenantId: 42,
    payloads: EntryPayload::listFromArray($decodedEnvelopes));
```

Envelope-shape errors raise `MalformedEntryPayloadException` (carrying the
offending `$key`, e.g. `'tenantId'` or `'[3].modelId'`). The `tenant_id >= 1`
rule and per-field type coercion stay on the write path — identical to the typed
constructor — so a factory-built payload behaves exactly like `new EntryPayload(...)`.
Pair this with [Searching with the JSON wire format](#searching-with-the-json-wire-format)
for an end-to-end JSON loop: JSON in, JSON-filtered out.

`tenant_id` is validated at every entry point (must be `>= 1`) before any SQL executes. All write-path operations emit structured NDJSON log events — `entry_written`, `entry_updated`, `entry_deleted`, `exhaustion_fallback`, `bulk_chunk_committed`, `bulk_chunk_rolled_back`, `bulk_accepted`, `payload_too_large`.

## Updating and deleting entries

```php
// Update is a full replace, not a patch: $fields becomes the entry's
// complete payload. A field you omit is removed from the JSON *and*
// its indexed slot column is cleared, so a filter can never match a
// value the entry no longer carries.
$engine->updateEntry(tenantId: 42, entryId: $entryId, fields: [
    'name'      => 'Acme Holdings',
    'employees' => 141,
]);

// Soft delete. One timestamp, and the entry is gone from read(),
// get(), search(), and exports alike.
$deleted = $engine->deleteEntry(tenantId: 42, entryId: $entryId);
// true on the transition; false if it was already deleted or not yours
```

`model_id` is immutable — an update never moves an entry between models. Coercion, tenant isolation, and the capacity fallback all behave exactly as they do on `write()`: an update that introduces a filterable field with no free slot still succeeds, storing the value in the JSON payload and queueing it for backfill.

`updateEntry()` throws `EntryNotFoundException` when the entry does not exist, belongs to another tenant, or is already deleted — silently discarding an update would lose data the caller believed it had written. `deleteEntry()` takes the opposite stance and returns `false` in those same cases, because a repeated delete has already achieved what the caller asked for. There is no hard delete and no restore.

## Reading entries

```php
use StarDust\Filter\Ast\AndNode;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Filter\Ast\NotNode;
use StarDust\Filter\Ast\OrNode;
use StarDust\Read\EntryQuery;

// Cursor-paginated read. Two-query bounded sequence:
//   1) Paginated Probe selects entry_data.id with LIMIT pageSize+1
//      (the extra row is the sole next-page signal — no COUNT(*),
//      no OFFSET).
//   2) Bounded Fetch materialises only those IDs plus the indexed
//      slot columns needed to assemble the caller's selectFields.
// Filters on fields with is_filterable=false or whose slot is
// backfilling/tombstoned/unmapped are rejected pre-flight with a
// typed exception — no SQL is issued.
//
// Filters are AST trees: leaves carry (operator, field, value);
// composites are AndNode / OrNode / NotNode. Pure-AND chains keep
// the original INNER-JOIN-per-page execution shape; trees that
// contain OR or NOT switch to EXISTS subqueries automatically.
$page = $engine->read(new EntryQuery(
    tenantId:     42,
    modelId:      $modelId,
    filter:       LeafNode::local('name', 'eq', 'Acme'),
    selectFields: ['name', 'employees'],
    pageSize:     100,
));

// Multiple AND-composed leaves:
$page = $engine->read(new EntryQuery(
    tenantId: 42,
    modelId:  $modelId,
    filter:   new AndNode([
        LeafNode::local('status', 'eq', 'active'),
        LeafNode::local('employees', 'gt', 100),
    ]),
));

// Full boolean composition:
$filter = new AndNode([
    new OrNode([
        LeafNode::local('region', 'eq', 'eu'),
        LeafNode::local('region', 'eq', 'us'),
    ]),
    new NotNode(LeafNode::local('status', 'eq', 'archived')),
]);
// $page->rows           — list<Entry>
// $page->nextCursor     — Cursor|null; null means last page
// $page->pageSize       — echo of the requested size

// Page through to exhaustion. The cursor is opaque — pass it back
// unchanged; do not inspect it.
$cursor = $page->nextCursor;
while ($cursor !== null) {
    $next = $engine->read(new EntryQuery(
        tenantId: 42,
        modelId:  $modelId,
        pageSize: 100,
        cursor:   $cursor,
    ));
    // ...
    $cursor = $next->nextCursor;
}

// Point read by (tenant_id, entry_id). Returns null when the entry
// does not exist for this tenant (or has been soft-deleted).
$entry = $engine->get(tenantId: 42, entryId: $someEntryId);
// $entry?->id, $entry?->fields, $entry?->createdAt
```

Fields are sourced from the joined slot column when the slot's status is `assigned` or `ready`; otherwise — `backfilling`, `tombstoned`, or unmapped — they fall back to the JSON payload stored in `entry_data.fields`. This preserves write-availability on the read side: a field that lacks an indexed slot still surfaces, just without filter / sort capability. The read path emits NDJSON events `search_request` and `pre_flight_rejected`; `cache_miss` is emitted by the in-process schema-version cache on registry-version bumps.

## Searching with the JSON wire format

Consumers (HTTP gateways, RPC layers) typically receive filters as JSON. Decode them with `JsonFilterDecoder`, then call `search()` with the resulting AST:

```php
use StarDust\Filter\Json\JsonFilterDecoder;
use StarDust\Search\SearchRequest;

$decoder = new JsonFilterDecoder($engine->config()->queryFilterLimits);
$filter  = $decoder->decode($requestBody);
$result  = $engine->search(new SearchRequest(
    tenantId: 42,
    modelId:  $modelId,
    filter:   $filter,
    pageSize: 100,
));
```

A typical wire payload:

```json
{
  "version": "1",
  "filter": {
    "op": "and",
    "args": [
      { "op": "eq",    "field": { "model": "invoice", "name": "status" }, "value": "paid" },
      { "op": "gt",    "field": { "model": "invoice", "name": "amount" }, "value": 100   },
      { "op": "is_not_null", "field": { "model": "invoice", "name": "due_date" } }
    ]
  }
}
```

The decoder enforces a closed 13-code error taxonomy (`envelope_malformed`, `node_malformed`, `operator_unknown`, `value_count_mismatch`, `value_unexpected`, `value_out_of_bounds`, `nesting_too_deep`, `node_count_exceeded`, `version_unsupported`, plus pre-flight `field_unknown`, `field_not_filterable`, `capability_unsupported`, `value_type_mismatch`). Every rejection carries an RFC 6901 JSON Pointer to the offending node.

The wire format also ships as a normative JSON Schema (draft 2020-12) at [`schemas/queryfilter.schema.json`](schemas/queryfilter.schema.json), for consumer-side validation in any language and for CI cross-checks. A smoke test (`QueryFilterSchemaConformanceTest`) runs a payload corpus through both the schema and `JsonFilterDecoder` and fails if their accept/reject verdicts ever diverge, keeping the two in lockstep.

## Custom search drivers

`StarDust\Search\EntrySearchInterface` is the swappable seam. The engine ships with a `MysqlNativeDriver` that wraps the bounded-read path; inject any other implementation through `Config`:

```php
use StarDust\Config\Config;
use StarDust\Search\EntrySearchInterface;

final class MeilisearchDriver implements EntrySearchInterface { /* ... */ }

$engine = new StarDust(new Config(
    pdo:          $pdo,
    searchDriver: new MeilisearchDriver(/* ... */),
));
```

Drivers declare which operators they service (`supportedOperators()`), per-field filterability (`supportsFilterOn()`), and their consistency model (`consistencyModel(): 'strong' | 'eventual'`). The pre-flight pipeline rejects unsupported requests before the driver is invoked. Writes always go to MySQL — drivers are read-only.

## Changing a field's type or filterability

```php
// Change a field's declared type. Atomic registry transaction:
//   - stardust_fields.declared_type updates;
//   - the field's current live slot tombstones (Liberator reclaims it);
//   - a new slot of the target type flips free → backfilling (or the
//     reservation defers until capacity is restored);
//   - stardust_schema_version bumps;
//   - a backfill_checkpoints row inserts as `running`.
// Reads fall back to JSON_EXTRACT throughout the backfill window;
// filter queries against the field throw FieldNotIndexedException
// until the slot promotes to `ready`. Uncoercible values store NULL
// (with a per-row `coercion_null` audit event); the JSON payload
// remains authoritative.
$engine->retypeField(
    tenantId:        42,
    fieldId:         $fieldId,
    newDeclaredType: 'int',
);

// Promote an existing unfiltered field to filterable. A fresh
// indexed slot is reserved and backfilled from the JSON payload;
// declared_type stays the same so no coercion is attempted. There
// is normally no old slot to tombstone — the field held none while
// it was non-filterable.
$engine->promoteFieldToFilterable(
    tenantId: 42,
    fieldId:  $fieldId,
);

// Turn indexing back off. Registry-only and effective on return:
// the slot tombstones for the Liberator to reclaim, no backfill
// window, and reads fall straight back to the JSON payload. From
// here on, filters against the field raise
// FieldNotFilterableException.
$engine->demoteFieldFromFilterable(
    tenantId: 42,
    fieldId:  $fieldId,
);
```

Only filterable fields occupy slots, so only a filterable field has anything to backfill. Retyping a field that is not filterable — or demoting one back to non-filterable — is a registry-only change: the metadata updates, any slot the field held is released, and the operation is complete when the call returns. There is no backfill window and nothing for the Reconciler to do, because the JSON payload was already the authoritative copy. A demoted field keeps reading correctly and immediately stops being a valid filter target.

Retypes between numeric / int and datetime are categorically rejected at registry-write time (`IncompatibleRetypeException`) — epoch interpretation is a caller policy, not engine behaviour; bridge through a `string` intermediate field if you need it. Initiating a second retype for the same field while one is already running throws `RetypeInProgressException`. The Reconciler picks up `running` retype checkpoints on every tick (alongside `stardust_sync_queue` and `stardust_import_jobs`); when the partition is exhausted it promotes the slot to `ready`, bumps `stardust_schema_version`, emits `promote_to_ready`, and triggers two one-shot advisory samples — `cardinality_sampled` for the new slot, and `spread_sampled` for the model, since a retype can move a field onto a page its model did not previously occupy.

## Async exports

```php
use StarDust\Export\ExportJobRequest;

// Submit an async export. The call enforces a per-tenant active-job
// cap (default ≤ 3 pending+processing) inside one transaction; a 4th
// concurrent submission throws ExportJobActiveCapExceededException.
// Format is 'csv' or 'json'. The filter array is stored verbatim
// for forward compatibility: the export pipeline currently consults
// only model_id and exports every (non-deleted) entry for the model.
// Predicate filtering of exports is not yet wired in — the search
// driver's AST is not consulted by the Chronicler.
$jobId = $engine->submitExport(new ExportJobRequest(
    tenantId: 42,
    modelId:  $modelId,
    format:   'csv',
    filter:   [],
));
// $jobId->jobId — pass back to getExportJob() to poll status

// Poll status. Returns null when the job does not exist for this
// tenant (tenant isolation is enforced by the WHERE clause).
$job = $engine->getExportJob(tenantId: 42, jobId: $jobId->jobId);
if ($job?->status === 'completed') {
    // $job->artifactPath holds the absolute path to the CSV/JSON
    // file under Config::$artifactDir. Serve it to the caller,
    // then trust the Chronicler's idle-cycle GC to clean it up
    // after the configured TTL (24 h default).
    serveDownload($job->artifactPath);
}
```

Run one or more Chronicler workers (multi-worker safe — no PID guard):

```bash
vendor/bin/stardust chronicler   # scale by spawning more processes
```

The Chronicler claims one job per tick — pending first (per-tenant round-robin so a single tenant cannot starve others), then abandoned jobs whose heartbeat lapsed beyond `chroniclerLeaseTimeoutSeconds`. On a re-claim it best-effort-deletes the prior partial artifact and resumes from `last_cursor`. Lease loss is self-detected at every chunk commit through a `WHERE worker_identity = self` predicate — a worker whose row was overwritten by a re-claimer emits `lease_lost`, deletes its partial, and bails without mutating the row (the re-claimer owns terminal state). Failure semantics: 3-deadlock budget per chunk before `chunk_skipped`, combined skip cap of 1 000 before `failed:excessive_skips`, fixed `[1, 4, 16]`-second DB-disconnect backoff before `failed:query_failure` (with `last_cursor` preserved for restart), `ENOSPC` mid-write yields `failed:disk_full`, and bytes-exceeding-5 GB emits `artifact_oversized` (a distinct event from `job_failed`) and marks `failed:artifact_size_exceeded`. Idle ticks GC TTL'd completed artifacts and orphaned failed-job partials; a pre-claim disk-pressure gate emits `low_disk` and skips new claims when free space falls below `chroniclerLowDiskThresholdPct` (in-flight jobs continue).

---

## Errors

All typed errors extend `RuntimeException`. They live under `StarDust\Exception\`, except `QueryFilterValidationException`, which is under `StarDust\Filter\`.

| Exception | Thrown when |
| :--- | :--- |
| `InvalidTenantIdException` | `tenantId` is `<= 0` (checked before any SQL at every entry point). |
| `PayloadTooLargeException` | A synchronous `bulkWrite()` exceeds 1 000 entities — use `submitBulkWrite()` instead. |
| `UncoercibleSlotValueException` | A first-write payload value cannot be coerced to its slot's declared type (the write path is fail-fast). |
| `MalformedEntryPayloadException` | An array/JSON entry envelope passed to `EntryPayload::fromArray()` / `fromJson()` / `listFrom*()` is structurally invalid — missing or mistyped `tenantId`/`modelId`/`fields`, a non-map `fields`, unparseable JSON, or a wrong root. Carries the offending `$key`. |
| `UnknownFieldException` | A filter references a field absent from `stardust_fields`. |
| `FieldNotFilterableException` | A filter targets a field the active driver reports as non-filterable (for the default MySQL driver, `is_filterable = false`). |
| `FieldNotIndexedException` | A filter targets a field whose slot is `backfilling`, `tombstoned`, or unmapped. |
| `PageSizeOutOfRangeException` | `pageSize` is outside `[1, 1000]`. |
| `InvalidCursorException` | An opaque cursor fails its structural decode. |
| `QueryFilterValidationException` | A JSON wire-format filter fails decode or pre-flight (see below). |
| `IncompatibleRetypeException` | A retype crosses a categorically rejected pair (`int ↔ datetime`, `numeric ↔ datetime`). |
| `RetypeInProgressException` | A retype is initiated for a field that already has one running. |
| `FieldNotFoundException` | `retypeField()` / `promoteFieldToFilterable()` receive a field id that doesn't exist for the tenant. |
| `NonFilterableFieldSlotException` | A slot reservation was attempted for a non-filterable field. Such fields live in the JSON payload only and never occupy a slot, so this signals a caller bug rather than a capacity problem — distinct from `FieldNotFilterableException`, which rejects a *query* that filters on one. |
| `ExportJobActiveCapExceededException` | A tenant is already at its active-export cap (carries `$tenantId`, `$activeCount`, `$cap`). |

### Handling wire-format rejections

`QueryFilterValidationException` is deliberately discriminator-style: a single `catch` handles every wire-format and pre-flight failure, because all of them share one caller response — fix the filter JSON and retry. It carries enough context to render a precise HTTP 4xx without a per-code handler:

- `$errorCode` — one of the closed `StarDust\Filter\ValidationErrorCode` constants.
- `$jsonPointer` — an RFC 6901 pointer to the offending node (e.g. `/filter/args/1/value`).
- `$details` — discriminator-specific context (e.g. `['expected' => 'int', 'received' => 'string']`).

```php
use StarDust\Filter\Json\JsonFilterDecoder;
use StarDust\Filter\QueryFilterValidationException;
use StarDust\Exception\UnknownFieldException;
use StarDust\Exception\FieldNotFilterableException;
use StarDust\Search\SearchRequest;

try {
    $filter = (new JsonFilterDecoder($engine->config()->queryFilterLimits))->decode($body);
    $result = $engine->search(new SearchRequest(
        tenantId: 42,
        modelId:  $modelId,
        filter:   $filter,
    ));
} catch (QueryFilterValidationException $e) {
    http_response_code(400);
    echo json_encode([
        'error'   => $e->errorCode,    // e.g. 'value_type_mismatch'
        'pointer' => $e->jsonPointer,  // e.g. '/filter/args/1/value'
        'details' => $e->details,
    ]);
} catch (UnknownFieldException | FieldNotFilterableException $e) {
    // The field_unknown and field_not_filterable cases reuse these
    // pre-existing exceptions rather than QueryFilterValidationException.
    http_response_code(400);
}
```

---

## CLI

The framework-neutral CLI entry point is `bin/stardust`:

```bash
vendor/bin/stardust --version
vendor/bin/stardust --help

# Phase 1: idempotently bootstrap the schema on a configured database.
# Reads STARDUST_DSN / STARDUST_USER / STARDUST_PASS from the environment.
STARDUST_DSN='mysql:host=127.0.0.1;dbname=app' \
STARDUST_USER=root STARDUST_PASS=root \
vendor/bin/stardust bootstrap

# Phase 5: singleton page-provisioning daemon. Holds a flock on
# <pidFileDir>/watcher.pid; a second instance exits with code 2.
vendor/bin/stardust watcher

# Phase 5: multi-worker sync_queue + import_jobs drain. Run as many
# replicas as you need — SKIP LOCKED keeps them disjoint.
vendor/bin/stardust reconciler

# Phase 5: operator-initiated DLQ replay (re-enqueues into
# stardust_sync_queue and removes the DLQ row in one transaction).
vendor/bin/stardust reconciler:dlq:replay --id=42
vendor/bin/stardust reconciler:dlq:replay --reason=schema_incompatibility

# Phase 6a: singleton slot-reclamation daemon. Polls
# stardust_slot_assignments for `tombstoned` rows, nullifies the
# corresponding slot column on entry_slots_page_N in bounded chunks,
# and transitions the slot back to `free` once the partition is
# fully nullified. Holds a flock on <pidFileDir>/liberator.pid; a
# second instance exits with code 2.
vendor/bin/stardust liberator

# Phase 7: multi-worker async export daemon. Claims pending or
# abandoned export jobs from stardust_export_jobs, paginates
# entry_data, streams CSV/JSON artifacts to <artifactDir>, runs
# idle-cycle GC on completed-artifact TTL + orphaned failed-job
# partials. Run multiple processes for horizontal scale — no PID
# guard; SELECT … FOR UPDATE SKIP LOCKED is the only coordination
# primitive.
vendor/bin/stardust chronicler

# Report slot spread: how many extension pages each model's filterable
# fields are scattered across, versus the fewest they could occupy.
# EXCESS is the number of avoidable joins every filtered query on that
# model pays — 0 is optimal packing. Registry-only and read-only, so it
# is safe to run against production at any time.
vendor/bin/stardust spread:report
vendor/bin/stardust spread:report --tenant=1 --model=7

# Compact a model: relocate its filterable fields onto the fewest pages
# that can hold them, removing the avoidable joins spread:report shows.
# Long-running and deliberate — it moves one field at a time and needs a
# running reconciler. While a field is in flight, filters on THAT field
# are rejected; reads keep working, and every other field is unaffected.
# Safe to re-run: fields already in place are skipped.
vendor/bin/stardust compact:model --tenant=1 --model=7 --dry-run
vendor/bin/stardust compact:model --tenant=1 --model=7
```

Daemons honour both `SIGTERM`/`SIGINT` (when `ext-pcntl` is loaded) and
`touch <pidFileDir>/<daemon-name>.shutdown` as a graceful-shutdown
signal — useful on hosts without `pcntl`. Exit codes: `0` clean
shutdown (including signal-induced), `1` fatal, `2` singleton
violation or user error.

---

## Testing

StarDust is covered by a smoke suite that runs against a **real MySQL** — no mocked databases. It skips cleanly when no test database is configured, so a fresh clone runs green out of the box:

```bash
composer install
cp phpunit.xml.dist phpunit.xml         # gitignored; edit with your DB creds
vendor/bin/phpunit --testsuite Smoke
```

A handful of the suite's tests need no database at all (e.g. the wire-format decoder, the event-vocabulary guard, and the schema-conformance cross-check), so they run even on a bare clone.

GitHub Actions runs the same suite on every push, plus a second job that asserts the suite **fails** against MariaDB.

For the full setup guide and a phase-by-phase breakdown of exactly what each behaviour the suite proves, see **[TESTING.md](TESTING.md)**.

---

## Contributing

Bug reports, questions, and pull requests are welcome.

Start with **[CONTRIBUTING.md](CONTRIBUTING.md)** — it covers the requirements, the setup, and the three commands to run before pushing. Most of this project's conventions are enforced by tests rather than by review, so you will hear about a mistake immediately instead of days later.

---

## Legacy

The legacy 0.2.x source code has been removed from the repository; it remains available via the `^0.2.0-alpha.x` release tags on Packagist.

---

## License

MIT License.
