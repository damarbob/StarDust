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

**This is a v0.3.0 pre-release.** Phases 0 (operating-environment verification and the package skeleton) and 1 (schema registry and core data plane) are implemented. The engine can now idempotently provision its full physical schema — data plane, registry, and operational tables — onto a fresh MySQL 8.0.13+ database in a single call. Entry ingestion, the read path, and the resilience daemons are not yet wired.

The remaining build sequence — Slot & Page System, Write Path, Read Path, Resilience Daemons, Slot Reclamation, Field Retype, Async Exports, and the Search Driver — is documented in the project's design notes (maintained separately). Each phase is a gate with explicit exit criteria.

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
// PSR-3 logger via Config.

// Phase 1: idempotently provision every physical table the engine
// needs (data plane, schema registry, operational/coordination).
// Safe to call on an already-bootstrapped database.
$engine->bootstrap();
```

That is the entire public surface at this point in the build. Model definition, entry ingestion, and the read path arrive in Phases 2 through 4.

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

The suite covers both implemented phases:

- **Phase 0 — environment.** Server is MySQL (not MariaDB), version is 8.0.13+, common table expressions work, and functional unique indexes enforce the partial-uniqueness invariant the schema registry depends on. (`EXPLAIN ANALYZE` is an 8.0.18+ operator-runbook tool per ADR 0019 / ADR 0023 and is deliberately **not** smoke-tested.)
- **Phase 1 — bootstrap.** The migration runner creates every data plane, registry, and operational table on a blank database; re-runs are non-destructive; the `stardust_schema_version` singleton is seeded with `id = 1`; the `stardust_slot_assignments` status ENUM rejects out-of-band values; the partial unique index on `field_id` is enforced at the database level; and the tenant-scoped composite indexes on `entry_data` are present.

GitHub Actions runs the same suite on every push, plus a second job that asserts the suite **fails** against MariaDB.

---

## Legacy

The legacy 0.2.x source code has been removed from the repository; it remains available via the `^0.2.0-alpha.x` release tags on Packagist.

---

## License

MIT License.
