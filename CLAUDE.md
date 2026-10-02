# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository. It is the map. Most packages carry their own `CLAUDE.md` with the rationale, invariants and traps for that package; those load automatically when you work in the directory, so read the relevant one before changing anything non-trivial there.

## Release state

**`0.3.0-alpha.1` was tagged on 2026-09-23 and is the published release.** Work after it ships as the next `0.3.0-alpha.N` (or a later pre-release), decided at release time. The public surface is now really public: a change to an entry point, `Config`'s constructor or `EntrySearchInterface` breaks someone.

The legacy 0.2.x line (CodeIgniter 4 + Virtual Columns) was removed in `02eccd4` and lives on as `^0.2.0-alpha.x` Packagist tags. Do not port code back from it.

### Phase numbers

The engine's initial build ran in numbered phases, now frozen ([SDDPG/implementation_phases.md](SDDPG/implementation_phases.md) is historical). The numbers survive in code comments, ADRs, `TESTING.md` and the `PhaseNTestCase` base classes, so this table is where they resolve. **New work never gets a phase number**, and consumer docs never name one (see "Sibling docs").

| Phase | What it built | Packages |
| :-- | :-- | :-- |
| 0 | Environment and package skeleton | n/a |
| 1 | Schema registry and data-plane DDL | `src/Bootstrap/` |
| 2 | Slots and pages | `src/Page/`, `src/Slot/` |
| 3 | Write path | `src/Write/` |
| 4 | Read path | `src/Read/` |
| 5 | Watcher and Reconciler daemons | `src/Daemon/`, `src/Watcher/`, `src/Reconciler/` |
| 6a | Liberator slot reclamation | `src/Liberator/` |
| 6b | Field retype and filterability promotion | `src/Retype/` |
| 7 | Async exports | `src/Export/`, `src/Chronicler/` |
| 8 | Search driver and QueryFilter | `src/Filter/`, `src/Search/` |

## Where the design lives

**Design authority is the SDDPG design repo at [SDDPG/](SDDPG/)**, a separate repository (`github.com/damarbob/SDDPG`) cloned into the project root. It is never tracked by this one, so the links below resolve only when it is present; `CONTRIBUTING.md` tells contributors to set it up the same way. Every `ADR NNNN` reference resolves to [SDDPG/adrs/](SDDPG/adrs/) as `NNNN-slug.md`. Other citations:

- "Architecture Blueprint §N" → [SDDPG/architecture_blueprint.md](SDDPG/architecture_blueprint.md).
- "AC#N" → the per-subsystem acceptance criteria in [SDDPG/blueprints/](SDDPG/blueprints/).
- "schema_reference §N" → [SDDPG/schemas/schema_reference.md](SDDPG/schemas/schema_reference.md). [SDDPG/schemas/queryfilter.schema.json](SDDPG/schemas/queryfilter.schema.json) is the source of truth for the in-package copy at [schemas/queryfilter.schema.json](schemas/queryfilter.schema.json); change the SDDPG one first.
- Also [SDDPG/glossary.md](SDDPG/glossary.md) and [SDDPG/runbooks/](SDDPG/runbooks/).

**Search the ADRs before surfacing a design question as open**; most already have a ruling. On any conflict between a doc and an ADR, the ADR governs.

**Status semantics.** An ADR's `Status` is a human-review milestone, not a build gate: `Proposed` never blocks implementation. **New ADRs always enter as `Proposed`, and only the maintainer flips one to `Accepted`**; never change a status on your own initiative. Acceptance is need-driven, not linear, so read each ADR's own `Status` line. When new work leans on a still-`Proposed` ADR, say so, because review has changed ADR semantics before.

**Amending an ADR.** A `Proposed` ADR may be edited in place. An `Accepted` one gets only editorial fixes in place; a change that alters what code someone would write needs a **new** ADR plus a dated pointer added to the superseded one.

### Sibling docs and what each owns

- [CONTRIBUTING.md](CONTRIBUTING.md): the human entry point (requirements, setup, the three pre-push commands, which conventions tests enforce). Deliberately thin; put depth here or in TESTING.md.
- [AGENTS.md](AGENTS.md): the tool-neutral agent entry point. **A pointer, not a copy**; never duplicate guidance into it.
- [.agent/rules/](.agent/rules/): commit-message, changelog and writing style, in the format Windsurf reads. Only conventions that hold for a framework-neutral library.
- [TESTING.md](TESTING.md): what the smoke suite *proves*. Test *conventions* live in [tests/Smoke/CLAUDE.md](tests/Smoke/CLAUDE.md); keep the two from contradicting each other.
- [examples/](examples/): runnable, self-cleaning demonstration scripts with their own [README](examples/README.md), covering behaviour that unfolds over time and across processes. **Outside PHPStan's analysed paths**, and the first StarDust code many people read, so keep the entry-point signatures they use in sync when a facade method changes.
- [README.md](README.md): consumer-facing and a first-class deliverable. When a feature ships, update its Status section ("What works today" / "Not yet available"), Contents list and smoke-suite bullets. Most usage examples live under [docs/](docs/); update the page that owns the example.
- [docs/](docs/): consumer-facing lookup pages split out of README. A new page needs a Contents entry **and** a one-line pointer in the README body. **Keep `docs/` flat**; the guards glob one level deep.
- [GLOSSARY.md](GLOSSARY.md): consumer-facing vocabulary. **Not a copy of [SDDPG/glossary.md](SDDPG/glossary.md) and never synced with it**; on an upstream change, check only whether a term was renamed or removed. No internal class, method or `stardust_*` names beyond what a consumer touches. Terms are alphabetical under `## Terms` after a `## How the pieces fit` walkthrough. **Each entry's first sentence must stand alone**, because it feeds the website's hover definitions.
- [CHANGELOG.md](CHANGELOG.md): Keep a Changelog format, **written at release time only**. No entries for unreleased work and no `## [Unreleased]` heading, because `DocsConsistencyTest::testVersionMatchesNewestChangelogHeading()` reads the first heading as the current release.
- `.markdownlint.json`: `MD013` (line length) is off, so long single-line bullets are intentional; don't rewrap them. `MD033` allows exactly `div` and `img` and `MD041` has `allow_preamble`, both only for README's centred logo block ([.github/assets/logo.svg](.github/assets/logo.svg)). Widen the allowlist deliberately, never to silence a lint error.

**Rules for every consumer doc** (README.md, GLOSSARY.md, CHANGELOG.md, `docs/*.md`): **never cite an ADR, and never name a numbered phase** ("Phase 5"); name the feature or daemon instead. `Conventions/DocsConsistencyTest` enforces the phase rule by globbing, so a new page is covered automatically. `Conventions/DocLinkIntegrityTest` checks every in-page anchor and relative link in README.md and `docs/*.md`, plus the Contents-entry rule above.

## Writing style

**Binding on every text a person reads**: README.md, GLOSSARY.md, CHANGELOG.md, `docs/*.md`, CONTRIBUTING.md, TESTING.md, ADRs and the other SDDPG documents, code comments and docblocks (`examples/` included), commit messages, what `examples/` scripts print, `bin/stardust` help and output, exception messages, release notes, the website, the docs site, social posts and video. The goal is text that reads as human-written. The full rule, with before/after examples, is [.agent/rules/writing-style-guide.md](.agent/rules/writing-style-guide.md); read it before writing any of the above. The core of it, so it sticks without the click:

- **Never use an em dash (`—`) or en dash (`–`)**, nor a hyphen standing in for one. Split the sentence, use a comma or parentheses, or say "so" / "because" / "with". Ranges are "8.0.13 to 8.0.15". Hyphenated words ("end-to-end", "read-only") are fine.
- **Never put `:` or `;` in a heading or title.** "Schemaless indexed fields in StarDust", not "StarDust: Schemaless Indexed Fields".
- **No colons at all in social posts or video** (narration and on-screen text).
- **Use colons and semicolons sparingly in prose.** Prefer two sentences.
- **Don't stack overclaims.** One "unparalleled" or "effortless", with a concrete fact beside it, is fine marketing. Two in one line ("Blazing-fast filtering with unparalleled scalability") or one per sentence is the generated-text formula.
- **Avoid the generated-text patterns** the guide lists (bold-label bullets on every item, "not just X, it's Y", forced lists of three, "seamless" / "robust" / "delve"). Not banned, just not habitual.
- **Voice is about 80% formal, 20% conversational**, nearer fully formal on lookup pages, ADRs, CONTRIBUTING.md and TESTING.md. Vary sentence length and structure, and never introduce errors on purpose.
- **Commit messages are brief**, with simple one-clause bullets and a reason only when it isn't obvious ([.agent/rules/commit-style-guide.md](.agent/rules/commit-style-guide.md), "Length and Tone").

**`Conventions/WritingStyleTest` checks two of these rules**: it ratchets dashes per file against `tests/Smoke/Conventions/dash-baseline.json` (counts may only go down, and a drop must be lowered in the same change) and rejects a colon or semicolon in a Markdown heading. A new code comment follows the rule even when the comments around it still use dashes. **The one deliberate exception is text written for AI agents** (every `CLAUDE.md`, AGENTS.md, `.agent/rules/`), which stays optimised for agents. That is why this file is still full of em dashes.

## Commands

```bash
# Local quickstart: MySQL + bootstrap + seed + all four daemons. Dev-only,
# not a production target. Seed output prints in `docker compose logs init`.
docker compose up

composer install

# Static analysis, PHPStan level 8 over src/ + bin/. No database needed.
# The first CI job: run it before calling any change done.
vendor/bin/phpstan analyse

# Markdown lint, same version and globs as CI. No database needed.
npx --yes markdownlint-cli2@0.23.2 "*.md" "src/**/*.md" ".agent/**/*.md" "docs/**/*.md"

# Smoke suite. Needs a reachable MySQL 8.0.13+ or MariaDB 10.11+.
cp phpunit.xml.dist phpunit.xml          # gitignored; edit with DB creds
vendor/bin/phpunit --testsuite Smoke
STARDUST_TEST_DSN='mysql:host=127.0.0.1;dbname=stardust_test' \
  STARDUST_TEST_USER=root STARDUST_TEST_PASS=root \
  vendor/bin/phpunit --testsuite Smoke --filter BootstrapTest

# CLI. All commands read STARDUST_DSN / STARDUST_USER / STARDUST_PASS.
bin/stardust bootstrap                   # idempotent schema bootstrap
bin/stardust watcher                     # singleton (flock on $pidFileDir/watcher.pid)
bin/stardust reconciler                  # multi-worker
bin/stardust liberator                   # multi-worker, page-level GET_LOCK (ADR 0049)
bin/stardust chronicler                  # multi-worker export drain
bin/stardust reconciler:dlq:replay --id=42
bin/stardust reconciler:dlq:replay --reason=missing_entry_data

# Advisories on demand. spread:report is registry-only and safe against
# production at any time. cardinality:report is read-only but counts rows
# on every matching page, so it is NOT free; --model narrows which slots
# are sampled, not which rows are counted.
bin/stardust spread:report --tenant=1 --model=7
bin/stardust cardinality:report --tenant=1 --model=7

# Model compaction (ADR 0033). Long-running, needs a running reconciler,
# safe to re-run.
bin/stardust compact:model --tenant=1 --model=7 --dry-run

# Bounded combined tick (ADR 0048) for hosts with no persistent processes.
# --advisories forces a sample now; --exports (off by default) adds the
# Chronicler. See docs/deployment.md.
bin/stardust tick --budget=50 [--advisories] [--exports]
```

**CI** ([.github/workflows/ci.yml](.github/workflows/ci.yml)) runs six jobs: `static-analysis`, `markdown-lint`, `mysql-smoke` (full PHP matrix), `mariadb-smoke` (full PHP matrix × MariaDB 10.11 and 11, must pass), `mariadb-rejection` (MariaDB 10.6, must fail, proving the floor holds) and `coverage-finish`.

- **Coverage is collected in exactly two cells**, PHP 8.4 on MySQL and PHP 8.4 on MariaDB 10.11, because a MySQL-only number misses every MariaDB branch in `Support\Dialect` and `ServerEngineDetector`. Both upload to Coveralls as one parallel build that `coverage-finish` closes under `always()`. Don't enable PCOV across the whole matrix.
- **Coverage is reported, never a gate.** Every Coveralls step uses `fail-on-error: false`, and the decrease threshold is deliberately unset.
- About 12 lines of `Filter\Json\JsonFilterDecoder` are uncovered by design (the post-`json_decode` narrowing guard and an unreachable `match` default). Don't contort tests to reach them.

## PHP floor and static analysis

- **The floor is PHP 8.1**, and CI runs 8.1 to 8.4. `phpstan.neon.dist` pins `phpVersion: {min: 80100, max: 80400}`, so the analyser catches newer syntax locally. Never use `readonly` classes, `json_validate()`, DNF types or `#[\Override]`; readonly properties and enums are fine. A note about 8.4 behaviour (such as the `str_getcsv()` `$escape` deprecation) is a constraint to satisfy on 8.4, not permission to target it.
- **PHPStan runs at level 8 with zero errors and no baseline file.** Don't reintroduce one; fix the code or say explicitly that you can't.
- **When PHPStan calls a runtime guard redundant, suspect the phpdoc first.** PHP enforces only `array`, never `list<string>` or a shape, so a docblock can promise more than runtime delivers. A method that validates untrusted input must have a parameter type that admits the input it defends against; several `@param`s are deliberately widened to `array<mixed>` for this, each with a comment.
- **Level 8 is the deliberate ceiling.** Level 9's cost sits at PDO and `json_decode` boundaries where values are `mixed` by design (ADR 0013). Reaching it honestly means typed row hydration per repository, an architectural decision, not a config change.
- **`PDO::query()` goes through `Support\PdoQuery::run()`**, which throws when the driver returns `false` instead of raising (a consumer on `ERRMODE_SILENT` can get `false`). `GcSweeper` and `SchemaVersionCache` handle it inline with different semantics and are deliberately left alone.

## Database support

- **Supported: MySQL / Percona 8.0.13+ and MariaDB 10.11+** (ADR 0054/0055). The engine is *detected* from the live connection by `Support\ServerEngineDetector`, never configured; `StarDust::serverEngine()` exposes it. `Support\Dialect` branches the two constructs that differ (table collation, and the ADR 0017 live-slot invariant).
- **Rejected, failing closed with `UnsupportedServerException`**: MariaDB below 10.11 (10.7 to 10.10 included, because ADR 0054's JSON-fallback collation divergence has no configuration-only fix) and MySQL / Percona 8.0.12 and older. `EnvironmentTest::testServerIsASupportedEngine` mirrors the gate in the suite.
- **One accepted behavioural difference on MariaDB**: range filters and field sorts order supplementary-plane characters at the opposite end from MySQL (ADR 0054 §3).
- `EXPLAIN ANALYZE` is an 8.0.18+ runbook tool only (ADR 0019/0023). Do **not** add it to the smoke suite.

## Architecture

A framework-neutral Composer library with zero framework, ORM or query-builder runtime dependencies; only the `psr/log` and `psr/clock` interfaces.

### Entry points (`src/StarDust.php`, the public surface)

`StarDust\StarDust` holds the injected `Config`, exposes `pdo()`, `logger()`, `config()`, `serverEngine()` and `VERSION`, and lazily builds each collaborator from `$this->config`. Asynchronous lifecycles **need a running Reconciler** to finish.

- **Setup and introspection.** `bootstrap()`, `schemaBuilder()`, and the read-only `listModels()` / `describeModel()` (backed by `Schema\SchemaReader`).
- **Writes** (`src/Write/CLAUDE.md`). `write()`, `bulkWrite()`, `submitBulkWrite()` returning an `ImportJobId`, and `getImportJob()`, whose counters are nullable ints where **`null` (no chunk committed) is deliberately distinct from `0`**. `updateEntry()` is a full replace that clears omitted fields' slots and throws `EntryNotFoundException`. `deleteEntry()` is a soft delete that returns `false` instead of throwing; the asymmetry is deliberate.
- **Reads** (`src/Read/CLAUDE.md`, `src/Search/CLAUDE.md`). `read(EntryQuery)`, `get()` and `search(SearchRequest)` all run through `SearchService` and the active `EntrySearchInterface` driver (`MysqlNativeDriver` when `Config::$searchDriver` is null); `EntryReader` is a thin façade. `EntryQuery` and `SearchRequest` take an optional `SortSpec` (ADR 0041); `null` means `entry_data.id ASC`. A field sort filesorts over the whole filtered set, while materialisation stays bounded and the read stays two queries. **Sorting is not part of the QueryFilter wire format**, so the JSON schema and the 13-code taxonomy don't mention it. A custom driver must implement `supportsSortOn()`.
- **Field and model lifecycles.**
  - `retypeField()`, `promoteFieldToFilterable()`, `demoteFieldFromFilterable()`: all `RetypeInitiator::initiate()`, asynchronous (`src/Retype/CLAUDE.md`).
  - `renameField()`: returns when the registry commits, and the payload rewrite is asynchronous. Reads, writes and CSV export bridge the window; filters and the JSON export artifact deliberately don't (`src/Rename/CLAUDE.md`).
  - `renameModel()`: synchronous and complete on return, because a model name is a label, not an identity. It deliberately does **not** bump `stardust_schema_version` (pinned by a negative assertion). `schemaBuilder()` still finds models by name, so it creates a duplicate after a rename.
  - `deleteField()` (ADR 0037): severs the field everywhere in one transaction, then purges asynchronously. Returns `false` when there's nothing to do. Refuses while a rename or retype runs, and the name isn't reusable until the purge lands (`src/Delete/CLAUDE.md`).
  - `deleteModel()` (ADR 0038): severs the model and all its fields, then purges asynchronously. Refuses while any of its fields has a lifecycle running. Writes to a deleting model are refused, and reads go dark. **The only operation that physically deletes `entry_data` rows**, with no undelete.
- **Exports** (`src/Export/CLAUDE.md`, `src/Chronicler/CLAUDE.md`). `submitExport()`, `getExportJob()`, `chronicler()`.
- **Operator tools.**
  - `compactModel(…, bool $dryRun = false)` (ADR 0033): long-running and blocks on the Reconciler, so never call it from a request path. Refuses, dry run included, with `RetypeInProgressException` while any field of the model is mid-retype (ADR 0039). See `src/Compaction/CLAUDE.md`.
  - `tick(?int $budgetSeconds, bool $advisories, bool $exports)` and `combinedTick()` (ADR 0048): Watcher, Liberator and Reconciler as one bounded run for cron-style hosts, never a request path. An overlapping run reports `TickStopReason::LOCK_CONTENDED`. `$exports` stays off by default because the disk probe can't prove a large export will fit, and GC only runs on idle cycles. See `src/Daemon/CLAUDE.md`.
- **Daemon factories.** `watcher()`, `reconciler()`, `dlqReplayer()`, `liberator()`, `pollLoop()`, `shutdownSignal()`, and `lockNamespace()` (ADR 0053, memoised because resolving its default costs a query).

`reconciler()` wires six work sources in round-robin order: `SyncQueueWorkSource`, `ImportJobWorkSource`, `RetypeBackfillWorkSource`, `RenameBackfillWorkSource`, `DeletePurgeWorkSource`, `ModelPurgeWorkSource`. **New sources append, never insert**, because the order is observable in event streams.

**Configuration** ([src/Config/Config.php](src/Config/Config.php)) is one construction-time DTO. Only `PDO` is required; every field is `readonly` with a default. Field inventory and the property-order vs constructor-order caveat: `src/Config/CLAUDE.md`.

### Subsystems

| Package | What it is |
| :-- | :-- |
| [src/Bootstrap/](src/Bootstrap/) | Idempotent migration runner, plus **the schema invariants** (read its `CLAUDE.md` before touching DDL). |
| [src/Schema/](src/Schema/) | `SchemaBuilder` and `SchemaReader` (below). |
| [src/Page/](src/Page/) | `PageProvisioner` and the ADR 0012 `EmptyTableGuard`. |
| [src/Slot/](src/Slot/) | `SlotReserver` (the ADR 0034 filterability chokepoint, with ADR 0032 model affinity), `LiveSlotTombstoner`, `IndexedSlotPredicate`. |
| [src/Write/](src/Write/) | `EntryWriter`, `PayloadSplitter`, `LiveSlotMap`, `BulkIngestor`, `BulkIngestSubmitter`, `BackfillExecutor`, `EntryDeleter`. |
| [src/Read/](src/Read/) | Two-query bounded read (ADR 0005), sort DTOs, cursor codec. Mostly delegates to `src/Search/`. |
| [src/Daemon/](src/Daemon/) | `PollLoop`, `Tickable`, `ShutdownSignal`, `PidFileGuard`, `AdvisoryLock`, `CombinedTick`, `TickBudget`. |
| [src/Watcher/](src/Watcher/) | Singleton page provisioner (capacity- and demand-driven), cardinality and spread advisories. |
| [src/Reconciler/](src/Reconciler/) | Multi-worker drain over the six work sources, the DLQ and replayer, the lock-retry budget. |
| [src/Liberator/](src/Liberator/) | Multi-worker slot reclamation with the ADR 0009 deadlock and gap path. |
| [src/Retype/](src/Retype/) | Retype and filterability promotion, and the ADR 0024 coercion matrix. |
| [src/Rename/](src/Rename/) | Field rename (ADR 0036) and `ModelRenamer`. Touches no slot. |
| [src/Delete/](src/Delete/) | Field deletion (ADR 0037) and model deletion (ADR 0038). |
| [src/Export/](src/Export/) | Export submission with the atomic per-tenant cap. |
| [src/Chronicler/](src/Chronicler/) | Multi-worker export drain, artifact streaming, failure semantics (ADR 0025), resume anchor (ADR 0047), GC. |
| [src/Filter/](src/Filter/) | Filter AST, JSON wire decoder, the 13-code validation taxonomy. Registry-free. |
| [src/Compaction/](src/Compaction/) | Operator-initiated model compaction (ADR 0033). |
| [src/Search/](src/Search/) | Driver contract, the pre-flight pipeline (three filter stages plus a separate sort stage; the value stage normalises as well as validates), `SearchService`, the MySQL driver and its adaptive SQL compiler. |
| [src/Logging/](src/Logging/) | `StdoutNdjsonLogger` and the ADR 0020 closed event vocabulary. |
| [src/Exception/](src/Exception/) | The typed-error taxonomy. |

### Small packages, documented here

- **`Schema\SchemaBuilder`** is an onboarding helper, not the first-class definition API (still unbuilt), so **keep it narrow**. `createModel()`, `defineModel()` and `defineField()` are get-or-create, so seed scripts are safe to re-run, and one `createModel()` call bumps `stardust_schema_version` exactly once if it inserted anything. It registers registry rows only; it doesn't provision pages or reserve slots.
- **`Schema\SchemaReader`** (`listModels()`, `describeModel()`) is lock-free, mutation-free and safe per request, which is why it is a separate class. `FieldDescription` carries both `isFilterable` (intent) and `isIndexed` (a filter works right now), and they differ throughout a promotion or retype backfill: `SchemaReader::QUERYABLE_STATUSES` deliberately excludes `backfilling`, which `LiveSlotMap::LIVE_STATUSES` includes.
- **`src/Clock/SystemClock`** is the `psr/clock` default (UTC). Always inject a clock rather than calling `new DateTime`, so tests can freeze time.

**Support utilities.** Each is the one definition of something that has gone wrong when copied. **Do not re-inline any of them.**

- **`RetryableLockFailure`**: "InnoDB refused this over a lock and retrying is safe" (errno 1213 / 1205 / SQLSTATE 40001). Shared by the Liberator's sweeper and all six work sources. It has been widened once already, and a copy that misses the next widening fails silently. What callers do after a match is deliberately left to each caller.
- **`ArtifactDirectory`**: the race-tolerant `is_dir() || @mkdir(recursive) || is_dir()`. The trailing `|| is_dir()` is the race tolerance. Returns `bool` and never throws; what a caller does with `false` is its own policy.
- **`Dialect`**: the engine-specific DDL, `tableOptionsClause()` (collation) and `liveSlotUniqueIndexDdl()` (ADR 0017), each branching on `ServerEngine`. It is the one file that names both engines' literals. If a JSON-fallback SQL comparison ever ships, its collation gets a method here too.
- **`UuidV4`**: the source of every `correlation_id` / `chunk_correlation_id` and of `WorkerIdentity`'s uniqueness suffix.
- **`LikePattern`**, **`PdoQuery`** and **`WorkerIdentity`** follow the same one-definition rule.

### Cross-cutting rules

- **A new log event name requires an ADR 0020 update**, or `tests/Smoke/EventVocabularyTest` fails. The scan is per directory, so **a new event-emitting package needs its own scan method** there, or its events go unenforced while the suite stays green. Full vocabulary: `src/Logging/CLAUDE.md`.
- **`NonFilterableFieldSlotException` and `FieldNotFilterableException` are different exceptions.** The first is a reservation-time invariant violation (your code asked for something the architecture forbids). The second is the read-path rejection of a filter on such a field (fix the query). Full taxonomy: `src/Exception/CLAUDE.md`.
- **`theoretical_min_pages` is one formula with two callers**: the ADR 0031 spread advisory and the ADR 0033 compaction planner, so the planner's "excess pages → 0" means what the advisory reports. Its per-page capacity comes through `Slot\IndexedFreeCapacityReader` for the same reason. **Move both callers together or neither.** Details: `src/Watcher/CLAUDE.md`.

### Schema invariants

They live in [src/Bootstrap/CLAUDE.md](src/Bootstrap/CLAUDE.md). That file loads only when you work in `src/Bootstrap/`, so **read it before writing SQL anywhere** that relies on a table's shape. The topics it covers:

- The slot-status ENUM, the live-slot unique index on both engines, the sweep annotations, and the `RESTRICT` FK with the two-step tombstone.
- The extension-page shape: `TEXT` string slots with a 766-char prefix and `ROW_FORMAT=DYNAMIC`, exactly-indexed pages, and two permanent page shapes.
- The drain-window markers: `previous_name` and both `deleted_at` columns.
- `backfill_checkpoints`' four namespaces, `LIKE` escaping, upserts and correlation ids.
- The sync-queue `entry_id` index, the two singletons, and the DLQ's deliberately missing FK.

### Test conventions

They live in [tests/Smoke/CLAUDE.md](tests/Smoke/CLAUDE.md): the `SchemaFixture` clean slate and its two allowlists, the base-class chain, seeding rules, and the fixture traps. The short version is that **every smoke test hits a real database and nothing is mocked**.

## Conventions for new code

- **Build only what the task asks for.** No seams for unshipped work. `bin/stardust --help` lists only commands that run.
- **Append to `StarDust` and `Config`; never reshape them.** `Config`'s constructor parameters are strictly append-ordered (its property declarations are grouped by subsystem for readability, so append in the constructor and place the property wherever it reads best). `Conventions/ConfigAppendOnlyTest` enforces this.
- **New schema is a new `Bootstrapper` method** (see [src/Bootstrap/CLAUDE.md](src/Bootstrap/CLAUDE.md)).
- **Transaction + log pattern**, used by every registry-mutating collaborator. Validate inputs, resolve any pure-logic plan, `beginTransaction()`, `commit()` inside `try`, then `catch (Throwable)` with an `inTransaction()`-guarded `rollBack()` and rethrow. **Log only after a successful commit**, so events never describe rolled-back state.
- **Composition, not inheritance.** Every class is `final` (`Conventions/FinalClassGuardTest` enforces it), there are no abstract base classes, and `Tickable` / `ShutdownSignal` / `ReconcilerWorkSource` are single-method interfaces. Collaborators are built by `Config`-lazy factories on `StarDust` with dependencies injected through the constructor. No `*Events` wrapper classes: events are inline `'event' => '...'` literals. A new daemon is a `final` `Tickable` orchestrator composing two or three `final` collaborators (a repository, a work-unit executor, optionally a DTO).

## Working conventions

- **`StarDust::VERSION` names the last tagged release (`0.3.0-alpha.1`).** It moves only at release time, in the same commit as the matching CHANGELOG heading.
- **Never cite anything a fresh clone doesn't have.** Tracked text (code, comments, docs, tests, commit messages) may point at files in this repo and at SDDPG, and nothing else. That rules out local notes and plan files, and also the labels that only mean something inside them, such as item numbers and stage names. Describe the thing instead, and ask the maintainer before adding any such reference.
- **Don't commit unprompted.** Implement, run PHPStan and the smoke suite, then hand over a proposed commit message and let the maintainer commit.
- **Verify database claims against a real server.** MySQL's storage limits are full of traps this design has already hit (errno 1118 on 25 wide VARCHARs, errno 1071 under the wrong `ROW_FORMAT`). If a claim about database behaviour is load-bearing, probe it.
- **When a feature ships, update the docs in the same change**: this file if the map changed, the affected nested `CLAUDE.md`, [README.md](README.md), the owning `docs/` page, and [TESTING.md](TESTING.md) if coverage changed. **Not CHANGELOG.md**, which is written at release time.
