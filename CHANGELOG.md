# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.3.0-alpha.2] - 2026-10-31

This release makes reads and bulk writes cheaper, indexes text fields through a key computed from the whole value, and lets a field sort choose where entries with no value go. It also fixes a case where a field being promoted could be filtered before its older entries were copied. It is still an alpha, so the public API may change before 0.3.0. Install it with `composer require damarbob/stardust:^0.3@alpha`.

**Upgrading from 0.3.0-alpha.1.** Replace every process of the old release before the new release's Watcher builds its first page. That means the four daemons in the reference mode, or the scheduled `tick` in the cron mode. A process from alpha.1 cannot claim a text slot on a page built with a key, so its Watcher provisions another page on every poll until it is replaced. Pages that already exist are never altered, no existing table changes, and `bootstrap()` stays safe to re-run. The steps are under "Upgrading a running deployment" in `docs/deployment.md`.

### Added

- **Null placement in field sorts**: `SortSpec::byField()` takes an optional `NullPlacement` (`First` or `Last`) that says where entries with no value for the sort field go. Left out, the placement is the database's own, first when ascending and last when descending, so existing callers, SQL and cursors do not change. A cursor records the placement only when it is not the default, and a cursor issued under one placement is refused under the other with `InvalidCursorException`. `EntrySearchInterface` gains no method, so a custom driver that ignores the placement orders by its own default without an error, as `docs/custom-search-drivers.md` explains (0611a63, 2c602ac, f6f4993).

### Changed

- **Text fields on new pages**:
  - A page built by this release indexes each text field through a key computed from the whole value, in place of the first 766 characters. A text filter matches exactly at any length, and a row with several long values can be updated and deleted.
  - An equality on a text field starts a filtered read from that key on a new page, so the read examines about a page of rows however many entries match.
  - A row on a new page is wider, because each text value carries a key of about 50 bytes. `docs/deployment.md` gives measured costs and what to expect.
  - Existing pages are not altered, and no command moves a field between page kinds. A field stays on the kind of page it started on, and new text fields take free slots on older pages first.
  - Building a page with text slots needs the server's SHA-256 function. On a server that returns nothing for it, building the page raises `UnsupportedServerException`.
- **Filtered reads**: On MySQL, a filtered read in the default order that holds an equality on an integer, numeric or datetime field now starts from that field's index, so it examines about a page of rows however many entries match. When an `and` holds several such equalities, the first one drives the read, so write the most selective one first. Other filters, and any sort on a field or on creation time, keep their earlier SQL. The gain was measured on MySQL, and MariaDB already planned these reads well (d72f696).
- **Fewer statements per read**:
  - A read with a filter, a sort or a cursor now checks the schema version once instead of twice, and answers whether a field is filterable from the snapshot it just checked.
  - A point read sends the version with the entry, so `get()` with a warm cache is one statement.
  - A first read of a model in a process loads its index and key lookups only when the read needs them.
- **Bulk writes**: A chunk of entries is written in a constant number of multi-row statements, where the earlier code sent several statements per entry. The copy step of a retype or promotion writes each chunk in one statement as well (9829e06, c7edd57).
- **Bootstrap**: `bootstrap()` throws `UnsupportedServerException` on a server whose InnoDB pages are smaller than 8 KB (the default is 16 KB), before it creates any table. The earlier code passed bootstrap and failed later, when the Watcher tried to build a page (9d05373).
- **Filter on a field that is not indexed yet**: The `FieldNotFilterableException` message now says so when a field is declared filterable but has no index yet. The docs and docblocks no longer name `FieldNotIndexedException` for this case, since the built-in engine never raised it (1abefaa, f4beaf2).
- **Package contents**: The Composer description, keywords and support links are updated. The release archive no longer carries the tests, examples, CI files, agent rules and Docker files, and `bin/stardust --help` no longer mentions design records (e4704fb, 252161b, a5dfa30).

### Fixed

- **Promotion or retype waiting for a slot**: A field whose promotion or retype was waiting for a free slot could be given one that answered filters before its older entries were copied in, so a filter missed them. The background copy could then fail on every later pass. The slot is now reserved in its copying state, and filters on the field are refused until the copy finishes (54bc488, 4f06259).
- **Emulated prepared statements**: Reads no longer fail with a MySQL syntax error when the `PDO` connection leaves `PDO::ATTR_EMULATE_PREPARES` at PHP's default. Either setting works now, and the full test suite runs both ways (021f155).

### Known limitations

- The limits listed for 0.3.0-alpha.1 still apply, except the one about emulated prepared statements.
- On an older page, a row that holds several long values in filterable text fields can be written but then not updated, and MySQL raises error 1713. Keep long free text in fields that are not filterable. `docs/deployment.md` describes the limit.
- On a new page, a text prefix or range bound narrows the index for only its first 16 Latin letters, or about 8 CJK characters. On a column where every value starts with the same longer text, such as the URLs of one site, the read may examine every entry of the tenant.

## [0.3.0-alpha.1] - 2026-09-23

The first release of the 0.3 line: a ground-up rewrite, not an upgrade of 0.2. StarDust is now a framework-neutral Composer library built on **Vertical Schema Partitioning** — each entry's full payload is stored as JSON, and the fields you mark filterable are mirrored into typed, indexed columns so filters run on real indexes. There is no upgrade path from 0.2.x; see **Removed** below.

This is an alpha: the public API may still change before 0.3.0. Install it with `composer require damarbob/stardust:^0.3@alpha`.

### Added

#### Platform and setup

- Framework-neutral Composer package. The only runtime dependencies are the `psr/log` and `psr/clock` interfaces: no framework, ORM or query builder.
- Requires PHP 8.1+ and MySQL 8.0.13+, Percona Server 8.0.13+, or MariaDB 10.11+. The server type and version are detected from the connection; older servers are refused at startup with `UnsupportedServerException`.
- `StarDust` engine class, configured through a single `Config` object. Only a `PDO` connection is required; every other setting has a default.
- `bootstrap()` and `bin/stardust bootstrap` create every table the engine needs. Safe to run on every deploy: re-running never drops or rewrites anything.
- `schemaBuilder()` registers models and fields without hand-written SQL. It is get-or-create, so setup scripts can be re-run safely.
- `listModels()` and `describeModel()` report a tenant's models and fields, including whether each field is declared filterable and whether it can be filtered right now.
- A `docker compose up` quickstart for local development, and runnable, self-cleaning scripts under `examples/`.

#### Writing entries

- `write()` for single entries, `bulkWrite()` for up to 1,000 entries per call, and `submitBulkWrite()` for larger batches processed in the background, with an optional idempotency key. `getImportJob()` reports a background import's progress and outcome.
- Payloads can be built from a typed constructor or from arrays and JSON (`EntryPayload::fromArray()`, `fromJson()`, `listFromJson()`); all paths validate and convert values the same way.
- Writes stay available when indexed capacity runs out. The value is always stored in the JSON payload and is copied into an indexed column in the background once one is free.
- `updateEntry()` replaces an entry's fields wholesale; `deleteEntry()` soft-deletes an entry.
- Datetime values must be either `Y-m-d H:i:s` (treated as UTC) or RFC 3339 with an explicit offset. Ambiguous formats such as `05/01/2026` are rejected rather than guessed.

#### Reading and searching

- `read()` returns cursor-paginated pages of entries; `get()` reads one entry by id. Every read runs in two bounded queries, however large the tenant.
- `search()` accepts filters as a JSON wire format with twelve operators and full AND/OR/NOT nesting. Invalid filters are rejected before any SQL runs, with one of thirteen error codes and a pointer to the offending part of the request. The JSON Schema ships in `schemas/queryfilter.schema.json`.
- Filters are also available as typed PHP objects (`LeafNode`, `AndNode`, `OrNode`, `NotNode`).
- Sorting by entry id, creation time, or one indexed field, in either direction.
- Custom search drivers: implement `EntrySearchInterface` to serve reads from another backend. The built-in MySQL driver is used by default.

#### Changing the schema of live data

- Change a field's type, or make a field filterable or not, while the application keeps running. The field is served from the JSON payload until its values have been copied into the new column.
- `renameField()` renames a field online; stored entries are rewritten in the background, and reads, writes and CSV exports show the new name in the meantime. (A JSON export taken during the rewrite contains each entry as stored, so some entries still carry the old name.) `renameModel()` renames a model immediately.
- `deleteField()` and `deleteModel()` remove a field's values or a whole model's entries. Both take effect for every reader at once; the data is erased in the background. Model deletion is permanent.

#### Background processes

- Four long-running processes, all started through `bin/stardust`:
  - `watcher` adds indexed capacity before it runs out.
  - `reconciler` catches up background work: queued index copies, background imports, type changes, renames and deletions. Work that keeps failing goes to a dead-letter queue, which `reconciler:dlq:replay` retries on demand.
  - `liberator` frees indexed columns that are no longer in use.
  - `chronicler` produces exports.
- `bin/stardust tick` runs the same maintenance as a single time-limited pass, for hosts that can run a cron job but not a persistent process. `--exports` adds export processing to the pass.
- The reconciler, liberator and chronicler can each run as several processes at once; the watcher runs as a single instance.

#### Exports

- `submitExport()` and `getExportJob()` produce CSV or JSON exports of a model in the background, with a per-tenant limit on concurrent jobs.
- An export interrupted by a crash resumes where it stopped rather than starting over. New export jobs are not started while the export disk is nearly full, and finished files are cleaned up after a configurable time.

#### Operations

- `spread:report` shows how many storage pages each model's filterable fields span compared with the fewest possible. `compact:model` moves a model's fields onto fewer pages, with a `--dry-run` preview.
- `cardinality:report` flags indexes whose values are too repetitive to be useful.
- Structured NDJSON logging by default, or any PSR-3 logger. A correlation id you supply follows an operation from the request into the background processes that finish it.
- A typed exception for each failure the engine reports, documented in `docs/errors.md`.

### Changed

- The package is a new engine rather than a CodeIgniter 4 library. Models, fields and entries are stored in a different schema, and the public API shares nothing with 0.2.x.

### Removed

- The entire 0.2.x codebase: the CodeIgniter 4 integration, Virtual Column indexing, and the manager and builder classes. The 0.2.x releases remain installable from their tags.

### Known limitations

- The `PDO` connection must use `PDO::ERRMODE_EXCEPTION` and `PDO::ATTR_EMULATE_PREPARES => false`. With PHP's default emulated prepares, reads fail with a MySQL syntax error.
- A newly filterable or retyped field can be filtered only once the background copy into its indexed column finishes.
- Sorting accepts one key at a time.
- Exports always cover every entry in the model; a request with a filter is refused with `ExportFilterNotSupportedException`.
- Pagination is cursor-only: there are no page numbers, offsets or total counts.
- Drivers are read-only and StarDust does not yet report entry changes, so an external search index has to be rebuilt from exports.
- On MariaDB, range filters and field sorts place supplementary-plane characters (mostly emoji) at the opposite end from MySQL.

## [0.2.0-alpha.3] - 2026-02-22

### Added

- **Sparse Fieldsets**: Implemented sparse fieldset support in `EntriesManager` and `ModelsManager` to optimize database queries (cb4cb2c, 7942ab7).
- **Advanced Filtering & Search**:
  - Virtual column filtering support in `EntriesManager` with a `VirtualColumnFilter` DTO using an operator whitelist (a732a06, 0dc2aa7, 385c66a).
  - Sorting and ID filtering in model search (796dfa0).
  - Introduced `EntrySearchCriteria` and `ModelSearchCriteria` DTOs for structured search and pagination parameters (7754380, 75fb141, 968b656).
- **Pagination**: Implemented `paginate` methods in both `EntriesManager` and `ModelsManager` (6701062, 384558c).
- **Query Builders**:
  - Exposed query builders via new methods in `EntriesManager` and `ModelsManager` for advanced query customization (8f033ac).
  - Explicitly included `current_model_data_id` in `ModelsBuilder` default selections (bedb3e3).
- **Asynchronous Cleanup**: Added `PurgeDeletedJob` for background cleanup and implemented batch purging with configurable limits (9e7f663, 42650a7).
- **Health Checks**: Added the `stardust:health` console command to proactively identify purge blockers (5c6d686, 04ad1f0).
- **Optimistic Locking**: Implemented optimistic locking in model updates, alongside a model-specific `ConcurrencyException` to catch lost updates safely (3d39a15, c482f48, a987209, 31bc318).
- **Testing Capabilities**: Introduced `StarDustTestCase` base class and `SafeMigrationTrait` to ensure stable integration tests across platforms (bc79cfa, 82fb712, 4829426).

### Changed

- **Dependency Injection**: Refactored manager services to use DI, moving away from Singletons. `StarDust` configuration is now injected directly into managers (1628053, 3ae03e6, 00db694).
- **Transaction Safety & Performance**:
  - Added strict transaction guards to `Entries` and `Models` manager methods (4119f41, afd266d).
  - Refactored `EntriesManager` with smart merges instead of full replacements (d6bc89a, 6576d30).
  - Moved index synchronization steps outside of active transactions to prevent lock contention (afd266d).
  - Optimized `RuntimeIndexer` to use batched DDL operations (2891e80).
  - Test suites have been heavily reorganized into Integration suites for better reliability (b88be71, 257e262, 7bdd439, bcd4ba6, 2cf7e34, 941809f, 169fd70, ba800fd).

### Deprecated

- **Raw Accessors**: Deprecated raw query accessors on models in favor of the new builder-based methods (a5ef2a9).
- **Singletons**: The Singleton pattern for manager services is deprecated going forward in preference of DI (1628053).

### Fixed

- **Windows Platform Stability**: Addressed file-locking issues resulting in SQLite database failures on Windows by adding DDL retry logic and `SafeMigrationTrait` to migrations like `AddCurrentVersionColumns`, `CreateHyperTables`, and `AddPerformanceIndexes` (d7df9f3, e83f09e, ae71fba, c5b57fa, 7267640, 725cf00, 3271446).
- **Query Builder Dependencies**: Disabled escaping in select methods for `ModelsBuilder` and ensured proper table aliasing (ecfbaa6, 6f0cfab).
- **Search Capabilities**: Fixed model search criteria to correctly filter by `model_data.name` instead of the base table (a1c5cc1).
- **Database Migrations**: Updated character set configuration to `utf8mb4` in `CreateHyperTables` and `CreateAuthTablesPolyfill` migrations to fully support Unicode (ad41ac2, 75f9a96).
- **Orphaned Column Cleanup**: Fixed issues with model retrieval during the cleanup of orphaned columns (eb73758).
- **Documentation**: Updated GDPR erasure instructions, fixed `ModelsManager` examples in `FAQ.md`, and corrected README headers (98ceb89, bce271c, 2fd8762, 49c6b8b, de1d396, 57a907a).

## [0.2.0-alpha.2] - 2026-01-11

### Added

- **Legacy Alias Support**
  - Introduced `LegacyAliasTrait` for backward compatibility with legacy column aliases (1d241f2)
  - Integrated trait into `EntriesBuilder`, `EntryDataBuilder`, `ModelDataBuilder`, and `ModelsBuilder` (30f8b1b)
  - Added comprehensive unit tests for legacy alias functionality (69d77b5)

## [0.2.0-alpha.1] - 2026-01-07

### Added

- **Asynchronous Indexing**

  - Introduced HTTP-based Queue Worker to handle indexing in the background (7fa5d2b)
  - Implemented `SyncIndexerJob` to manage the actual indexing process (1c38044)
  - Added configuration options for customizing async worker behavior (c443c8a)
  - Updated documentation to include database requirements for queues (f15e6e8)
  - Models now dispatch indexing jobs automatically via `ModelsManager` (97367f7)
  - Added queue library suggestion to `composer.json` (701929b)

- **Runtime Indexer & Virtual Columns**

  - Launched `RuntimeIndexer` library for dynamic index management (93354d3)
  - Added `MapCurrentEntries` console command to map latest history IDs (a11c823)
  - Added `GenerateEntryIndexes` command to create indexes for existing data (fd3e709)
  - Added `MigrateEntryFields` command to convert old field structures (285dc67)
  - Implemented orphaned column cleanup functionality with `CleanupOrphanedColumns` (342866e)

- **Database & Configuration**

  - Added support for a configurable `users` table, allowing integration with any auth system (8aaf348, b96b09c)
  - Included a users table polyfill migration for quick setup (6053ec1, a567b69)
  - Standardized configuration loading across the library (3ca90aa)
  - Added performance indexes to optimize lookups for entries and history (cb82c58, b2b0442)
  - Enhanced database builders with default selection and join methods (8105118, 04bcd62)
  - Implemented current version tracking to optimize latest-entry queries (acef0e9)

- **Documentation & Testing**
  - Comprehensive FAQ and README updates covering new features (50955f9)
  - Added detailed docblocks for better code intelligence (a51d88d, 2b7633c)
  - Established PHPUnit configuration and test bootstrap (7b0b5f3)
  - Added extensive unit tests for `QueueWorker` (49e6469, 996a207), `SyncIndexerJob` (3022deb), and cleanup logic (55db62d)

### Changed

- **Refactoring & Improvements**
  - Auth-agnostic configuration is now standard; documentation updated to reflect this (268e2ca)
  - Refactored query conditions in managers to explicitly specify table names, avoiding ambiguity (289f3fa)
  - Deprecated legacy `EntriesModel` methods in favor of new builder patterns (c3cd72b)
  - Clarified index sync behavior in builder documentation (860ea6d)

## [0.1.0-alpha.1] - 2025-12-12

### Added

- Initial release (881b73a)

[0.3.0-alpha.2]: https://github.com/damarbob/StarDust/compare/v0.3.0-alpha.1...v0.3.0-alpha.2
[0.3.0-alpha.1]: https://github.com/damarbob/StarDust/compare/v0.2.0-alpha.3...v0.3.0-alpha.1
[0.2.0-alpha.3]: https://github.com/damarbob/StarDust/compare/v0.2.0-alpha.2...v0.2.0-alpha.3
[0.2.0-alpha.2]: https://github.com/damarbob/StarDust/compare/v0.2.0-alpha.1...v0.2.0-alpha.2
[0.2.0-alpha.1]: https://github.com/damarbob/StarDust/compare/v0.1.0-alpha.1...v0.2.0-alpha.1
[0.1.0-alpha.1]: https://github.com/damarbob/StarDust/releases/tag/v0.1.0-alpha.1
