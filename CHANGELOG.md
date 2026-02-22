# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
