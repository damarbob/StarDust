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

**This is a v0.3.0 pre-release.** Phases 0 (operating-environment verification and the package skeleton), 1 (schema registry and core data plane), 2 (slot & page system), 3 (write path), and 4 (read path) are implemented. The engine can now idempotently provision its full physical schema, allocate new `entry_slots_page_N` extension pages with the index layout determined by the registry's `is_filterable` flags, atomically reserve free slots for model fields, ingest entries (single rows, sync chunked batches up to 1 000 per call, or larger batches via async submission), **and serve cursor-paginated reads** — the two-query bounded read of ADR 0005 with pre-flight rejection of unindexed/backfilling/unmapped filter targets, tenant-isolated SQL on every `WHERE` and `JOIN`, and an in-process schema-version cache keyed on `stardust_schema_version.version`. Fields whose slot is mid-retype or unmapped fall back to the JSON payload transparently. When a write field has no live slot the write still degrades gracefully to `stardust_sync_queue` so writes never block on capacity exhaustion. The four resilience daemons (Watcher, Reconciler, Liberator, Chronicler) are not yet wired.

The remaining build sequence — Resilience Daemons, Slot Reclamation, Field Retype, Async Exports, and the Search Driver — is documented in the project's design notes (maintained separately). Each phase is a gate with explicit exit criteria.

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

1. **Persistent background processes or long-running containers** — systemd, supervisor, Docker / Kubernetes / ECS, or equivalent. Cron-only invocation is not supported in v1; a future `--once` mode is deliberately deferred but not foreclosed.
2. **MySQL 8.0.13+ or Percona 8.0.13+** (also covered by the Requirements section above).
3. **PHP 8.x with CLI access** for the `bin/stardust` entry point.
4. **Local filesystem write access** for the Chronicler's async export artifacts (a mounted volume in container deployments).
5. **PID-file or orchestrator-level Watcher singleton enforcement** — the in-database advisory lock is a safety net, not the primary enforcement mechanism.

**Supported deployment tiers:**

| Tier | Verdict |
| :--- | :--- |
| Free shared hosting (no shell, no cron, no persistent processes) | Unsupported. |
| Paid shared hosting (cron only) | Unsupported in v1; awaits a future `--once`-mode ADR. |
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
// (NDJSON to stdout per ADR 0020) unless you inject your own
// PSR-3 logger via Config. Optional Config::$artifactDir overrides
// where async bulk-ingest payloads are persisted (defaults to
// sys_get_temp_dir() . '/stardust').

// Phase 1: idempotently provision every physical table the engine
// needs (data plane, schema registry, operational/coordination).
// Safe to call on an already-bootstrapped database.
$engine->bootstrap();
```

Phase 2's page provisioner and slot reserver remain internal classes (`StarDust\Page\PageProvisioner`, `StarDust\Slot\SlotReserver`) that Phase 5's Watcher daemon will wire; the Watcher and other daemons are not yet shipped. Model and field definition remain a registry-only concern until a future operator surface lands.

## Writing entries

```php
use StarDust\Write\BulkIngestOptions;
use StarDust\Write\EntryPayload;

// Single-entry write. Atomic INSERT into entry_data + per-page
// INSERT … ON DUPLICATE KEY UPDATE into entry_slots_page_N for
// each field with a live slot; falls back to stardust_sync_queue
// (in the same transaction) if any field lacks a live slot
// (ADR 0007 exhaustion fallback — the call still succeeds).
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

`tenant_id` is validated at every entry point (must be `>= 1`) before any SQL executes. All write-path operations emit structured-log events per ADR 0020 — `entry_written`, `exhaustion_fallback`, `bulk_chunk_committed`, `bulk_chunk_rolled_back`, `bulk_accepted`, `payload_too_large`.

## Reading entries

```php
use StarDust\Read\Cursor;
use StarDust\Read\EntryQuery;
use StarDust\Read\QueryFilter;

// Cursor-paginated read. Two-query bounded sequence per ADR 0005:
//   1) Paginated Probe selects entry_data.id with LIMIT pageSize+1
//      (the extra row is the sole next-page signal — no COUNT(*),
//      no OFFSET, per ADR 0006).
//   2) Bounded Fetch materialises only those IDs plus the indexed
//      slot columns needed to assemble the caller's selectFields.
// Filters on fields with is_filterable=false or whose slot is
// backfilling/tombstoned/unmapped are rejected pre-flight per
// ADR 0004 with a typed exception — no SQL is issued.
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

Fields are sourced from the joined slot column when the slot's status is `assigned` or `ready`; otherwise — `backfilling`, `tombstoned`, or unmapped — they fall back to the JSON payload stored in `entry_data.fields`. This satisfies ADR 0007's write-availability invariant on the read side: a field that lacks an indexed slot still surfaces, just without filter / sort capability. The read path emits ADR 0020 events `request` and `pre_flight_rejected`; `cache_miss` is emitted by the in-process schema-version cache on registry-version bumps.

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
```

Daemon and operator commands (`watcher`, `reconciler`, `liberator`, `chronicler`, `reconciler:dlq:replay`, `backfill`) land in later phases.

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

The suite covers all five implemented phases:

- **Phase 0 — environment.** Server is MySQL (not MariaDB), version is 8.0.13+, common table expressions work, and functional unique indexes enforce the partial-uniqueness invariant the schema registry depends on. (`EXPLAIN ANALYZE` is an 8.0.18+ operator-runbook tool per ADR 0019 / ADR 0023 and is deliberately **not** smoke-tested.)
- **Phase 1 — bootstrap.** The migration runner creates every data plane, registry, and operational table on a blank database; re-runs are non-destructive; the `stardust_schema_version` singleton is seeded with `id = 1`; the `stardust_slot_assignments` status ENUM rejects out-of-band values; the partial unique index on `field_id` is enforced at the database level; and the tenant-scoped composite indexes on `entry_data` are present.
- **Phase 2 — slot & page system.** Page provisioning emits composite `(tenant_id, slot_column)` indexes only for the filterable slots named by the caller; the full 60-row slot inventory is inserted with `status='free'` in the same registry transaction as the `stardust_schema_version` bump; a forced failure rolls the registry transaction back without leaking partial inventory; sequential calls assign monotonic page numbers; the slot reserver performs the `free → assigned` transition atomically and returns `null` when no free slot of the requested family exists; and the ADR 0012 `EmptyTableGuard` rejects DDL against populated pages before any metadata lock is acquired.
- **Phase 3 — write path.** Single-entry writes commit `entry_data` + every live-slot row + (optionally) a `stardust_sync_queue` enqueue in one transaction; the exhaustion-fallback path keeps the write succeeding when slots are missing; uncoercible payload values roll the whole entry back; bulk ingest chunks transactions per `BulkIngestOptions::$chunkSize`, applies the inter-chunk delay only between chunks, and rolls each failed chunk back atomically while later chunks continue; the 1 000-entity synchronous threshold throws `PayloadTooLargeException`; async submission writes a payload artifact under `Config::$artifactDir`, inserts a `stardust_import_jobs` row, and returns an `ImportJobId`; retrying with the same `(tenant_id, idempotency_key)` returns the existing job ID; `tenant_id <= 0` is rejected before any SQL.
- **Phase 4 — read path.** Filters on `is_filterable = false`, `backfilling`, `tombstoned`, or unmapped slots are rejected pre-flight with a typed exception and a `pre_flight_rejected` log event — `EXPLAIN` for an accepted filter shows an index range scan on the `(tenant_id, slot_column)` composite, never a full table scan; cursor pagination over a mutated dataset never duplicates or skips entries that existed before page 1; the trailing page returns a null next-cursor sentinel; `tenant_id` outside `[1, 2^63-1]` is rejected before any SQL; rows from other tenants never appear regardless of filter collision; a field whose slot is `backfilling` returns the value from the JSON payload and never touches the slot column; the schema-version cache emits `cache_miss` on registry-version bumps and reuses the snapshot otherwise.

GitHub Actions runs the same suite on every push, plus a second job that asserts the suite **fails** against MariaDB.

---

## Legacy

The legacy 0.2.x source code has been removed from the repository; it remains available via the `^0.2.0-alpha.x` release tags on Packagist.

---

## License

MIT License.
