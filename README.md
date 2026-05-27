# StarDust

> **🚧 UNDER ACTIVE DEVELOPMENT (v0.3.0) 🚧**
>
> StarDust is currently undergoing a major architectural migration to **Vertical Schema Partitioning** to address scalability limits and OOM vulnerabilities found in the previous Virtual Column architecture. The current `main` branch and upcoming `0.3.x` pre-releases represent a breaking change.
>
> We strongly advise consumers and developers to remain locked to the `^0.2.0-alpha.x` release line until the v0.3.0 API contract and migration paths are finalized. Critical fixes and backports for the `0.2.x` line will be maintained in the `support/v0.2` branch.

## MySQL-Native, Framework-Neutral Engine for Dynamic Data Models

**StarDust** is a high-performance PHP engine that gives applications schemaless dynamic fields with the query speed of a native SQL table — without bolting on a separate search service. The v0.3.0 architecture (**Vertical Schema Partitioning**) stores every entry's full JSON payload as the system of record and mirrors filterable fields into pre-provisioned slot columns on immutable extension pages, so consumer reads hit indexed columns directly while writes remain available even when slot capacity is exhausted.

Unlike the legacy 0.2.x line, v0.3.0 ships as a **framework-neutral Composer library** with zero runtime framework dependencies. Adapters for specific frameworks (CodeIgniter 4 first) are opt-in companion packages, not core requirements.

---

## Status

**This is a v0.3.0 pre-release.** Phases 0 (operating-environment verification and the package skeleton), 1 (schema registry and core data plane), 2 (slot & page system), 3 (write path), 4 (read path), 5 (resilience daemons: Watcher + Reconciler), 6a (slot reclamation: Liberator), 6b (field retype & filterability-promotion pipeline), and 7 (async exports: Chronicler) are implemented. The engine can now idempotently provision its full physical schema, allocate new `entry_slots_page_N` extension pages with the index layout determined by the registry's `is_filterable` flags, atomically reserve free slots for model fields, ingest entries (single rows, sync chunked batches up to 1 000 per call, or larger batches via async submission), serve cursor-paginated reads — a two-query bounded read with pre-flight rejection of unindexed/backfilling/unmapped filter targets, tenant-isolated SQL on every `WHERE` and `JOIN`, and an in-process schema-version cache keyed on `stardust_schema_version.version` — **automatically maintain slot capacity in the background**, **and stream CSV/JSON exports to disk via an async submission API**. The singleton Watcher provisions a new page when global capacity drops below the configured threshold (under `GET_LOCK('stardust_page_provision', 10)`) and runs a cardinality advisory on a 24 h cadence. The multi-worker Reconciler drains `stardust_sync_queue` via `SELECT … FOR UPDATE SKIP LOCKED`, processes async bulk-ingest jobs from `stardust_import_jobs`, **and drains pending field-retype backfills against a per-field `entry_data` cursor**, quarantining poison rows to `stardust_reconciler_dlq`. Operators replay quarantined entries via `bin/stardust reconciler:dlq:replay --id=N` or `--reason=X`. The singleton Liberator polls `stardust_slot_assignments` for `tombstoned` rows, chunk-nullifies their slot columns on `entry_slots_page_X` with per-chunk cursor checkpointing, transitions reclaimed slots back to `free` atomically with a schema-version bump, and bounded-retries InnoDB deadlocks before annotating the registry row with a `sweep_gap_count` for operator review. Operators initiate a field retype or filterability promotion through the public API; the engine atomically tombstones the old slot, reserves a new `backfilling` slot (or defers until capacity is restored), and the Reconciler drains the partition through a normative type-coercion matrix before promoting the slot to `ready` and triggering a one-shot cardinality sample. The multi-worker Chronicler claims pending export jobs from `stardust_export_jobs` via per-tenant round-robin `SELECT … FOR UPDATE SKIP LOCKED`, paginates `entry_data` with the bounded `LIMIT N+1` shape, and streams a CSV (RFC 4180) or single-document JSON array artifact incrementally to disk; lease loss is self-detected at every chunk commit via a `WHERE worker_identity = self` predicate, and an abandoned-claim sweep resumes stranded jobs from their last cursor. Idle Chronicler ticks GC TTL'd artifacts and orphaned partials; a pre-claim disk-pressure gate emits `low_disk` and skips new claims when free space falls below the configured threshold (in-flight jobs continue).

The remaining build sequence — the Search Driver — is documented in the project's design notes (maintained separately). Each phase is a gate with explicit exit criteria.

If you need a working library today, stay on `^0.2.0-alpha.x`.

---

## Requirements

- **PHP:** 8.1 or later
- **PHP extensions:** `ext-pdo`, `ext-pdo_mysql`
- **Database:** MySQL 8.0.13+ **or** Percona Server 8.0.13+

The 8.0.13 floor is non-negotiable. StarDust relies on functional/conditional unique indexes and common table expressions, both of which require 8.0.13.

**Explicitly unsupported:**

- **MariaDB** — partial-index syntax and `SKIP LOCKED` semantics diverge from MySQL. The Phase 0 smoke suite is intentionally inhospitable to MariaDB and the CI pipeline asserts the rejection on every push.
- **MySQL 5.7 and older** — missing the partial-unique-index feature the schema registry depends on.

---

## Deployment Requirements

StarDust v0.3.0 ships with four background daemons (Watcher, Reconciler, Liberator, Chronicler) arriving in Phases 5–7. A supported deployment target MUST provide all of the following — these requirements are binding once daemons ship, but you can plan against them today.

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

Phase 2's page provisioner and slot reserver remain internal classes (`StarDust\Page\PageProvisioner`, `StarDust\Slot\SlotReserver`); Phase 5's Watcher daemon (`bin/stardust watcher`) wires them automatically. Model and field definition remain a registry-only concern until a future operator surface lands.

Phases 5, 6a, and 7 add twenty-eight optional `Config` parameters for daemon tuning:

```php
$engine = new StarDust(new Config(
    pdo:                                 $pdo,
    watcherPollIntervalSeconds:          60,        // default
    watcherCapacityThreshold:            0.20,      // provision when free-ratio falls below
    watcherProvisionLockTimeoutSeconds:  10,        // GET_LOCK wait — production stays at 10
    cardinalityIntervalSeconds:          86_400,    // 24 h cadence
    cardinalitySelectivityThreshold:     0.01,
    cardinalityRowFloor:                 10_000,
    cardinalityDistinctFloor:            10,
    reconcilerChunkSize:                 500,       // SKIP LOCKED LIMIT N
    reconcilerInterChunkDelayMicros:     0,         // pace drain throughput (0 = no pacing)
    reconcilerCapacityWaitMillis:        5_000,     // sleep after a capacity_wait tick
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
));
```

## Writing entries

```php
use StarDust\Write\BulkIngestOptions;
use StarDust\Write\EntryPayload;

// Single-entry write. Atomic INSERT into entry_data + per-page
// INSERT … ON DUPLICATE KEY UPDATE into entry_slots_page_N for
// each field with a live slot; falls back to stardust_sync_queue
// (in the same transaction) if any field lacks a live slot
// (exhaustion fallback — the call still succeeds).
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
// The Phase 5 Reconciler will drain the queue once it ships.
$jobId = $engine->submitBulkWrite(
    tenantId:        42,
    payloads:        $largeBatch,
    idempotencyKey:  'monthly-import-2026-05',
);
```

`tenant_id` is validated at every entry point (must be `>= 1`) before any SQL executes. All write-path operations emit structured NDJSON log events — `entry_written`, `exhaustion_fallback`, `bulk_chunk_committed`, `bulk_chunk_rolled_back`, `bulk_accepted`, `payload_too_large`.

## Reading entries

```php
use StarDust\Read\Cursor;
use StarDust\Read\EntryQuery;
use StarDust\Read\QueryFilter;

// Cursor-paginated read. Two-query bounded sequence:
//   1) Paginated Probe selects entry_data.id with LIMIT pageSize+1
//      (the extra row is the sole next-page signal — no COUNT(*),
//      no OFFSET).
//   2) Bounded Fetch materialises only those IDs plus the indexed
//      slot columns needed to assemble the caller's selectFields.
// Filters on fields with is_filterable=false or whose slot is
// backfilling/tombstoned/unmapped are rejected pre-flight with a
// typed exception — no SQL is issued.
$page = $engine->read(new EntryQuery(
    tenantId:     42,
    modelId:      $modelId,
    filters:      [new QueryFilter('name', 'eq', 'Acme')],
    selectFields: ['name', 'employees'],
    pageSize:     100,
));
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

Fields are sourced from the joined slot column when the slot's status is `assigned` or `ready`; otherwise — `backfilling`, `tombstoned`, or unmapped — they fall back to the JSON payload stored in `entry_data.fields`. This preserves write-availability on the read side: a field that lacks an indexed slot still surfaces, just without filter / sort capability. The read path emits NDJSON events `request` and `pre_flight_rejected`; `cache_miss` is emitted by the in-process schema-version cache on registry-version bumps.

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

// Promote an existing unfiltered field to filterable. Same lifecycle
// as retype but the new slot reservation demands an indexed column;
// declared_type stays the same so no coercion is attempted.
$engine->promoteFieldToFilterable(
    tenantId: 42,
    fieldId:  $fieldId,
);
```

Retypes between numeric / int and datetime are categorically rejected at registry-write time (`IncompatibleRetypeException`) — epoch interpretation is a caller policy, not engine behaviour; bridge through a `string` intermediate field if you need it. Initiating a second retype for the same field while one is already running throws `RetypeInProgressException`. The Reconciler picks up `running` retype checkpoints on every tick (alongside `stardust_sync_queue` and `stardust_import_jobs`); when the partition is exhausted it promotes the slot to `ready`, bumps `stardust_schema_version`, emits `promote_to_ready`, and triggers a one-shot post-backfill `cardinality_sampled` event.

## Async exports

```php
use StarDust\Export\ExportJobRequest;

// Submit an async export. The call enforces a per-tenant active-job
// cap (default ≤ 3 pending+processing) inside one transaction; a 4th
// concurrent submission throws ExportJobActiveCapExceededException.
// Format is 'csv' or 'json'. The filter array is stored verbatim
// for forward compatibility (Phase 7 MVP only consults model_id;
// predicate semantics arrive with the search driver).
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
```

Daemons honour both `SIGTERM`/`SIGINT` (when `ext-pcntl` is loaded) and
`touch <pidFileDir>/<daemon-name>.shutdown` as a graceful-shutdown
signal — useful on hosts without `pcntl`. Exit codes: `0` clean
shutdown (including signal-induced), `1` fatal, `2` singleton
violation or user error.

---

## Running the smoke suite locally

```bash
composer install
cp phpunit.xml.dist phpunit.xml         # gitignored; edit with your DB creds
vendor/bin/phpunit --testsuite Smoke
```

`phpunit.xml.dist` ships with empty `<env>` placeholders for `STARDUST_TEST_DSN`, `STARDUST_TEST_USER`, and `STARDUST_TEST_PASS`. Fill them in on your local `phpunit.xml` copy (which is gitignored). A shell-exported env var with the same name still wins over the file value, so the one-off form also works:

```bash
STARDUST_TEST_DSN='mysql:host=127.0.0.1;dbname=stardust_test' \
STARDUST_TEST_USER=root STARDUST_TEST_PASS=root \
vendor/bin/phpunit --testsuite Smoke
```

The suite covers all nine implemented phases:

- **Phase 0 — environment.** Server is MySQL (not MariaDB), version is 8.0.13+, common table expressions work, and functional unique indexes enforce the partial-uniqueness invariant the schema registry depends on. (`EXPLAIN ANALYZE` is an 8.0.18+ operator-runbook tool and is deliberately **not** smoke-tested.)
- **Phase 1 — bootstrap.** The migration runner creates every data plane, registry, and operational table on a blank database; re-runs are non-destructive; the `stardust_schema_version` singleton is seeded with `id = 1`; the `stardust_slot_assignments` status ENUM rejects out-of-band values; the partial unique index on `field_id` is enforced at the database level; and the tenant-scoped composite indexes on `entry_data` are present.
- **Phase 2 — slot & page system.** Page provisioning emits composite `(tenant_id, slot_column)` indexes only for the filterable slots named by the caller; the full 60-row slot inventory is inserted with `status='free'` in the same registry transaction as the `stardust_schema_version` bump; a forced failure rolls the registry transaction back without leaking partial inventory; sequential calls assign monotonic page numbers; the slot reserver performs the `free → assigned` transition atomically and returns `null` when no free slot of the requested family exists; and the `EmptyTableGuard` rejects DDL against populated pages before any metadata lock is acquired.
- **Phase 3 — write path.** Single-entry writes commit `entry_data` + every live-slot row + (optionally) a `stardust_sync_queue` enqueue in one transaction; the exhaustion-fallback path keeps the write succeeding when slots are missing; uncoercible payload values roll the whole entry back; bulk ingest chunks transactions per `BulkIngestOptions::$chunkSize`, applies the inter-chunk delay only between chunks, and rolls each failed chunk back atomically while later chunks continue; the 1 000-entity synchronous threshold throws `PayloadTooLargeException`; async submission writes a payload artifact under `Config::$artifactDir`, inserts a `stardust_import_jobs` row, and returns an `ImportJobId`; retrying with the same `(tenant_id, idempotency_key)` returns the existing job ID; `tenant_id <= 0` is rejected before any SQL.
- **Phase 4 — read path.** Filters on `is_filterable = false`, `backfilling`, `tombstoned`, or unmapped slots are rejected pre-flight with a typed exception and a `pre_flight_rejected` log event — `EXPLAIN` for an accepted filter shows an index range scan on the `(tenant_id, slot_column)` composite, never a full table scan; cursor pagination over a mutated dataset never duplicates or skips entries that existed before page 1; the trailing page returns a null next-cursor sentinel; `tenant_id` outside `[1, 2^63-1]` is rejected before any SQL; rows from other tenants never appear regardless of filter collision; a field whose slot is `backfilling` returns the value from the JSON payload and never touches the slot column; the schema-version cache emits `cache_miss` on registry-version bumps and reuses the snapshot otherwise.
- **Phase 5 — resilience daemons.** The Watcher provisions a new `entry_slots_page_N` (and its 60 slot rows) when global capacity is below the threshold, bumping `stardust_schema_version.version` in the same transaction; `poll_started` / `provision_complete` / `poll_complete` events fire with `source: 'watcher'`. When a sibling session holds `GET_LOCK('stardust_page_provision', …)`, the Watcher emits `lock_contention` and does not provision (end-to-end test runs with a 1 s timeout via `Config::$watcherProvisionLockTimeoutSeconds` — production stays at 10 s). The PID-file guard throws `WatcherSingletonViolationException` on contention and preserves the last PID after release. The `PollLoop` surfaces SIGTERM / flag-file shutdown within one sleep slice. The cardinality sampler emits `cardinality_sampled` (and `low_cardinality_index` when distinct/selectivity floors are breached) with `source: 'registry'`. The sync-queue work source drains a chunk via `SELECT … FOR UPDATE SKIP LOCKED`, routes `EntryDataMissingException` to `stardust_reconciler_dlq` with `reason='missing_entry_data'`, and rolls the chunk back with a `capacity_wait` event when the entry's field still has no live slot. The import-job work source claims one pending row, decodes the single-document JSON artifact, materialises entries through `EntryWriter::writeWithinTransaction()` in chunk windows paced by `Config::$reconcilerInterChunkDelayMicros`, and transitions to `completed | failed` with a manifest. `Reconciler::tick()` itself ticks work sources round-robin, short-circuits on `CAPACITY_WAIT`, and paces between `WORK_DONE` outcomes using the same inter-chunk delay. The DLQ replayer re-enqueues by id or by reason in a single transaction and throws `DlqReplayNotFoundException` on no-match. A closed-vocabulary guard greps `src/Watcher/`, `src/Reconciler/`, and `src/Liberator/` for `'event' => '...'` literals and asserts the union matches the documented allowlist.
- **Phase 6a — slot reclamation (Liberator).** Tombstoning a slot and starting the Liberator nullifies every non-NULL value in the corresponding `entry_slots_page_N.<slotColumn>` across the partition, transitions the registry row from `tombstoned → free` (and clears `field_id`) in the same transaction as the final chunk's nullification, and bumps `stardust_schema_version.version`. Sweep proceeds in `LIMIT N` chunks; each chunk's `UPDATE` and the `sweep_cursor_id` advance commit together, so a mid-sweep crash resumes deterministically from `last cursor + 1` on restart. On `SQLSTATE 40001` (InnoDB deadlock) the sweeper rolls the chunk back, emits `deadlock_retry`, and retries the same chunk from the same cursor; after three consecutive deadlocks on the same chunk it advances the cursor by `chunkSize`, increments `sweep_gap_count` on the registry row, emits `sweep_gap_flagged`, and continues — bounded contention does not block sweep progress indefinitely. Tombstoned slots are processed `tombstoned_at ASC, page_id, slot_column` (deterministic across restarts). The PID-file guard throws `LiberatorSingletonViolationException` on contention. Closed `source: 'liberator'` event vocabulary: `sweep_started` (per non-empty batch only — idle ticks emit nothing), `sweep_chunk`, `sweep_complete`, `deadlock_retry`, `sweep_gap_flagged`. The bootstrap migration adds the `sweep_gap_count INT NOT NULL DEFAULT 0` column idempotently.
- **Phase 6b — field retype & filterability promotion.** `retypeField()` atomically updates `stardust_fields.declared_type`, tombstones the field's current live slot, reserves a new `backfilling` slot of the target type (or defers if no matching free slot exists), bumps `stardust_schema_version`, and inserts a `running` `backfill_checkpoints` row keyed `retype_field_{id}` — emits `retype_started`. `numeric ↔ datetime` and `int ↔ datetime` retypes raise `IncompatibleRetypeException` at registry-write time with zero registry mutation. Filterability promotion follows the same pipeline but the new slot reservation demands a column with a `(tenant_id, slot_column)` composite index. While the slot is `backfilling`, reads of the field fall back to the JSON payload and filter queries throw `FieldNotIndexedException`. The Reconciler's third work source — `RetypeBackfillWorkSource` — claims one running checkpoint per tick via `SELECT … FOR UPDATE SKIP LOCKED`, runs the normative type-coercion matrix per row through `JSON_EXTRACT`, writes coerced values (or NULL on uncoercible) via `INSERT … ON DUPLICATE KEY UPDATE`, and on the final chunk transitions the slot `backfilling → ready` plus bumps `stardust_schema_version` in the same transaction. Per-row uncoercible values emit `coercion_null` with the closed taxonomy (`out_of_range`, `non_integer`, `malformed_datetime`, `malformed_number`, `epoch_coercion_rejected`, `unparseable`); absent/null JSON values do NOT emit (only attempted-and-failed coercions are observable). Promotion to `ready` emits `promote_to_ready` and triggers `CardinalitySampler::sampleSlot()` for the one-shot post-backfill `cardinality_sampled` event with `trigger='post_backfill'`. Idempotent resume: mid-chunk crash + restart re-processes only entries after `last_processed_id`, and the UPSERT primary key guarantees no double-write. The bootstrap migration adds the `source_declared_type VARCHAR(16) NULL` column to `backfill_checkpoints` idempotently.
- **Phase 7 — async exports (Chronicler).** `submitExport()` enforces the per-tenant active-job cap atomically (`SELECT … FOR UPDATE` + `INSERT` in one transaction) and emits `export_accepted` (source `export_api`); a 4th concurrent submission for the same tenant throws `ExportJobActiveCapExceededException`. `getExportJob()` is tenant-isolated — returns `null` for cross-tenant or missing job ids. The Chronicler's per-tenant round-robin claim orders pending jobs by `MIN(created_at) GROUP BY tenant_id`, computed at claim time without a materialised column — a single tenant's burst cannot starve another tenant's oldest job. Abandoned-claim sweep detects stranded `processing` rows with `heartbeat_at < UTC_TIMESTAMP() - INTERVAL leaseTimeoutSeconds SECOND`, best-effort deletes the prior partial, and resumes from `last_cursor` with `claimed_at` preserved. Lease-loss self-detection at every chunk commit (`WHERE worker_identity = self`, `rowCount() == 0` ⇒ `lease_lost` + delete partial, no terminal-state mutation). End-to-end CSV happy path covers RFC 4180 quoting (comma, double-quote, CR, LF), `\r\n` line terminator, header derived alphabetically from `stardust_fields`, and embedded-NUL → `row_skipped{format_invalid}`. End-to-end JSON happy path validates the streamed single-document array (leading `[`, `,`-prefix for subsequent rows, trailing `]`, exactly `n-1` commas for `n` rows, round-trip through `json_decode`). Three-deadlock budget per chunk + skip-cap (1 000) trip → `failed:excessive_skips`; partial-artifact bytes > 5 GB cap → `artifact_oversized` (distinct event) + `failed:artifact_size_exceeded`; idle ticks GC TTL'd completed artifacts (24 h) and orphaned failed-job partials (1 h); pre-claim disk-pressure gate emits `low_disk` and skips new claims while in-flight jobs continue. Tenant isolation is enforced by the pager's `WHERE tenant_id = ? AND model_id = ? AND deleted_at IS NULL` predicate; soft-deleted rows never appear in artifacts. The closed-vocabulary guard scans `src/Chronicler/` and `src/Export/` for `'event' => '...'` literals — adding an unallowlisted name fails CI.

GitHub Actions runs the same suite on every push, plus a second job that asserts the suite **fails** against MariaDB.

---

## Legacy

The legacy 0.2.x source code has been removed from the repository; it remains available via the `^0.2.0-alpha.x` release tags on Packagist.

---

## License

MIT License.
