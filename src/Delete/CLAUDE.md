# Delete pipeline

ADR 0037 field deletion. Five `final` collaborators mirroring the ADR 0036 rename shape — and mirroring it closely enough that the rename package is the right thing to read first.

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

The purge touches no slot — the initiator already tombstoned it — so it can never be blocked on inventory. The work source returns only `WORK_DONE` or `IDLE`.

## Final-chunk atomicity

`DELETE stardust_fields` + `DELETE backfill_checkpoints` + the version bump commit **together**. Dropping the field row while the checkpoint survived would strand a `running` row whose INNER JOIN can no longer resolve — precisely the orphan class this feature exists to remove.
