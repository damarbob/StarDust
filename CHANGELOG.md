# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
