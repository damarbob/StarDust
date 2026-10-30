# tests/Smoke

Test conventions: fixtures, base classes and the traps they guard against. [TESTING.md](../../TESTING.md) owns what the suite *proves*; this file owns how to write a test. Keep the two from contradicting each other.

## Ground rules

- **Every smoke test hits a real database.** There are no mocked databases anywhere in this repo. The suite skips (does not fail) when `STARDUST_TEST_DSN` / `STARDUST_TEST_USER` are unset.
- **Keep tests strict-clean.** `phpunit.xml.dist` sets `failOnWarning`, `failOnRisky`, `beStrictAboutOutputDuringTests` and `beStrictAboutTestsThatDoNotTestAnything`.
- **Guard every `tearDown()` with `isset($this->pdo)`.** PHPUnit still calls `tearDown` after a `markTestSkipped()` or `fail()` in `setUp`, leaving the typed property uninitialised.
- **A test that opens its own PDO takes its options from `Support\TestPdo::options()`**, so it follows whatever prepare mode the CI cell runs.
- **`str_getcsv()` in helpers passes `''` as `$escape`** explicitly (PHP 8.4 deprecation, and the RFC 4180-correct choice).

## The per-test clean slate

- **`Support\SchemaFixture`, called in `setUp()`, gives each test a clean slate.** There is no `tearDown()` sweep and nothing is dropped. It uses `DELETE FROM`, because a drop-and-rebuild is about 22 DDL statements and MySQL 8.0 commits each one atomically across InnoDB and the binlog, which used to make schema churn most of the suite's runtime. **`TRUNCATE TABLE` is not a shortcut**: in InnoDB it is DDL too.
- **`SchemaFixture::reset()` still calls `Bootstrapper::run()`** on every test (about 18 ms against an existing schema). That reseeds the `stardust_schema_version` singleton the sweep deletes, and self-heals a table missing from the allowlist. A test that needs virgin DDL uses `SchemaFixture::dropAll()`; `BootstrapTest` is the only caller.
- **`SchemaFixture::CORE_TABLES` is the one list of Bootstrapper-managed tables.** It is an allowlist, so a table missing from it survives the reset and leaks state into the next test, which fails quietly and intermittently. `Conventions/BootstrapperTableAllowlistTest` scans `Bootstrapper.php` and fails on drift in either direction.
- **`SchemaFixture::IDENTITY_TABLES` lists the AUTO_INCREMENT counters that get reset, and each entry needs a stated reason**, since the reset is itself DDL. Exactly one qualifies: `stardust_pages`, because page tables are named `entry_slots_page_{id}` and many fixtures hardcode `entry_slots_page_1`. **Do not add `entry_data` to make a failing sweep test pass.** It was removed with ADR 0046, and needing it back means production code is reading entry ids as dense and 1-based again (details: `src/Liberator/CLAUDE.md`). Both `ALTER TABLE` statements are skipped when the `DELETE` freed no rows.
- **`BootstrapTest::setUp()` drops every StarDust table** with `SET FOREIGN_KEY_CHECKS = 0`, so point the suite only at a throwaway database. It is the one class that still drops, because idempotent, non-destructive DDL is what it exists to prove.

## Base classes

Each base class extends the one above it and adds helpers. Use the lowest one that has what the test needs rather than duplicating the env-gated `setUp`. The `PhaseN` names are historical (see the phase table in the root `CLAUDE.md`), and later features reuse them: rename and delete tests extend `Phase6bTestCase`, and there is no `Phase6cTestCase`.

| Base class | Adds | Used by |
| :-- | :-- | :-- |
| `WritePathTestCase` | Connection scaffolding and registry seeding: `provisionPage()`, `provisionLegacyPage()`, `provisionPrefixPage()` (the frozen `Support/PrefixIndexPage`, a string slot indexed on its first 766 characters), `createModel()`, `createField()`, `reserveSlotFor()`, `setupModelWithReservedField()`, `forceGrandfatheredSlotFor()`, `makeSiblingPdo()`. | Write path |
| `ReadPathTestCase` | `seedEntry()` (through the real `EntryWriter`, so slot UPSERTs match production), `setupFilterableStringField()`, `reader()`. | Read path |
| `Phase5TestCase` | Daemon helpers: `fillAllFreeStringSlots()`, `enqueueSyncRow()`, `writePendingImportJob()`, `writeProcessingImportJob()` (controllable heartbeat age and manifest checkpoint), `seedImportJob()`, `seedDlqRow()`, the `make*()` factories for the Watcher, Reconciler, work sources and DLQ, plus the ADR 0035 demand fixtures `provisionPageWithIndexedFamily()` and `unmappedFilterableField()`. | `Watcher/`, `Reconciler/` |
| `Phase6aTestCase` | Liberator helpers: `tombstoneSlotAssignment()`, `setTombstonedAt()`, `seedSlotValues()` (cheap direct seeding of entries and slot rows), `fetchSlotAssignment()`, `countNonNullValues()`, `makeSlotSweeper()`, `makeLiberator()`, `readNdjsonStream()`. | `Liberator/` |
| `Phase6bTestCase` | Retype, rename and delete helpers: `makeRetypeInitiator()`, `makeRetypeReconciler()`, `fetchCheckpointForField()`, `fetchLiveSlotForField()`, `fetchFieldRow()`, `makeRenameInitiator()`, `runRenameTick()`, `rawPayload()` (the stored payload with no fallback), `makeDeleteFieldInitiator()`, `runDeleteTick()`, `fetchFieldRowOrNull()`, `makeDeleteModelInitiator()`, `runModelPurgeTick()`, `drainModelPurge()`, `makeRecordingLogger()`. The `run*Tick()` helpers run one tick, so a test can stop a drain halfway. | `Retype/`, `Rename/`, `Delete/` |
| `Phase7TestCase` | Export helpers: `makeChronicler()`, `makeExportSubmitter()`, `makeProcessor()`, `seedExportJob()`, `seedEntryDataBatch()`, `readArtifactCsv()` / `readArtifactJson()`, `makeTempArtifactDir()` (auto-cleaned). | `Chronicler/` |
| `Phase8TestCase` | Search helpers: `makeMysqlDriver()`, `makeSearchService()`, `makeJsonDecoder()`, `makeCompiler()`. | `Search/` |

## Seeding rules

- **A reserved slot implies a filterable field (ADR 0034).** `createField()` defaults to `$isFilterable = false`, matching the DDL, but `reserveSlotFor()` throws for a non-filterable field. `setupModelWithReservedField()` seeds a filterable one.
- **`forceGrandfatheredSlotFor()` is the only way to build a pre-0034 slot** (a live slot held by a non-filterable field), by direct registry UPDATE. `QueryValidatorTest` and `RetypeFilterabilityPromotionTest` need it.
- **A retype fixture with a filterable field needs the target family's indexed column on its page**, for example `provisionPage(['i_int_01'])` for `string → int`, because the replacement reservation demands an indexed slot (ADR 0016).
- **`provisionPage()` builds whatever shape the provisioner builds now, which is a key page for any string slot (ADR 0057).** `provisionPrefixPage()` and `provisionLegacyPage()` build the two older shapes from the frozen `Support/PrefixIndexPage` and `Support/LegacyPage`. Use an older helper only when the subject is that shape, and keep both fixtures off `PageProvisioner` and `Dialect`. A test about a string slot's index reads the finished table from `information_schema`, never the DDL text. `entryIdsFoundByKey()` runs the lookup a key exists to serve, so a test can prove what the server stored in a key without recomputing it.
- **Direct registry UPDATEs are the documented way to build a fragmented model.** `SlotReserver` packs onto the oldest page first, so tests such as `Watcher/SpreadSamplerTest` and `Slot/SlotAffinityTest` place slots on exact `(page, column, status)` triples by hand. `seedSlotValues()` is the same precedent.
- **`threshold: 0.0` does not keep the Watcher quiet.** The demand trigger ignores the threshold, so a quiet-Watcher test must also leave no filterable field without a slot.
- **Daemon factories use zero backoff and a no-op `sleepFn`**, so timeout scenarios add no wall-clock time.

## Traps

- **MySQL normalises JSON object key order on storage.** Payload assertions `ksort()` both sides.
- **Prove the fixture is in the state the test claims.** `compactModel()` returns an empty plan for an unfragmented model, so a compaction-guard test must strand a slot on a second page first. `DeleteWindowTest::testFixtureLeavesResidueInStorage` proves the purge is genuinely half-done, because every other assertion there is "the API does not show the field", which passes vacuously once the purge finishes.
- **`testResubmittingSameEntryIdUpdatesNotErrors` inserts into the slot table directly.** `entry_data`'s auto-increment makes a repeat `entry_id` impossible through the write path, so the UPSERT clause is tested in isolation.
- **`testTransactionRollsBackCleanlyOnFailure` provokes the failure indirectly**, by patching `declared_type` so `UncoercibleSlotValueException` fires before any INSERT. A real mid-transaction failure, such as a dropped connection between the two writes, is not exercised.

## DB-free tests and special fixtures

- **Pure-PHP tests need no database**: `EventVocabularyTest`, everything under `Conventions/`, `Watcher/ProvisioningPlannerTest`, `Watcher/SpreadFormulaTest`, `Slot/IndexedSlotPredicateTest`, `Retype/RetypeCoercionMatrixTest`, `Daemon/PidFileGuardTest`, `Daemon/PollLoopTest` and `Daemon/TickBudgetTest`.
- **Injected lock failures.** `Support/LockFailureInjectingPdo` (used by `Reconciler/WorkSourceLockRetryTest`) and the private wrappers in `Liberator/LiberatorDeadlockRetryTest` and `Chronicler/ChroniclerDeadlockRetryTest` wrap the test PDO in a subclass created with `ReflectionClass::newInstanceWithoutConstructor()`, because PDO's constructor needs a live DSN and `PDOStatement`'s is private. They delegate every method to the real connection and inject the failure where needed. Reuse the shared one for new tests.
- **Disk full.** `Support/FailWriteStreamWrapper` registers a `failwrite://` scheme whose every write returns `0`. `Chronicler/ChroniclerDiskFullTest` uses it to trip `ChroniclerArtifactDiskFullException` on both the row path and the header path.
- **Sibling connections.** `Daemon/AdvisoryLockTest`, `Slot/SlotAffinityTest`, `Chronicler/ChroniclerMultiWorkerClaimTest` and `Chronicler/ExportJobSubmitterCapConcurrencyTest` open a second PDO session (`makeSiblingPdo()` where the base class is available) to prove locking behaviour. The cap test holds the tenant's gap lock from session A with `innodb_lock_wait_timeout = 1` on session B and asserts errno 1205 rather than an insert past the cap.
- **Page size.** `Support/PageSizeFakingPdo` is a real connection that answers the `@@innodb_page_size` query with `$reportedPageSize` and forwards every other statement. A server fixes its page size when its data directory is created, so `BootstrapTest` fakes that one query. Build it with `TestPdo::options()` like any other test PDO.
- **A new event-emitting package needs its own scan in `EventVocabularyTest`.** The scan is per directory, and `scanDir()` returns `[]` for a directory that doesn't exist, so a missing scan leaves the package's events unenforced while the suite stays green.
