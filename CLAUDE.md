# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project status

StarDust is mid-migration to a **Vertical Schema Partitioning** architecture and currently sits at `0.3.0-alpha.1`. Phases 0 through 8 are implemented:

| Phase | What shipped | Deep notes |
| :-- | :-- | :-- |
| 0 | Operating environment + framework-neutral package skeleton. | — |
| 1 | Schema registry + core data plane DDL, idempotent and non-destructive. | `src/Bootstrap/` |
| 2 | Slot & page system — page provisioner, slot reserver, ADR 0012 empty-table guard. | `src/Page/`, `src/Slot/` |
| 3 | Write path — single-entry write, slot extraction, chunked synchronous bulk ingest, async bulk-ingest submission, ADR 0007 exhaustion fallback. | `src/Write/` |
| 4 | Read path — two-query bounded read, cursor pagination, ADR 0004 pre-flight rejection, ADR 0015 schema-version cache, JSON payload fallback. | `src/Read/` |
| 5 | Resilience daemons — singleton Watcher with capacity- and demand-driven provisioning under `GET_LOCK` (ADR 0035) plus the ADR 0019 cardinality advisory; multi-worker Reconciler draining `stardust_sync_queue` and `stardust_import_jobs` via SKIP LOCKED, with ADR 0018 DLQ + operator replay and the ADR 0007 exhaustion reservation that makes `pending_demand` self-draining. | `src/Daemon/`, `src/Watcher/`, `src/Reconciler/` |
| 6a | Slot reclamation — singleton Liberator sweeping `tombstoned` slots through chunked nullification with `sweep_cursor_id` checkpointing, bounded deadlock retry (ADR 0009), and a `sweep_gap_count` annotation for operator review. | `src/Liberator/` |
| 6b | Field retype & filterability promotion — atomic registry transaction, `backfill_checkpoints` row keyed `retype_field_{id}`, a third Reconciler work source draining the `(tenant_id, model_id)` partition through the ADR 0024 coercion matrix, `backfilling → ready` on the final chunk. | `src/Retype/` |
| 7 | Async exports — submission API enforcing a per-tenant active-job cap atomically; multi-worker Chronicler claiming jobs round-robin with abandoned-claim recovery, streaming CSV/JSON artifacts, ADR 0025 failure semantics, idle-cycle GC, disk-pressure gate. | `src/Export/`, `src/Chronicler/` |
| 8 | Search driver / adapter — closed `FilterNode` AST with full AND/OR/NOT (ADR 0021) and twelve closed-v1 operators; JSON wire-format decoder with the 13-code error taxonomy; `EntrySearchInterface` capability contract (ADR 0022); `MysqlNativeDriver` with an adaptive JOIN/EXISTS compiler; three-stage pre-flight pipeline. | `src/Filter/`, `src/Search/` |

The remaining build sequence is gated on ADR-tracked exit criteria. **The `0.3.0-alpha.1` tag stays put while v0.3.0 is in progress — do not bump it per-phase.**

The legacy 0.2.x line (CodeIgniter 4 + Virtual Columns) was removed in `02eccd4` and lives on as `^0.2.0-alpha.x` Packagist tags. Do not port code back from it.

## Where the design lives

**Design authority is the SDDPG design repo at [SDDPG/](SDDPG/)** — a separate repository (`github.com/damarbob/SDDPG`) cloned into the project root, so it is never tracked by this one and the links below resolve only when it is present. `CONTRIBUTING.md` tells contributors to set it up the same way. Every `ADR NNNN` reference in this repo resolves to [SDDPG/adrs/](SDDPG/adrs/) as `NNNN-slug.md`. Blueprint and `§` citations resolve to:

- [SDDPG/architecture_blueprint.md](SDDPG/architecture_blueprint.md) — "Architecture Blueprint §N" (e.g. the §1.2 tenant-isolation invariant).
- [SDDPG/blueprints/](SDDPG/blueprints/) — per-subsystem acceptance criteria; "AC#N" citations live here (`watcher_reconciler_daemons.md`, `liberator_daemon.md`, `chronicler_daemon.md`, `async_exports.md`, `queryfilter_wire_format.md`, `search_driver_adapter.md`).
- [SDDPG/schemas/schema_reference.md](SDDPG/schemas/schema_reference.md) — "schema_reference §N". [SDDPG/schemas/queryfilter.schema.json](SDDPG/schemas/queryfilter.schema.json) is the source of truth for the in-package copy at [schemas/queryfilter.schema.json](schemas/queryfilter.schema.json); change the SDDPG one first.
- [SDDPG/glossary.md](SDDPG/glossary.md), [SDDPG/implementation_phases.md](SDDPG/implementation_phases.md), [SDDPG/runbooks/](SDDPG/runbooks/).

**Search the ADRs before surfacing a design question as open** — most already have a ruling. On any conflict between a doc and an ADR, the ADR governs.

**Status semantics.** An ADR's `Status` is a human-review milestone, not a build gate: `Proposed` never blocks implementation, and the whole engine was built while every ADR was still Proposed. Acceptance means the maintainer has personally reviewed that ADR and internalised it — so **new ADRs always enter as `Proposed`, and only the maintainer flips one to `Accepted`.** Never change a status on your own initiative. Acceptance is also need-driven rather than linear: read the individual ADR's `Status` line rather than assuming everything below some number is accepted. When new work leans on a still-`Proposed` ADR, say so — review has changed ADR semantics before and can again.

**Amending an ADR.** A `Proposed` ADR may be edited freely in place; the append-only discipline binds `Accepted` ones. For those, edit in place only for editorial fixes — a change that alters what code someone would write needs a **new** ADR plus a dated pointer added to the superseded one.

### Sibling docs and what each owns

- [CONTRIBUTING.md](CONTRIBUTING.md) — the human entry point: requirements, setup, the three pre-push commands, and a table of which conventions are enforced by tests. Deliberately thin and pointer-heavy; put depth in this file or TESTING.md rather than growing it.
- [AGENTS.md](AGENTS.md) — tool-neutral agent entry point (Cursor, Codex, and others read it, as this file is Claude Code's). **A pointer, not a copy** — never duplicate guidance into it.
- [.agent/rules/](.agent/rules/) — commit-message and changelog style, in the format Windsurf reads. Keep it to conventions that hold for a framework-neutral library; anything phrased in terms of a framework's helpers does not belong here.
- [TESTING.md](TESTING.md) — contributor-facing narrative of what the smoke suite *proves*, phase by phase. This file owns test *conventions* (fixtures, base classes, gotchas); TESTING.md owns *coverage claims*. Keep them from contradicting each other.
- [README.md](README.md) — consumer-facing, and a first-class deliverable. When a phase ships, update it in the same change as this file: Status line, usage examples, smoke-suite bullets. **README must never cite an ADR** — ADRs are internal; the README explains behaviour on its own terms.
- [CHANGELOG.md](CHANGELOG.md) — Keep a Changelog format, grouped under the single `[0.3.0-alpha.1]` heading for the whole v0.3.0 build. **It is consumer-facing and written at release time, not per phase — do not add entries for unreleased work.** Phases 2–8 have no entries for exactly this reason; the running record of what shipped lives in the internal roadmap instead. Whatever does eventually go here must read to someone who has only ever seen this package on Packagist: no ADR numbers, and no internal build-sequencing vocabulary (phase names are fine, they are defined in this file's own table).
- `.markdownlint.json` — `MD013` (line length) is off, so the long single-paragraph bullets in these files are intentional; don't rewrap to satisfy a linter. `MD024` is `siblings_only`.

## Commands

```bash
# Five-minute local quickstart: MySQL + bootstrap + seed + all four
# daemons via Docker Compose. Seed output (a filtered query) prints in
# the init service: `docker compose logs init`. (docker/Dockerfile,
# docker/seed.php, docker-compose.yml — dev-only, NOT a production target.)
docker compose up

# Install dependencies (runtime: psr/log + psr/clock only; dev adds
# phpunit, phpstan, opis/json-schema)
composer install

# Static analysis — PHPStan level 8 over src/ + bin/. No database needed.
# This is the FIRST CI job: run it before calling any change done.
vendor/bin/phpstan analyse

# Markdown lint — same version and globs CI uses. No database needed.
npx --yes markdownlint-cli2@0.23.2 "*.md" "src/**/*.md" ".agent/**/*.md"

# Run the full smoke suite — requires a reachable MySQL 8.0.13+
cp phpunit.xml.dist phpunit.xml          # gitignored; edit with DB creds
vendor/bin/phpunit --testsuite Smoke

# One-off run via shell env (overrides the phpunit.xml values; force="false")
STARDUST_TEST_DSN='mysql:host=127.0.0.1;dbname=stardust_test' \
  STARDUST_TEST_USER=root STARDUST_TEST_PASS=root \
  vendor/bin/phpunit --testsuite Smoke

# Run a single test class or method
vendor/bin/phpunit --testsuite Smoke --filter BootstrapTest
vendor/bin/phpunit --testsuite Smoke --filter testBootstrapIsIdempotentAndNonDestructive

# CLI: idempotent schema bootstrap (Phase 1)
STARDUST_DSN='mysql:host=127.0.0.1;dbname=app' \
  STARDUST_USER=root STARDUST_PASS=root \
  bin/stardust bootstrap

# CLI: Phase 5 daemons (long-running). Watcher is a strict singleton
# via flock on $pidFileDir/watcher.pid; Reconciler is multi-worker safe.
STARDUST_DSN='mysql:host=127.0.0.1;dbname=app' \
  STARDUST_USER=root STARDUST_PASS=root \
  bin/stardust watcher
bin/stardust reconciler

# CLI: Phase 5 operator-initiated DLQ replay
bin/stardust reconciler:dlq:replay --id=42
bin/stardust reconciler:dlq:replay --reason=missing_entry_data

# CLI: Phase 6a Liberator (long-running, singleton via flock on
# $pidFileDir/liberator.pid). Sweeps tombstoned slots back to free
# via chunked nullification on entry_slots_page_X.
bin/stardust liberator

# CLI: Phase 7 Chronicler (long-running, multi-worker — no PID guard).
# Claims pending or abandoned export jobs from stardust_export_jobs
# under FOR UPDATE SKIP LOCKED, paginates entry_data, streams CSV/JSON
# artifacts to $artifactDir, runs idle-cycle GC. Run multiple processes
# for horizontal scale.
bin/stardust chronicler

# CLI: ADR 0031 slot-spread advisory, on demand. Registry-only and
# read-only — safe against production at any time. Prints a table and
# emits spread_sampled / high_spread_model on the registry source.
bin/stardust spread:report
bin/stardust spread:report --tenant=1 --model=7

# CLI: ADR 0033 model compaction (long-running, operator-initiated).
# Relocates one model's filterable slots onto the fewest pages that can
# hold them, one field at a time. NEEDS a running reconciler to make
# progress. Filters on the in-flight field are rejected until it lands.
# Safe to re-run: already-relocated fields are no-ops.
bin/stardust compact:model --tenant=1 --model=7 --dry-run
bin/stardust compact:model --tenant=1 --model=7

bin/stardust --version
bin/stardust --help
```

The smoke suite **skips** (does not fail) when `STARDUST_TEST_DSN` / `STARDUST_TEST_USER` are unset. CI provides them via the MySQL service container. `phpunit.xml.dist` sets `failOnWarning`, `failOnRisky`, `beStrictAboutOutputDuringTests`, and `beStrictAboutTestsThatDoNotTestAnything` — keep tests strict-clean.

CI ([.github/workflows/ci.yml](.github/workflows/ci.yml)) runs four jobs: `static-analysis` (PHPStan, no DB), `markdown-lint` (markdownlint, no DB), `mysql-smoke` (the suite across the full PHP matrix), and `mariadb-rejection` (below).

## PHP floor and static analysis

- **The floor is PHP 8.1** (`composer.json` requires `^8.1`) and CI runs the smoke suite on **8.1, 8.2, 8.3, and 8.4**. [phpstan.neon.dist](phpstan.neon.dist) pins `phpVersion: {min: 80100, max: 80400}`, so the analyser enforces the whole supported span locally — you no longer have to push and wait for the 8.1 matrix job to find out. Do not use syntax newer than 8.1 — `readonly` *classes*, `json_validate()`, DNF types, and `#[\Override]` all compile locally on 8.4 and break the 8.1 job. Readonly *properties* and enums are 8.1 and fine. Where a note in these files mentions 8.4 behaviour (e.g. `Exception::$code` readonly redeclaration, the `str_getcsv()` `$escape` deprecation), that is a *constraint the code must satisfy on 8.4*, not permission to target 8.4.
- **PHPStan runs at level 8** over `src/` and `bin/` ([phpstan.neon.dist](phpstan.neon.dist)) and reports **zero errors with no baseline file**. Do not reintroduce one: if new code can't pass level 8, fix the code or say so explicitly.
- **When PHPStan calls a runtime guard redundant, suspect the phpdoc before the guard.** PHP enforces only `array` — never `list<string>` or an `array{...}` shape — so a docblock can promise more than the runtime delivers, and the analyser will then flag the check that defends against the difference. Several `@param` types are deliberately widened to `array<mixed>` for exactly this reason, each with a comment saying so. The rule: where a method's job is to validate or normalise untrusted input, its parameter type must admit the input it is defending against.
- **Level 8 is the deliberate ceiling.** Measured before the raise: level 9 → 163 errors, level 10 → 259, against 31 for level 8. Level 9's cost is concentrated in `offsetAccess.nonOffsetAccessible` / `cast.int` / `cast.string` at PDO and `json_decode` boundaries, where ADR 0013 makes values `mixed` by design — `(int) $row['tenant_id']` is already a total operation, and satisfying the analyser there means narrowing ceremony rather than safety. Reaching 9 honestly means typed row hydration per repository, which is an architectural decision, not a config change. Don't raise the level without making that decision first.
- **`PDO::query()` goes through `Support\PdoQuery::run()`**, which throws when the driver returns `false` instead of raising. The engine takes an injected PDO, so a consumer on `ERRMODE_SILENT` really can get `false` back. Two older sites (`GcSweeper`, `SchemaVersionCache`) handle it inline with different semantics and are deliberately left alone.

## Database support is intentionally narrow

- **Supported:** MySQL 8.0.13+ or Percona 8.0.13+. The floor is non-negotiable — Phase 1 relies on functional/conditional unique indexes (8.0.13) and CTEs.
- **Unsupported and actively rejected:** MariaDB and MySQL ≤ 5.7. The CI workflow runs a second job (`mariadb-rejection`) that **expects the smoke suite to fail** against MariaDB; if you change environment checks, this job must still be tripped. `EnvironmentTest::testServerIsMySql` is the primary rejection gate.
- `EXPLAIN ANALYZE` is an 8.0.18+ runbook tool only (ADR 0019/0023) — do **not** add it to the smoke suite.

## Architecture

The engine ships as a framework-neutral Composer library. Zero framework / ORM / query-builder runtime dependencies — only `psr/log` and `psr/clock` interfaces.

**Most subsystems carry their own `CLAUDE.md`** next to the code, holding the design rationale, invariants, and traps for that package. Those load automatically when you work in the directory; read the relevant one before changing anything non-trivial there. This section is the map, not the detail.

### Entry points (documented here — they are the public surface)

- **[src/StarDust.php](src/StarDust.php):** `StarDust\StarDust` holds the injected `Config` and exposes typed accessors (`pdo()`, `logger()`, `config()`) plus:
  - `bootstrap()` (Phase 1) and `schemaBuilder()`.
  - Three Phase 3 write methods — `write(EntryPayload): EntryWriteResult`, `bulkWrite(list<EntryPayload>, ?BulkIngestOptions): BulkIngestResult`, `submitBulkWrite(int $tenantId, list<EntryPayload>, ?string $idempotencyKey): ImportJobId`.
  - Two entry-mutation methods — `updateEntry(int $tenantId, int $entryId, array $fields): EntryWriteResult` (full replace / PUT, clearing the slots of omitted fields; throws `EntryNotFoundException`) and `deleteEntry(int $tenantId, int $entryId): bool` (soft delete, idempotent, returns `false` rather than throwing). Details and the reason for the throw/return asymmetry: `src/Write/CLAUDE.md`.
  - Two Phase 4 read methods — `read(EntryQuery): EntryPage`, `get(int $tenantId, int $entryId): ?Entry`.
  - Phase 5 daemon factories — `watcher()`, `reconciler()`, `dlqReplayer()`, `pollLoop()`, `shutdownSignal(string $daemonName)`.
  - The Phase 6a factory `liberator()`.
  - Three Phase 6b lifecycle initiators — `retypeField(int $tenantId, int $fieldId, string $newDeclaredType): void`, `promoteFieldToFilterable(int $tenantId, int $fieldId): void`, `demoteFieldFromFilterable(int $tenantId, int $fieldId): void`. All three delegate to `RetypeInitiator::initiate()`; demotion is the `newIsFilterable: false` tuple, which was implemented and tested from Phase 6b but only reached the facade later.
  - Two read-only registry introspection methods — `listModels(int $tenantId): list<ModelSummary>`, `describeModel(int $tenantId, int $modelId): ?ModelDescription`, backed by `Schema\SchemaReader`.
  - Three Phase 7 entry points — `submitExport(ExportJobRequest): ExportJobId`, `getExportJob(int $tenantId, int $jobId): ?ExportJob`, `chronicler(): Chronicler`.
  - The Phase 8 search entry point — `search(SearchRequest): SearchResult`, which runs the active `EntrySearchInterface` driver through the pre-flight pipeline and defaults to `MysqlNativeDriver` when `Config::$searchDriver` is `null`.
  - The ADR 0033 compaction entry point — `compactModel(int $tenantId, int $modelId, bool $dryRun = false): CompactionPlan`. **Long-running and operator-initiated**: it relocates one field at a time and blocks until the Reconciler drains each, so it needs a running `bin/stardust reconciler` and must never be called from a request path. `$dryRun` plans and returns without mutating or emitting.

  Phase 8 also re-routes `read()` and `get()` through the same `SearchService`, so `EntryReader` collapsed to a thin façade and `QueryValidator` was deleted — **the public signatures are unchanged for Phase 4 callers**. Each factory lazily constructs its collaborator from `$this->config`. The `reconciler()` factory wires THREE work sources — `SyncQueueWorkSource`, `ImportJobWorkSource`, `RetypeBackfillWorkSource` — in that round-robin order. Later phases append entry points here without breaking this surface. `VERSION` lives on this class.

- **Configuration — [src/Config/Config.php](src/Config/Config.php):** Single construction-time DTO per **ADR 0026**. Required: `PDO`; everything else optional with a default. Fields are `readonly`, and new phases **append** constructor params rather than repurposing existing ones. Full field inventory and the property-order-vs-constructor-order caveat: `src/Config/CLAUDE.md`.

### Subsystems (detail lives beside the code)

| Package | What it is | Phase |
| :-- | :-- | :-- |
| [src/Bootstrap/](src/Bootstrap/) | Idempotent migration runner. See below — no nested file; the invariants are in "Schema invariants". | 1 |
| [src/Schema/](src/Schema/) | `SchemaBuilder` onboarding helper. See below. | — |
| [src/Page/](src/Page/) | `PageProvisioner` (extension capacity + slot inventory) and the ADR 0012 `EmptyTableGuard`. | 2 |
| [src/Slot/](src/Slot/) | `SlotReserver` (the ADR 0034 filterability chokepoint, with ADR 0032 model affinity) and `IndexedSlotPredicate`. | 2 |
| [src/Write/](src/Write/) | `EntryWriter`, `PayloadSplitter`, `LiveSlotMap`, `BulkIngestor`, `BulkIngestSubmitter`, `BackfillExecutor`. | 3 |
| [src/Read/](src/Read/) | The ADR 0005 two-query bounded read. Largely hollowed out by Phase 8 — read `src/Search/CLAUDE.md` too. | 4 |
| [src/Daemon/](src/Daemon/) | Shared scaffolding: `PollLoop`, `Tickable`, `ShutdownSignal`, `PidFileGuard`, `AdvisoryLock`. | 5 |
| [src/Watcher/](src/Watcher/) | Singleton page provisioner + `CardinalitySampler` + the ADR 0031 `SpreadSampler`. Demand-driven since ADR 0035. | 5 / 6b |
| [src/Reconciler/](src/Reconciler/) | Multi-worker drain over three work sources, the ADR 0018 DLQ and replayer, and the ADR 0007 exhaustion reservation. | 5 |
| [src/Liberator/](src/Liberator/) | Singleton slot reclamation with the ADR 0009 deadlock/gap path. | 6a |
| [src/Retype/](src/Retype/) | Retype + filterability-promotion lifecycle and the ADR 0024 coercion matrix. | 6b |
| [src/Export/](src/Export/) | Synchronous export submission with the atomic per-tenant cap. | 7 |
| [src/Chronicler/](src/Chronicler/) | Multi-worker export drain, artifact streaming, ADR 0025 failure semantics, GC. | 7 |
| [src/Filter/](src/Filter/) | Filter AST, JSON wire decoder, the 13-code validation taxonomy. Registry-free. | 8 |
| [src/Compaction/](src/Compaction/) | ADR 0033 operator-initiated model compaction: pure planner, registry projection, sequential orchestrator. | — |
| [src/Search/](src/Search/) | Driver contract, pre-flight pipeline, `SearchService`, MySQL driver + adaptive SQL compiler. | 8 |
| [src/Logging/](src/Logging/) | `StdoutNdjsonLogger` and the ADR 0020 closed event vocabulary. | all |
| [src/Exception/](src/Exception/) | The typed-error taxonomy. | all |

### Small packages, documented here

- **Schema runner — [src/Bootstrap/Bootstrapper.php](src/Bootstrap/Bootstrapper.php):** Idempotent migration runner. Creates the data plane (`entry_data`, `stardust_sync_queue`), schema registry (`stardust_models`, `stardust_fields`, `stardust_pages`, `stardust_slot_assignments`), and operational tables (`stardust_schema_version`, `stardust_export_jobs`, `stardust_import_jobs`, `stardust_reconciler_dlq`, `backfill_checkpoints`). Safe to re-run; **no DDL is destructive** — a load-bearing invariant verified by `testBootstrapIsIdempotentAndNonDestructive`. New tables for later phases must land in new private methods alongside the existing ones, never as edits to existing method bodies.
- **Schema builder — [src/Schema/SchemaBuilder.php](src/Schema/SchemaBuilder.php):** Convenience helper (NOT a phase deliverable, NOT the first-class definition API — that is still unbuilt) added for onboarding ergonomics so the first-run experience doesn't require hand-written `stardust_models` / `stardust_fields` SQL. `createModel(int $tenantId, string $name, list<FieldDefinition> $fields = []): ModelDefinition`, `defineModel()`, and `defineField()` are all get-or-create (idempotent — a name that already exists returns its id unchanged, so a seed/setup script is safe to re-run) and follow the transaction+log discipline: one transaction per `createModel()` call that bumps `stardust_schema_version` exactly once iff a row was actually inserted (matches schema_reference §5.1 "field metadata changes increment version"). It registers registry rows ONLY — it does not provision pages or reserve slots, so making a field genuinely filterable still goes through `PageProvisioner` + `SlotReserver` (or the Watcher). Surfaced via `StarDust::schemaBuilder()`. It deliberately stays narrow; do not grow it into the full definition API. **Read-side introspection lives in a separate class for exactly that reason** — `Schema\SchemaReader` (`listModels()`, `describeModel()`) is lock-free, mutation-free, version-bump-free, and safe per request, so keeping it off `SchemaBuilder` lets the write-side stopgap be replaced without dragging the read side with it. Its DTOs are `ModelSummary`, `ModelDescription`, and `FieldDescription`; the last carries **both** `isFilterable` (registry intent) and `isIndexed` (a live `assigned|ready` slot, i.e. a filter works right now). Those diverge for the whole of a promotion or retype backfill — `SchemaReader::QUERYABLE_STATUSES` is deliberately narrower than `LiveSlotMap::LIVE_STATUSES`, excluding `backfilling`, because ADR 0004 rejects filters against it. Smoke coverage: `tests/Smoke/Schema/SchemaReaderTest`. `FieldDefinition` (input DTO: `name`, `declaredType`, `isFilterable`) and `ModelDefinition` (result: `modelId` + `fieldName → id` map, `fieldId()` accessor) live alongside it. Smoke coverage: `tests/Smoke/Schema/SchemaBuilderTest`.
- **Support — [src/Support/UuidV4.php](src/Support/UuidV4.php):** The one shared utility. `UuidV4::generate(): string` is the source of every `correlation_id` / `chunk_correlation_id` in the daemons and of the uniqueness suffix in `WorkerIdentity::mint()`. Covered by `tests/Smoke/UuidV4Test`.
- **Clock — [src/Clock/SystemClock.php](src/Clock/SystemClock.php):** `psr/clock` default; UTC `DateTimeImmutable`. Always inject this rather than calling `new DateTime` directly, so tests can swap in a frozen clock.

### Two cross-cutting rules worth knowing without opening a nested file

- **Adding a new log event name requires an ADR 0020 update**, or `tests/Smoke/EventVocabularyTest` fails. It greps eight `src/` directories for `'event' => '...'` literals and asserts the union is a subset of the allowlist. Full vocabulary: `src/Logging/CLAUDE.md`.
- **`NonFilterableFieldSlotException` and `FieldNotFilterableException` are different exceptions.** The first is a reservation-time invariant violation ("your code asked the registry for something the architecture forbids"); the second is the read-path pre-flight rejection for a filter targeting such a field ("fix your query"). One word apart, and that is exactly why both exist. Full taxonomy: `src/Exception/CLAUDE.md`.

### Schema invariants worth knowing before editing DDL

- **`stardust_slot_assignments.status`** is a closed five-state ENUM: `free | assigned | tombstoned | backfilling | ready`. Out-of-band values must be rejected at the database level (relies on default 8.0 `STRICT_TRANS_TABLES`). Live states are `assigned | backfilling | ready` — Phase 3's write path materializes into all three.
- **`ux_slot_assignments_field_live`** is a functional partial UNIQUE index implementing **ADR 0017**'s "at most one live slot per field" invariant via a `CASE … END` over `status`. MySQL has no `CREATE INDEX IF NOT EXISTS`, so `Bootstrapper::ensureSlotAssignmentFieldLiveUniqueIndex()` probes `information_schema.STATISTICS` first to stay idempotent. Do not collapse this back into the `CREATE TABLE`.
- **`stardust_slot_assignments.sweep_gap_count`** (Phase 6a) is the Liberator's `INT NOT NULL DEFAULT 0` annotation. Incremented inside the gap path (3rd consecutive deadlock on the same chunk) so operators can spot slots whose sweep skipped over rows. `Bootstrapper::ensureSlotAssignmentSweepGapColumn()` probes `information_schema.COLUMNS` and runs `ALTER TABLE … ADD COLUMN` only when missing — same idempotency pattern as the functional unique index above. The column is **intentionally preserved across the `tombstoned → free` reclaim**; resetting it to 0 is the future `SlotReserver`'s concern on the next `free → assigned` transition, not the Liberator's.
- **`backfill_checkpoints.source_declared_type`** (Phase 6b) is the `VARCHAR(16) NULL` annotation the retype work source needs to pick the right ADR 0024 matrix cell. The `RetypeInitiator` overwrites `stardust_fields.declared_type` to the target in the same tx that inserts the checkpoint row, so the source type cannot be recovered from the field row afterwards — it lives on the checkpoint instead. Nullable because the (not-yet-built) Backfill Pump CLI uses operator-supplied `job_name`s with no retype semantics. `Bootstrapper::ensureBackfillCheckpointsSourceTypeColumn()` follows the same idempotent `information_schema.COLUMNS` probe pattern as Phase 6a's `sweep_gap_count`.
- **`stardust_schema_version`** is a singleton enforced by `PRIMARY KEY (id)` + `CHECK (id = 1)` + a seed step (`INSERT ... ON DUPLICATE KEY UPDATE id = id`). The CHECK is advisory only on MySQL 8.0.13–8.0.15 (silently dropped); the PK + seed are the real guarantee.
- **`stardust_reconciler_dlq`** intentionally has **no FK to `entry_data`** — the `missing_entry_data` reason exists so DLQ rows outlive their source row (ADR 0018). Do not "fix" this.
- **`stardust_sync_queue`** has only a PK by design (schema reference §3); Phase 5 ships without adding sync_queue indexes — the PK is sufficient for `SELECT … ORDER BY id LIMIT N FOR UPDATE SKIP LOCKED` because InnoDB locks rows in PK order. A future workload-driven ADR can add one as a standalone change.
- **`stardust_import_jobs`** (Phase 3) enforces ADR 0011 idempotency via `UNIQUE (tenant_id, idempotency_key)`. MySQL UNIQUE allows multiple NULL `idempotency_key` rows, so unkeyed submissions never collide. Phase 5's `ImportJobWorkSource` transitions `pending → processing → completed | failed` and populates `manifest`, `worker_identity`, `claimed_at`, `heartbeat_at`, `failed_reason`, `completed_at`. The `manifest` is now written **chunk-by-chunk** (not only at completion): it doubles as the resume checkpoint. The `INDEX (status, heartbeat_at)` backs the abandoned-claim sweep — `ImportJobWorkSource` re-claims a `processing` job whose `heartbeat_at` lapsed past `Config::$reconcilerImportLeaseTimeoutSeconds` (default 30 s) and resumes from `manifest.entries_written`, with the prior worker self-aborting on a `worker_identity` mismatch (Gap 5 resolution, 2026-06-18 — mirrors the Chronicler).
- **`stardust_export_jobs`** (provisioned in Phase 1, consumed in Phase 7) needs no Phase 7 DDL — every column and index the Chronicler reads or writes (`status`, `filter` JSON, `format`, `last_cursor`, `artifact_path`, `failed_reason`, `skip_count`, `worker_identity`, `claimed_at`, `heartbeat_at`, `completed_at`, plus four indexes: `(status, created_at)` for pending claim, `(tenant_id, status)` for the submission cap check, `(status, heartbeat_at)` for the abandoned-claim sweep, `(completed_at)` for GC) was already present in Phase 1's `createExportJobs()`. The Chronicler claims with `SELECT … FOR UPDATE SKIP LOCKED` against the existing indexes — no new DDL means no Phase 7 entry in any `ensureXxxColumn()` probe. Phase 7's `model_id` lives at the top level of a `{model_id, filter}` envelope stored in the `filter` JSON column (stamped by `ExportJobSubmitter::submit()` and read by `ExportJobClaimer::extractModelId()`); the consumer's original QueryFilter is preserved verbatim under `.filter`, so Phase 8's QueryFilter validator does not have to peel out the engine's stamping. A future workload-driven ADR can materialise `model_id` as a separate column.
- **String slots are `TEXT` with a 766-char prefix index** (ADR 0030, resolving Gap 4): `i_str_NN` columns hold the full 4096-char QueryFilter string bound (`FilterLimits::DEFAULT_MAX_STRING_LENGTH`); a filterable string slot's composite index is `(tenant_id, i_str_NN(766))` — 766 utf8mb4 chars × 4 bytes + 8-byte tenant_id = 3072 bytes, exactly the InnoDB DYNAMIC key limit. **`VARCHAR(4096)` is physically impossible** — 25 such columns exceed MySQL's 65,535-byte row-definition limit (errno 1118), which counts every VARCHAR in full while TEXT counts only ~12 bytes. The page DDL pins `ROW_FORMAT=DYNAMIC` (load-bearing: COMPACT/REDUNDANT cap index keys at 767 bytes → errno 1071). MySQL rechecks the full value behind every prefix-index access so all 12 filter operators stay exact; never add `ORDER BY`/`GROUP BY` on a string slot without revisiting ADR 0030 (`max_sort_length` truncates TEXT sorts at 1024 bytes). `PageProvisioner::STRING_INDEX_PREFIX` is the single source of the 766; `tests/Smoke/Page/StringSlotWidthTest` locks the DDL shape and the >255-char write/filter behavior. Forward-only — existing `VARCHAR(255)` pages are not altered (ADR 0012).
- **Which slot columns are indexed is not persisted anywhere.** `PageProvisioner` emits `filterable_slots` to the log but stores it in no registry column, so both consumers that need the answer derive it at runtime from `information_schema.STATISTICS` via `IndexedSlotPredicate`. Correct but not free — the Watcher pays one data-dictionary lookup per slot per poll. Persisting it (`stardust_slot_assignments.is_indexed`, written at inventory-insert time behind a new `Bootstrapper::ensureXxxColumn()` probe) is a reasonable future standalone schema change, and `IndexedSlotPredicate` is deliberately the seam that keeps it a one-file migration.
- `entry_data` carries two tenant-scoped composite indexes — `(tenant_id, model_id)` and `(tenant_id, deleted_at, created_at)` — verified by `testEntryDataCompositeIndexesPresent`.
- Every `entry_slots_page_N` write goes through `INSERT … ON DUPLICATE KEY UPDATE` keyed on `entry_id` (Architecture Blueprint §5). The slot column list is built from `stardust_slot_assignments.slot_column` whose universe is `i_{str|int|num|dt}_NN` — interpolating those into SQL is safe.

### Test conventions

- Smoke tests under [tests/Smoke/](tests/Smoke/) hit a real MySQL — there are no mocked databases. Every test class guards `tearDown()` with `isset($this->pdo)` because PHPUnit still calls `tearDown` after a `markTestSkipped()`/`fail()` in `setUp`, leaving the typed property uninitialised.
- `BootstrapTest::setUp()` drops every StarDust table before each test with `SET FOREIGN_KEY_CHECKS = 0` — point it only at a throwaway database.
- Phase 3 tests extend [tests/Smoke/WritePathTestCase.php](tests/Smoke/WritePathTestCase.php), which provides the shared connection scaffolding plus registry-seed helpers (`provisionPage()`, `createModel()`, `createField()`, `reserveSlotFor()`, `setupModelWithReservedField()`, `forceGrandfatheredSlotFor()`). Use it for any new write-path test rather than duplicating the env-gated setUp. Post-ADR-0034 seeding rules: `createField()` keeps its `$isFilterable = false` default (mirroring the `NOT NULL DEFAULT FALSE` DDL), but `reserveSlotFor()` now requires a **filterable** field or the reserver throws, and `setupModelWithReservedField()` seeds one accordingly (a reserved slot implies filterability). `forceGrandfatheredSlotFor()` assigns a slot by direct registry UPDATE, bypassing `SlotReserver` — the only way to construct a pre-0034 grandfathered slot (a live slot held by a non-filterable field), which ADR 0034 §5 declines to migrate and which `QueryValidatorTest` and `RetypeFilterabilityPromotionTest` still need. It follows the same bypass precedent as `Phase6aTestCase::seedSlotValues()`. Note that a retype fixture whose field is filterable now needs its page provisioned **with the target family's indexed column** (e.g. `provisionPage(['i_int_01'])` for a `string → int` retype), because the replacement reservation demands an indexed slot per ADR 0016 commitment 1.
- Phase 4 tests extend [tests/Smoke/ReadPathTestCase.php](tests/Smoke/ReadPathTestCase.php) which builds on `WritePathTestCase` and adds `seedEntry()` (writes via the real `EntryWriter` so slot UPSERTs match production), `setupFilterableStringField()` (page with `i_str_01` filterable + filterable string field reserved), and `reader()` (constructs an `EntryReader` bound to the test PDO). Use it for any new read-path test.
- Phase 5 tests extend [tests/Smoke/Phase5TestCase.php](tests/Smoke/Phase5TestCase.php) which builds on `ReadPathTestCase` and adds daemon helpers (`fillAllFreeStringSlots()`, `enqueueSyncRow()`, `writePendingImportJob()`, `writeProcessingImportJob()` (Gap 5 — seeds a `processing` row with a controllable heartbeat age + manifest checkpoint for abandoned-claim tests), `seedDlqRow()`, `makeBackfillExecutor()`, `makeDlqWriter()`, `makeUnmappedFieldReserver()`, `makeSpreadSampler()`, `makeSyncQueueWorkSource()`, `makeImportJobWorkSource()`, `makeReconciler()`, `makeWatcher()`, `makeDlqReplayer()`), plus the ADR 0035 demand fixtures `provisionPageWithIndexedFamily(string $family, int $count = 1)` and `unmappedFilterableField(int $modelId, string $declaredType = 'string')` (the latter names the intent: seed exactly one unit of Watcher demand). **`threshold: 0.0` no longer suppresses provisioning** — a test that wants the Watcher quiet must also leave no filterable field without a slot, since the demand trigger ignores the threshold. Phase 5 tests live under `tests/Smoke/Watcher/`; `ProvisioningPlannerTest` is intentionally DB-free (pure policy matrix, same posture as `RetypeCoercionMatrixTest`), as are `tests/Smoke/Slot/IndexedSlotPredicateTest` and `Watcher/SpreadFormulaTest`. `Watcher/SpreadSamplerTest` and `Slot/SlotAffinityTest` place slots on exact `(page, column, status)` triples by direct registry UPDATE — `SlotReserver` packs onto the oldest page first, so a *deliberately fragmented* model cannot be built through the normal reservation path at all (same bypass precedent as `Phase6aTestCase::seedSlotValues()`). `SlotAffinityTest` owns its PDO rather than extending a phase case, because it opens sibling connections to prove the ADR 0032 non-locking invariant; its spread-outcome test was validated by temporarily neutering affinity and confirming it fails, so it is not a fixture that could not have failed. The `EventVocabularyTest` is intentionally DB-free — it greps `src/` directories for `'event' => '...'` literals. The `Daemon\PidFileGuardTest`, `Daemon\PollLoopTest`, and `Liberator\LiberatorSingletonTest` are pure-PHP (no DB); the `Daemon\AdvisoryLockTest` connects directly via env vars rather than through `WritePathTestCase` so it can open two sibling PDO sessions for contention testing.
- Phase 6a tests extend [tests/Smoke/Phase6aTestCase.php](tests/Smoke/Phase6aTestCase.php) which builds on `Phase5TestCase` and adds Liberator helpers (`tombstoneSlotAssignment()`, `setTombstonedAt()`, `seedSlotValues()` — bypasses `EntryWriter` to cheaply seed N `entry_data` + slot rows, `pageTableNameFor()`, `fetchSlotAssignment()`, `fetchSchemaVersion()`, `countNonNullValues()`, `makeTombstonedSlotRepository()`, `makeSlotSweeper()`, `makeLiberator()`, `readNdjsonStream()`). The `Liberator\LiberatorDeadlockRetryTest` simulates `SQLSTATE 40001` via a `PDO` subclass instantiated through `ReflectionClass::newInstanceWithoutConstructor()` — PDO's constructor requires a live driver+DSN, so reflection is the cleanest way to wrap the test PDO without a second connection. Same trick for `DeadlockInjectingStatement extends PDOStatement` (its constructor is private — only PDO may instantiate). The wrapper delegates every method to the inner real PDO/statement so the bypassed parent never matters.
- Phase 6b tests extend [tests/Smoke/Phase6bTestCase.php](tests/Smoke/Phase6bTestCase.php) which builds on `Phase6aTestCase` and adds retype helpers (`makeRetypeInitiator()`, `makeRetypeBackfillWorkSource()`, `runRetypeReconcilerTick()`, `fetchCheckpointForField()`, `fetchSlotForField()`, `fetchFieldRow()`, `assertCoercionMatrix()`). Tests live under [tests/Smoke/Retype/](tests/Smoke/Retype/). The `RetypeCoercionMatrixTest` is intentionally DB-free (pure unit test against `RetypeCoercionEngine::attempt()`); every other Phase 6b test hits MySQL via the inherited env-gated PDO setup.
- Phase 7 tests extend [tests/Smoke/Phase7TestCase.php](tests/Smoke/Phase7TestCase.php) which builds on `Phase6bTestCase` and adds Chronicler/Export helpers (`makeChronicler()`, `makeExportSubmitter()`, `makeProcessor()`, `seedExportJob()` — direct INSERT bypassing the submitter for tests that need a specific lifecycle state, `seedEntryDataBatch()` — bulk INSERT of entry_data rows, `fetchExportJob()`, `readArtifactCsv()`, `readArtifactJson()`, `makeTempArtifactDir()` — auto-tracked + tearDown'd to prevent artifact leaks between tests). Tests live under [tests/Smoke/Chronicler/](tests/Smoke/Chronicler/). The `EventVocabularyTest` extends to scan `src/Chronicler/` and `src/Export/` directories with two new methods — `testChroniclerSourceUsesOnlyAllowedEventNames()` and `testExportApiSourceUsesOnlyAllowedEventNames()`. All Phase 7 smoke tests use `[0, 0, 0]` DB-disconnect backoff and a no-op `sleepFn` in `makeChronicler()` / `makeProcessor()` so timed-out scenarios don't add real wall-clock latency to the suite. `str_getcsv()` calls in test helpers pass `''` as the `$escape` argument explicitly (PHP 8.4 deprecation; `''` matches the post-8.4 behaviour and is the RFC 4180-correct choice). Three special-purpose fixtures live alongside the standard tests: (1) `ChroniclerDeadlockRetryTest`'s `DeadlockInjectingPdo`/`DeadlockInjectingStatement` wrap the test PDO via `ReflectionClass::newInstanceWithoutConstructor()` (same trick as `LiberatorDeadlockRetryTest`) to inject `SQLSTATE 40001` on the `entry_data` SELECT and prove the deadlock retry budget + `chunk_skipped` gap path; (2) `ChroniclerDiskFullTest` registers a `failwrite://` PHP stream wrapper that accepts `fopen`/`mkdir` but returns `0` on every `stream_write` — pointing `ArtifactStreamFactory` at `failwrite:///disk` makes the very first write trip `ChroniclerArtifactDiskFullException`, exercising both the per-row catch and the header-write catch wrapped around `stream->open()`; (3) `ChroniclerMultiWorkerClaimTest` and `ExportJobSubmitterCapConcurrencyTest` open sibling PDO connections (same `STARDUST_TEST_DSN/USER/PASS` env vars, not channelled through `WritePathTestCase`) — the multi-worker test holds a `FOR UPDATE` on row 1 from session A and asserts the claimer on session B's `SKIP LOCKED` routes to row 2; the cap-concurrency test holds the tenant's gap-lock range from session A with `innodb_lock_wait_timeout = 1` on session B, asserting B's submission surfaces `SQLSTATE 1205` rather than silently inserting past the cap.
- The static table-drop allowlist (`PHASE_1_TABLES` in each test class, `TABLES` in `BootstrapTest`) MUST include every Bootstrapper-managed table. When you add a new table in a future phase, extend every list.
- `testResubmittingSameEntryIdUpdatesNotErrors` exercises `ON DUPLICATE KEY UPDATE` via a direct `INSERT` against the slot table rather than a second `write()` call — `entry_data`'s auto-increment makes it impossible to produce the same `entry_id` naturally through the write path. The UPSERT clause is tested in isolation; end-to-end coverage of a repeat write requires a higher-level fixture and is deferred.
- `testTransactionRollsBackCleanlyOnFailure` simulates a mid-transaction failure by patching `declared_type` in the DB to provoke `UncoercibleSlotValueException` before any `INSERT`. This is indirect — a real mid-tx failure (e.g. a dropped connection after the `entry_data` insert but before the slot write) is not exercised in Phase 3.

## Phased build discipline

Each phase has explicit ADR-tracked exit criteria. When adding code:

- Don't introduce surface area for un-shipped work. Build what the current task asks for; if a future phase would need a seam, wait until that phase. `bin/stardust --help` documents only commands that actually run — a command that isn't implemented doesn't get a help entry.
- Append to existing entry-point classes (`StarDust`, `Config`) — don't break their current public shape. Phase 5's eleven new Config fields all sit AFTER `$artifactDir` for this reason; Phase 6a's five new fields all sit AFTER `$pidFileDir`. Note that `Config`'s *property declarations* are grouped by subsystem for readability while its *constructor parameters* are strictly append-ordered — the constructor is the compatibility surface, so append there and place the property declaration wherever it reads best.
- New tables / indexes / columns added in future phases should be reviewed as standalone schema changes, added as new methods in `Bootstrapper`, not retrofitted into existing method bodies (Phase 6a's `ensureSlotAssignmentSweepGapColumn()` follows the same pattern as `ensureSlotAssignmentFieldLiveUniqueIndex()`).
- Transaction + log pattern (used by `PageProvisioner`, `SlotReserver`, `EntryWriter`, Phase 5's `SyncQueueWorkSource` / `ImportJobWorkSource` / `DlqReplayer`, and Phase 6a's `SlotSweeper`): validate inputs first; resolve any pure-logic plan; `beginTransaction()`; try-block with `commit()`; `catch (Throwable)` with `inTransaction()`-guarded `rollBack()` and rethrow; log AFTER successful commit (so events never describe rolled-back state).
- Daemon SOLID discipline (established in Phase 5/6a, and binding on every phase since — 6b's retype collaborators, Phase 7's Chronicler, and Phase 8's search pipeline all follow it): every daemon collaborator is `final`; no abstract base classes; `Tickable` / `ShutdownSignal` / `ReconcilerWorkSource` are single-method interfaces (ISP); collaborators are constructed via `Config`-lazy factories on `StarDust\StarDust`; no `*Events` wrapper classes — events emit inline with `'event' => '...'` literals matching the Phase 1–4 style, and the `EventVocabularyTest` enforces the closed allowlist. New daemons follow the same shape: a `final` `Tickable` orchestrator that composes 2–3 `final` collaborators (one repository, one work-unit executor, optionally a DTO), with all dependencies injected via constructor (DIP).

## Working conventions

- **`StarDust::VERSION` stays at `0.3.0-alpha.1` for the whole v0.3.0 build.** Do not bump it when a phase or stage ships — that is a release-time action, not a per-phase one.
- **Don't commit unprompted.** Implement, run PHPStan and the smoke suite, then hand over a proposed commit message as text and let the maintainer commit.
- **Verify database claims against a real server.** No mocked databases anywhere in this repo, and MySQL's storage limits are full of traps this design has already hit (errno 1118 on 25 wide VARCHARs, errno 1071 under the wrong `ROW_FORMAT`). If a claim about MySQL behaviour is load-bearing, probe it rather than asserting it.
- **When a phase or stage ships, update the docs in the same change:** this file, the affected `src/*/CLAUDE.md`, [README.md](README.md) (no ADR citations), and [TESTING.md](TESTING.md) if coverage changed. **Not [CHANGELOG.md](CHANGELOG.md)** — see its entry above; it is written at release time. New event names additionally require an ADR 0020 update or `EventVocabularyTest` fails.
