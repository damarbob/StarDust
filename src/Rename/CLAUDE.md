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

A field may have one lifecycle in flight. Both initiators check both checkpoint repositories, **rename first then retype**, so two concurrent initiators cannot each see the other's row as absent; `ux_backfill_job_name` is the real backstop.

The retype-side guard sits inside `RetypeInitiator::runTuple()`, **not** on the `StarDust` facade, because `initiateRelocation()` shares `runTuple()` — so `compactModel()` inherits it. A facade-level check would leave compaction as an unguarded back door into a real bug: `RetypeBackfillExecutor` locates values by name, so mid-rename every un-migrated row reads as "value absent" and its slot is written NULL, silently, with no `coercion_null` event because no coercion was attempted.

## `insertOrReset()`, not `insert()`

Nothing in the engine ever deletes from `backfill_checkpoints`, and `ux_backfill_job_name` is UNIQUE, so a plain INSERT makes the *second* lifecycle for a field throw a raw `PDOException` once the first completes — `existsRunningForField()` returns false for a `completed` row and offers no protection. `RetypeCheckpointRepository::insert()` has that defect today, which is why `compactModel()`'s "safe to re-run" claim does not hold for an already-relocated field. This repository uses `INSERT … ON DUPLICATE KEY UPDATE` and does not inherit it. Fixing the retype side is tracked separately.

## Final-chunk atomicity

`markCompleted()` + clearing `previous_name` + the schema-version bump commit **together**. A reader refreshing its snapshot between the clear and the bump would lose the fallback while un-migrated rows still existed.

## No `CAPACITY_WAIT`

A rename touches no slot, so it can never be blocked on inventory. The work source returns only `WORK_DONE` or `IDLE`. If that ever stops being true, the failure mode is a stuck checkpoint rather than a visible wait event — worth revisiting the outcome enum at that point rather than before.
