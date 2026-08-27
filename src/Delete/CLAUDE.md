# Delete pipeline

Two lifecycles, eleven `final` collaborators. **ADR 0037 field deletion** (five classes, `Delete*`) mirrors the ADR 0036 rename shape closely enough that the rename package is the right thing to read first. **ADR 0038 model deletion** (six classes, `*Model*`) mirrors the field one — read that first, then the model section at the bottom of this file for the four places it deliberately diverges.

## The one-line summary

**Severance is synchronous and total; the purge is asynchronous; the registry row dies last.**

`deleteField()` commits one transaction and returns. From that commit the field is invisible to every first-class surface. The Reconciler then removes its key from `entry_data.fields` in chunks, and the *final chunk* is what hard-deletes `stardust_fields`.

## `stardust_fields.deleted_at` is the whole design

Exactly the `previous_name` pattern, on the same table for the same reason — `SlotResolver` and `LiveSlotMap` already SELECT it with no join, so the predicate rides along free on both hot paths. **A non-null value means exactly "a deletion is in flight for this field".**

It is a **drain-window marker, not a soft-delete tier.** There is no undelete, nothing retains the row, and the purge's last chunk removes it. If you find yourself adding a "restore" it is a different feature and needs its own ADR.

### Why the DELETE is deferred — and why it is *not* what you'd guess

Not because the foreign key forces it. `fk_slot_assignments_field` is `RESTRICT`, but `LiveSlotTombstoner` nulls `field_id` **before** flipping status, which releases it inside the same transaction. Measured on MySQL 8.0.13: the DELETE fails with errno 1451 before that runs and succeeds immediately after, with no Liberator sweep in between. A fully synchronous `deleteField()` is mechanically possible.

It is deferred because **the purge needs the field's name, model and tenant to build its JSON path, and `backfill_checkpoints` has no column for any of them.** Keeping the row alive under `deleted_at` lets `loadOneClaimable()` recover all three from a JOIN it already performs. That is the entire justification; if the checkpoint ever grows those columns, the deferral stops being necessary.

The `ROADMAP.md` entry that preceded this package says "demote then DELETE, both synchronous" and is correct about the mechanics. It was written before the payload-purge question was settled, which is what moved the DELETE to the end.

## `is_filterable = 0` is set in the same UPDATE, and it is load-bearing

A field with `is_filterable = 1` and no live slot **is demand**:

- `PendingDemandReader` would have the Watcher provision a whole page for a field being deleted (ADR 0035).
- `UnmappedFieldReserver` would reserve it a fresh slot on the ADR 0007 exhaustion path — **re-taking the foreign key, so the purge's final DELETE fails with errno 1451, permanently, because nothing retries it.**

Both gate on `is_filterable = 1`, so clearing it closes both. The registry readers additionally carry an explicit `deleted_at IS NULL`; a predicate whose absence is silent and unbounded belongs in the SQL twice.

## Who is severed immediately, and who deliberately is not

| Surface | Behaviour during the purge window |
| :-- | :-- |
| `read()` / `search()` results | Field omitted — `SlotResolver` excludes it from `fieldsByName` |
| `get()` point read | Key stripped via `SnapshotEntry::canonicalisePayloadKeys()` |
| Filters | **Rejected** as `UnknownFieldException` |
| `write()` / `updateEntry()` | Key **stripped** from the inbound payload by `LiveSlotMap::canonicalise()` |
| `describeModel()` | Field omitted |
| CSV export | Column omitted (`HeaderResolver`) |
| Slot reservation | Refused (`is_filterable = 0`, plus an explicit guard) |
| Watcher demand | Not counted |
| **JSON export artifact** | **Verbatim payload — not bridged.** Same documented carve-out as rename |
| **Raw `entry_data` dump** | Still shows the values until the purge reaches the row |

**The `get()` half is not optional.** The paginated read is driven by `fieldsByName`, which excludes the field outright, so without the strip the same entry would come back *with* the field through `get()` and *without* it through `read()` for the whole window. `DeleteWindowTest::testPointReadAgreesWithPaginatedReadDuringTheWindow` pins it.

**The write-path half is not optional, and it is not what you would first assume.** Leaving the field out of `LiveSlotMap` does *not* drop its key. An unregistered key is an **unknown** key, and ADR 0013 preserves those verbatim in `entry_data.fields` — that is the whole finding ADR 0036 rests on. So a client still sending the deleted name would keep writing it back into every new entry indefinitely, and into existing ones on update, including rows the purge cursor has already passed and will never revisit. The deletion would never complete in any observable sense.

`canonicalise()` therefore **strips** the key rather than relying on its absence from the map, and that strip is what bounds the purge. This was implemented backwards first and caught by `DeleteWindowTest::testWritingWithTheDeletedKeyDropsItSilently`.

## Lifecycle exclusivity is now N×N

Three lifecycles, checked in one fixed order everywhere — **rename → retype → delete** — so two concurrent initiators cannot each see the other's row as absent. `ux_backfill_job_name` remains the real backstop.

**Delete refuses; it does not cancel.** Deleting a field mid-rename or mid-retype would strand a `running` checkpoint that no worker can ever claim, because both sibling repositories recover the field id by INNER JOIN on a substring of `job_name` — invisible to the daemon, permanently non-zero on any dashboard counting running backfills. `RenameCheckpointRepository::loadOneClaimable()` has carried a docblock naming this obligation since before this package existed.

**The delete-side guards key on `deleted_at`, not on a checkpoint row.** That is deliberate: it still fires for a field whose purge checkpoint was manually failed or deleted, where a checkpoint-keyed guard would silently pass. They live in `RenameInitiator::loadField()` and `RetypeInitiator::loadField()` rather than on the `StarDust` facade, following the ADR 0036 placement ruling.

**But compaction is not the back door here that it is for rename, and the asymmetry is worth knowing.** `CompactionRepository::loadModelSlots()` selects on `status IN ('assigned','ready') AND is_filterable = 1`, and a deleting field fails *both* — its slot is tombstoned and `is_filterable` was cleared at initiation. A renaming field, by contrast, keeps its live slot and stays fully visible to the planner. So compaction can never *plan* a deleting field; it simply compacts what remains. The guard still earns its place by closing the plan-then-delete race, where a deletion commits between the planner reading the registry and `initiateRelocation()` acting on it. `DeleteConcurrencyTest::testCompactionIgnoresADeletingFieldRatherThanRefusing` pins the real behaviour — it was written asserting the rename analogy, and failed.

## The name is not reusable until the purge lands

`ux_fields_model_name` is unconditional, so a deleting field still holds its name. `SchemaBuilder::findFieldId()` excludes soft-deleted rows and `insertField()` translates the resulting errno 1062 into `FieldDeletionInProgressException`.

**The predicate on `findFieldId()` is a data-loss guard, not hygiene.** `defineField()` is get-or-create; without it the caller would silently adopt the id of a field whose values are actively being erased and whose row is about to be dropped.

Freeing the name at initiation was considered and rejected: it would mean renaming the field to a sentinel and stashing the real name in `previous_name`, which reads as "a rename is in flight" to five bridging surfaces and would make them alias the sentinel back.

## This package deletes from `backfill_checkpoints`

The first and only place in `src/` that does. Two sites:

- `deleteTerminalLifecycleRowsFor()` at initiation, clearing `completed`/`failed` rename and retype rows that would become orphans the moment the field row goes. Scoped by exact `job_name`, never a `LIKE`, so it cannot reach another field's rows.
- `delete()` on the final chunk, instead of `markCompleted()`. Every other lifecycle leaves an audit row keyed to a field that still exists; here the field is gone, so a surviving row is exactly the orphan this feature eliminates.

`src/Rename/CLAUDE.md`'s `insertOrReset()` rationale opens "Nothing in the engine ever deletes from `backfill_checkpoints`" — that observation is now false, and has been corrected there. The *conclusion* still holds: this repository uses `insertOrReset()` too, because a purge whose checkpoint was manually failed leaves both the row and `deleted_at` behind, and re-issuing the delete must resume rather than crash.

## The purge is SQL, not decode-mutate-encode

Same reasoning and the same trap as the rename executor: `json_decode($json, true)` is not round-trip faithful, so `{"0":"x","1":"y"}` re-encodes as `["x","y"]`, and `"0"` is a legal field name. `DeletePurgeTest::testPreservesPayloadFidelityIncludingNumericKeys` pins it.

`jsonPathFor()` is **reused from `RenameBackfillExecutor`** rather than re-derived. The quoted `$."name"` form is required, not cosmetic — bare `$.name` is only valid for bare ECMAScript identifiers, and field names permit spaces, hyphens, leading digits and Unicode.

**One path guard, not two.** `JSON_CONTAINS_PATH(fields, 'one', :path)` is for idempotence and an honest `rowCount()` — `JSON_REMOVE` on an absent path is already a no-op. The rename executor's second guard protects a destination key from a stale write; a delete has no destination.

## No `CAPACITY_WAIT`

The purge touches no slot — the initiator already tombstoned it — so it can never be blocked on inventory. The work source returns `WORK_DONE`, `IDLE`, or `LOCK_WAIT`.

`LOCK_WAIT` arrived on 2026-08-27 with the Reconciler-wide lock-retry budgets: `tickOne()` wraps `attemptOne()` on `Support\RetryableLockFailure`, and exhaustion defers the chunk rather than letting the `PDOException` kill the daemon. **Note this is the opposite of what the model purge below does** — see the divergence table.

## Final-chunk atomicity

`DELETE stardust_fields` + `DELETE backfill_checkpoints` + the version bump commit **together**. Dropping the field row while the checkpoint survived would strand a `running` row whose INNER JOIN can no longer resolve — precisely the orphan class this feature exists to remove.

---

## Model deletion (ADR 0038)

`DeleteModelInitiator`, `ModelDeleteCheckpoint`, `ModelDeleteCheckpointRepository`, `ModelDeleteChunkResult`, `ModelPurgeExecutor`, `ModelPurgeWorkSource`. Same shape as the field half, same order of operations, and the same one-line summary with one word changed:

**Severance is synchronous and total; the purge is asynchronous; the model row dies last — and it destroys rows rather than keys.**

### What it inherits for free, and why that is the design

Severance marks `stardust_models.deleted_at` **and** sets `deleted_at` + `is_filterable = 0` on every field of the model. That second half is the whole economy of the feature: every existing field-severance guard in the engine fires with **zero new predicates** — the read snapshot, the point-read strip, `LiveSlotMap::canonicalise()`, both `SchemaReader` queries, both `SchemaBuilder` get-or-create guards, both `HeaderResolver` methods, `PendingDemandReader`, `UnmappedFieldReserver`, `SlotReserver`, `MysqlNativeDriver::supportsFilterOn()`, and the rename/retype/delete initiators. `CompactionRepository` and `SpreadSampler` exclude them structurally instead (`status IN ('assigned','ready') AND is_filterable = 1` — a severed field fails both).

### `stardust_models.deleted_at` is NOT redundant with the field markers

The argument that decides it: a guard derived only from field markers has to be spelled *"no field of this model is live"* — and that is **true of every brand-new empty model**. A model registered with no fields is legal (`createModel($t, 'Foo')` with an empty list), so its severance would mark zero rows and the purge's claim query would have nothing to join through. `DeleteModelInitiatorTest::testAFieldlessModelIsSeveredAndOpensAClaimableCheckpoint` pins it.

### Four deliberate divergences from the field half

**1. Writes are refused, not stripped.** ADR 0037's most-easily-got-backwards rule inverted. A deleted field's key is stripped because a rejected write loses data while a strip converges; for a model there is no residual valid entry — the row would land behind the purge cursor (making the acceptance a lie) or ahead of it (a permanent orphan with a dangling `model_id`). `write()` / `updateEntry()` / `bulkWrite()` / `submitBulkWrite()` throw `ModelDeletionInProgressException`; `deleteEntry()` returns `false`; `read()` / `search()` / `get()` go **dark**.

**2. The final chunk re-asserts severance rather than trusting it.** If ADR 0037's final chunk is wrong, one row survives. If this one is wrong it throws errno 1451 *after every earlier chunk has already committed its deletes* — and the chunk fetch then returns nothing, so every subsequent tick believes it is the final chunk and rethrows forever. **There is no DLQ path, by design** (ADR 0018's quarantine would leave the exact orphan this eliminates), which is precisely why the last transaction must not be able to fail on a preventable condition. The re-assertion carries **no status predicate** on the slot sweep: measured, a `tombstoned` row that still holds a `field_id` re-breaks the cascade, because the FK cares about the column, not the status.

**3. The tenant predicate is the access path, not hygiene.** `model_id` is globally unique so `WHERE model_id = ?` selects identical rows — but both `entry_data` secondary indexes lead on `tenant_id`, and for a model whose rows are not spread uniformly across the PK (every model created after the first) the tenant-scoped chunk query is a **covering** range scan while dropping the predicate collapses it to a PK scan of the whole table. Measured 43× on 150 000 rows, per chunk. ADR 0029 omits a predicate that was never on the access path; this would omit the leading column of the only usable index — same principle, opposite conclusion.

**4. Lock failures are retried, the errno is 1205 not 1213, and exhaustion rethrows.** The purge cascades `entry_data` deletes into `entry_slots_page_X` while the Liberator nullifies the same rows — and severance tombstones every slot of the model, so the Liberator is *guaranteed* to be sweeping precisely those slots. Measured in both directions: **errno 1205, never 1213.** `ModelPurgeWorkSource` retries both on `Config::$modelPurgeLockRetryBudget`, and **there is no gap path** — skipping a chunk would leave rows with a dangling `model_id` forever, and since `entry_data` has no FK the final DELETE would still succeed, so nothing would notice.

**It also keeps its rethrow while the other five work sources moved to `TickOutcome::LOCK_WAIT` (2026-08-27).** Their argument — a rolled-back chunk is re-claimable with nothing skipped — is true here too. It is declined anyway: a soft outcome would render an unrecoverable drain as ordinary back-pressure, and this is the one drain in the engine that destroys rows. Two rules in the package is the honest outcome, not an inconsistency to tidy away.

That last one had to be fixed on the Liberator side too, in the same change: `SlotSweeper::isDeadlock()` matched only 40001/1213, `PollLoop` deliberately does not catch, and an unretried 1205 therefore **killed the Liberator daemon** for the duration of every model purge. Pinned by `LiberatorDeadlockRetryTest::testLockWaitTimeoutIsRetriedLikeADeadlock`.

### `stardust_sync_queue` rows die in the chunk transaction

Otherwise `SyncQueueWorkSource` finds no `entry_data` row for each survivor and files a `missing_entry_data` dead-letter row — the purge manufacturing DLQ noise in proportion to pending writes. This needed a new index: the table carried a PK and nothing else, and measured, deleting ten rows by `entry_id` from a 100 000-row queue was a full scan taking **100 261 exclusive record locks** (30 with `ix_sync_queue_entry`), held for a whole chunk transaction. `EntryWriter`'s exhaustion enqueue does not use `SKIP LOCKED`, so that is a blocked `write()` — an ADR 0007 regression. Bind the ids as literals; a subquery reverts to the full scan.

**One race ADR 0038 does not name, closed here:** `BackfillExecutor` reads `entry_data` non-locking then UPSERTs into page tables, so a purge committing in between produces errno 1452, which `SyncQueueWorkSource`'s catch-all files as `reason: 'other'` — the same DLQ noise through a different door. It returns an empty `BackfillResult` for a deleting model instead.

### `delete_model_{id}`, and why the LIKE is escaped

The fourth namespace. All four prefixes are thirteen characters, so every claim query shares `SUBSTRING(job_name, 14)`, and `delete_model_` / `delete_field_` diverge at position 8. But `job_name` is **operator-supplied** for Backfill Pump jobs and `_` is a single-character wildcard — `schema_reference` §5.4's own example is `model_42_rebuild`. For ADR 0037 a stray match meant a spurious `JSON_REMOVE`; here it is an unrecoverable `DELETE`. So the pattern goes through `Support\LikePattern` **and** the query carries `m.deleted_at IS NOT NULL`. Verified on MySQL 8.0.13: `deleteXmodelY_rebuild` matches the unescaped pattern and not the escaped one. All four repositories were escaped in the same change.

Severance opens **one** checkpoint, never one per field — N field purges would rewrite the same rows N times and never drop the model — and clears every field-scoped checkpoint across **all three** namespaces (the `delete_field_` leg is new relative to ADR 0037, and reachable: a field purge manually marked `failed` leaves its row).

### The E0 emptiness probe

`count($ids) < $chunkSize` is a hypothesis; the probe before the model DELETE is the proof. A write transaction that opened before severance committed carries a pre-severance snapshot for its whole life and can commit rows afterwards; `entry_data.id` is auto-increment so those land ahead of the cursor and the purge normally catches them — unless they commit after what we thought was the last chunk. One indexed probe per completed purge turns a silent permanent orphan into an extra tick. Beyond what ADR 0038 requires, and kept deliberately.

### Testing note: the vacuous-pass hazard is worse than for a field

A *model* window test can pass for free in two ways, not one: the purge already finished, **or the model never existed** — `read()` on an unknown model id returns an empty page and `describeModel()` returns null today, with no code change at all. `ModelDeleteWindowTest::halfPurgedModel()` therefore asserts five things about its own fixture before returning, and `testTheDarkAssertionsCannotDistinguishAModelThatNeverExisted` records the reason in code.
