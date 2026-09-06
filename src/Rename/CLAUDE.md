# Rename pipeline

ADR 0036 field rename. Four `final` collaborators mirroring the Phase 6b retype shape, but with markedly less machinery — a rename touches **no slot at all**.

## Why this is a separate package from `src/Retype/`

Zero shared mechanism. No reservation, no tombstone, no Liberator hand-off, no coercion matrix, no `SlotRowUpserter`, no cardinality or spread sampling. `RetypeBackfillExecutor`'s whole body is slot upserts; this executor's whole body is an `entry_data` rewrite — different target table, different failure modes.

`RetypeInitiator::runTuple()` is also at its documented limit ("check this invariant if you add a fourth shape" — `src/Retype/CLAUDE.md`), and a rename skips two of its five steps entirely.

The one cost of a new package is the `EventVocabularyTest` blind spot: that scan is per-directory and `scanDir()` returns `[]` for a directory that does not exist, so `src/Rename/` needed its own scan method or both new events would have gone unenforced while the suite stayed green. `testRenameSourceUsesOnlyAllowedEventNames()` closes it, and its `assertNotEmpty()` is the part that actually bites if the package is ever moved.

## `stardust_fields.previous_name` is the whole design

A rename flips `name` immediately and rewrites payloads asynchronously, so mid-drain some rows carry the old key and some the new. `previous_name` is what lets every consumer bridge that window, and **a non-null value means exactly "a rename is in flight for this field"**.

It lives on `stardust_fields` rather than on the checkpoint because `SlotResolver` and `LiveSlotMap` already SELECT that table with no join — the alias rides along free on both hot paths. This is deliberately *not* the `source_declared_type` pattern: that column exists only because retype destructively overwrites `declared_type` and has nowhere else to put the old value. Duplicating `previous_name` onto the checkpoint would buy nothing and create two sources of truth.

A useful secondary property: if a checkpoint is manually failed or deleted, `previous_name` is left non-null and the read path just keeps doing a harmless double-lookup forever. A stranded checkpoint column would be invisible instead.

## Who bridges the window, and who deliberately does not

| Surface | Behaviour during the window |
| :-- | :-- |
| `read()` / `search()` results | Falls back new key → old key (`ResultAssembler`) |
| `get()` point read | Canonicalises the whole payload via `SnapshotEntry::canonicalisePayloadKeys()` |
| `write()` / `updateEntry()` | Canonicalises **inbound** keys via `LiveSlotMap::canonicalise()` |
| CSV export | Header says the new name; lookup falls back (`HeaderResolver::resolveAliases()`) |
| JSON export artifact | **Verbatim payload — not bridged.** Documented contract; a JSON consumer sees the old key and can cope |
| Filters | **Rejected on the old name.** Not bridged, on purpose |

**The filter asymmetry is the load-bearing decision here.** A rejected filter loses nothing and tells the caller immediately; a rejected write loses data silently. So writes converge and filters fail loudly. Do not "fix" `FieldRefResolver` to alias `previous_name`.

**The write-path half is not optional.** `PayloadSplitter` silently drops payload keys `LiveSlotMap` does not know, so without canonicalisation a client still sending the old name would land that key in `entry_data.fields` with no slot write and no exhaustion enqueue — and for a row the backfill cursor has already passed, that key is never migrated and the value disappears when `previous_name` is cleared. The read-window hole is transient; this one is permanent. `EntryWriter` therefore loads the map *before* `json_encode` on both the insert and update paths.

## The rewrite is SQL, not decode-mutate-encode

`RenameBackfillExecutor` issues one `JSON_REMOVE(JSON_SET(…))` per chunk rather than decoding each payload in PHP. This would otherwise be the first stored-payload round-trip in the engine, and `json_decode($json, true)` is not faithful: a payload whose keys form a complete sequential list from zero re-encodes as a JSON *array*, so `{"0":"x","1":"y"}` silently becomes `["x","y"]`. Field names are `VARCHAR(128)` with no numeric restriction, so that payload is reachable. `RenameBackfillTest::testPreservesPayloadFidelityIncludingNumericKeys` pins it.

Verified on MySQL 8.0.13: bound parameters work as JSON path arguments, and `jsonPathFor()`'s quoted `$."name"` form survives spaces, hyphens, leading digits, Unicode, embedded quotes and backslashes, and a `$.`-prefixed injection attempt. The quoted form is required, not cosmetic — bare `$.name` is only valid for ECMAScript identifiers.

Two path guards, for two different reasons:

- `JSON_CONTAINS_PATH(fields, 'one', :oldPath)` — idempotence, and it stops `JSON_SET` writing a spurious `newKey: null` into rows that never held the field. It is **not** needed to protect `fields JSON NOT NULL`; that was checked, and `JSON_SET` with an absent source returns a document containing a JSON null, not SQL NULL.
- `NOT JSON_CONTAINS_PATH(fields, 'one', :newPath)` — a post-rename write wins over the stale key.

MySQL normalises JSON object key order on any store, so the rewrite does not introduce reordering and tests must not assert on key order.

## Lifecycle exclusivity

A field may have one lifecycle in flight. Since ADR 0037 there are **three** of them, checked in one fixed order everywhere — **rename → retype → delete** — so two concurrent initiators cannot each see the other's row as absent; `ux_backfill_job_name` is the real backstop.

The delete leg is checked differently from the other two: it keys on `stardust_fields.deleted_at` (in `loadField()`, riding a SELECT this initiator already runs) rather than on a checkpoint row, so it still fires for a field whose purge checkpoint was manually failed. See `src/Delete/CLAUDE.md`.

The retype-side guard sits inside `RetypeInitiator::runTuple()`, **not** on the `StarDust` facade, because `initiateRelocation()` shares `runTuple()` — so `compactModel()` inherits it. A facade-level check would leave compaction as an unguarded back door into a real bug: `RetypeBackfillExecutor` locates values by name, so mid-rename every un-migrated row reads as "value absent" and its slot is written NULL, silently, with no `coercion_null` event because no coercion was attempted.

## Lock failures are retried, then deferred

`RenameBackfillWorkSource::tickOne()` wraps `attemptOne()` in a budget of `Config::$reconcilerLockRetryBudget`; exhaustion emits `lock_wait` and returns `TickOutcome::LOCK_WAIT` rather than letting the `PDOException` reach `PollLoop`, which does not catch and would exit the daemon. The checkpoint cursor only advances on commit, so a rolled-back chunk is retried identically next tick. Shared rationale: `src/Reconciler/CLAUDE.md`.

`markFailed()` was removed from `RenameCheckpointRepository` in the same change — it never had a caller.

## `insertOrReset()`, not `insert()`

Nothing deletes a *rename* checkpoint, and `ux_backfill_job_name` is UNIQUE, so a plain INSERT makes the *second* lifecycle for a field throw a raw `PDOException` once the first completes — `existsRunningForField()` returns false for a `completed` row and offers no protection. This repository uses `INSERT … ON DUPLICATE KEY UPDATE` and does not have that defect.

**Update, 2026-08-27.** `RetypeCheckpointRepository` was the last holdout and has now been converted too, so all four `backfill_checkpoints` namespaces upsert. Its version is *not* a copy of this one: it also resets `source_declared_type`, a column no other namespace has, and it needed a `FOR UPDATE OF f` row lock in `RetypeInitiator` that this initiator does not take — see `src/Retype/CLAUDE.md`. A retype's lost race mis-coerces stored data; a rename's resets a cursor.

**Correction, 2026-08-24.** This section used to open "Nothing in the engine ever deletes from `backfill_checkpoints`". That is no longer true: ADR 0037's `DeleteCheckpointRepository` deletes terminal rename/retype rows at deletion initiation, and deletes its own row on the purge's final chunk (`src/Delete/CLAUDE.md`). The conclusion is unaffected — a rename checkpoint is still only ever cleared by a *field deletion*, which refuses to start while a rename is running, so the upsert remains necessary.

## One correlation id spans both halves

`RenameInitiator` mints a v4 UUID at the operation boundary, stamps `rename_started` with it, and writes it to `backfill_checkpoints.correlation_id` in the same transaction. `RenameBackfillWorkSource` reads it back off the claimed checkpoint and emits `rename_complete` under it, with the per-tick id preserved beside it as `chunk_correlation_id`.

**The `reconciler`-source `chunk_claimed` / `chunk_complete` events are untouched** — their operation genuinely is the chunk. Only the one `registry`-source event at the end of the drain switches, because that one closes what `rename_started` opened.

`insertOrReset()` resets the column on the UPDATE branch, for the same reason it resets everything else: a *second* rename of the same field reuses the row, and inheriting the first id would join this drain's completion to a start that described a different rename. `LifecycleCorrelationTest::testASecondRenameDoesNotInheritTheFirstsId` pins it; both it and the join test were validated by neutering.

The `??  $chunkCorrelationId` fallback covers a checkpoint opened before the column existed. That is not defensive padding — it is what makes the ALTER safe to run under a live fleet, and `testAPreExistingCheckpointFallsBackToTheChunkId` asserts the fallback is the chunk id rather than null, since ADR 0020 types the field non-nullable.

## Final-chunk atomicity

`markCompleted()` + clearing `previous_name` + the schema-version bump commit **together**. A reader refreshing its snapshot between the clear and the bump would lose the fallback while un-migrated rows still existed.

## No `CAPACITY_WAIT`

A rename touches no slot, so it can never be blocked on inventory. The work source returns only `WORK_DONE` or `IDLE`. If that ever stops being true, the failure mode is a stuck checkpoint rather than a visible wait event — worth revisiting the outcome enum at that point rather than before.

## `ModelRenamer` — why it shares almost nothing with the above

A model rename lives in this package for cohesion, but it needs none of the machinery on this page: no checkpoint, no work source, no `previous_name`, no read/write fallback, no window.

**Because a model's name is load-bearing nowhere.** Identity is `stardust_models.id`. `entry_data` carries `model_id`; `SchemaVersionCache` keys snapshots by `modelId` and `SlotResolver` builds them from `stardust_fields` alone, so no snapshot holds a model name; every other join to `stardust_models` in the engine (`CompactionRepository`, `HeaderResolver`, `RetypeInitiator`, `SpreadSampler`, both checkpoint repositories) selects only `id` / `tenant_id`. The QueryFilter wire format accepts `{"model": …}`, but `FieldRefResolver` resolves leaves by field name against the snapshot using the request's `modelId` and never reads it.

So it is one UPDATE, synchronous, complete on return.

**No schema-version bump, deliberately.** `SchemaBuilder::createModel()` bumps when it inserts a model row and this does not, which looks like an oversight unless you know why: nothing a cached snapshot holds changes, and `stardust_schema_version` is a singleton, so a bump would invalidate every model's snapshot in every process for no correctness benefit. It also matches schema_reference §5.1, which scopes the version to *field metadata*. `ModelRenameTest::testRenameDoesNotBumpSchemaVersion` pins it so a future contributor cannot "fix" it silently.

**No clock either.** `stardust_models` has no `updated_at` column, so there is no timestamp to write, and the structured logger already emits `ts`. This is the one registry collaborator that takes only `(PDO, LoggerInterface)`.

**The one caller that misbehaves afterwards** is `SchemaBuilder::findModelId()`, the engine's only look-up-by-name. After a rename, `createModel()` / `defineModel()` with the old name finds nothing and creates a *second* model — `docker/seed.php` is a live instance. Inherent to get-or-create rather than a defect here, and pinned by `ModelRenameTest::testSeedingWithTheOldNameCreatesASecondModel` so it reads as known behaviour.

**It checks `rowCount()` on its UPDATE**, for the reason `src/Slot/CLAUDE.md` sets out for `reserveCore()`: the engine takes an injected PDO (ADR 0026), so under `ERRMODE_SILENT` `execute()` returns `false` rather than raising, and without the guard the method would commit and emit `model_renamed` for a rename that never happened. `rowCount()` is exact here because the same-name case returns before the transaction, so a matched row is always a changed row. Unlike `SlotReserverTest`'s equivalent there is **no regression test yet** — the public API cannot currently produce the zero-row state, since nothing deletes a model. When `deleteModel()` lands, that test becomes both possible and worth writing.
