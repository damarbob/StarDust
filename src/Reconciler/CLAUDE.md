# Reconciler daemon

Phase 5 multi-worker drain (ADR 0008). No PID guard — `SELECT … FOR UPDATE SKIP LOCKED` is the only coordination primitive. Horizontal scale = more `bin/stardust reconciler` processes.

## The tick

`Reconciler` implements `Tickable`. Each `tick()` generates one `chunk_correlation_id` via `UuidV4` and ticks each `ReconcilerWorkSource` once, round-robin, in this order: `SyncQueueWorkSource`, `ImportJobWorkSource`, `RetypeBackfillWorkSource` (`src/Retype/`), `RenameBackfillWorkSource` (`src/Rename/`), `DeletePurgeWorkSource` and `ModelPurgeWorkSource` (both `src/Delete/`). **New sources append, never insert** — the order is observable in event streams.

- `CAPACITY_WAIT` from any source sleeps `Config::$reconcilerCapacityWaitMillis` and short-circuits the tick.
- `WORK_DONE` from a source sleeps `Config::$reconcilerInterChunkDelayMicros` before moving to the next source — paces drain throughput per the Phase 5 deliverable; default `0` means no pacing.
- `LOCK_WAIT` is handled exactly like `CAPACITY_WAIT` and borrows its sleep, for an analogous reason: the contention is on the shared `entry_slots_page_X` tables every backfill writes, so one source losing a lock predicts the rest will too, and marching on would spend their budgets against the same holder.

## Lock failures do not kill the daemon

Every work source here wraps its claim-plus-chunk transaction in a bounded retry on `Support\RetryableLockFailure` (errno 1205 / 1213 / SQLSTATE 40001), budget `Config::$reconcilerLockRetryBudget` (3). Each attempt logs `deadlock_retry`; exhaustion logs `lock_wait` and **returns `TickOutcome::LOCK_WAIT` rather than rethrowing**.

That return is the whole point. `PollLoop` deliberately does not catch tick exceptions, so a propagating `PDOException` exits the process, which then crash-loops for as long as the contending sweep runs — the failure ADR 0038 had to fix for the Liberator and which lived on here. Returning is safe because each source's cursor lives on a row its own chunk transaction owns, so a rollback leaves it untouched and the next tick retries identical work: nothing skipped, nothing failed.

`ModelPurgeWorkSource` is the deliberate exception and still rethrows; its own docblock says why.

**`chunk_claimed` is a claim-*attempt* event.** It is emitted inside the chunk transaction, so it survives a rollback and a retried chunk logs it once per attempt, correlated by `chunk_correlation_id`. Moving it after the commit would make it fire alongside `chunk_complete` and say nothing.

**Two catch blocks had to be narrowed, and they are the interesting half of this.** A retry wrapper is useless if something inside the tick swallows the exception first:

- `SyncQueueWorkSource`'s per-row `catch (Throwable)` filed a **DLQ row with `reason: 'other'`** — quarantining a perfectly good entry, permanently, because the Liberator happened to be sweeping the same page table. ADR 0018's DLQ is for rows that cannot succeed; a lock timeout says nothing about the row.
- `ImportJobWorkSource`'s window `catch (Throwable)` called `failJob('entry_write_failed')` — **permanently destroying a consumer's import** over a condition that clears on its own.

Both now let a retryable lock failure through to the budget.

## `SyncQueueWorkSource`

`tickOne($corrId)` claims a chunk via `SELECT id, entry_id … ORDER BY id LIMIT N FOR UPDATE SKIP LOCKED`, then calls `BackfillExecutor::backfill()` per row inside the chunk transaction. Exception routing to DLQ:

- `EntryDataMissingException` → `reason='missing_entry_data'`
- `UncoercibleSlotValueException` → `reason='schema_incompatibility'`
- any other `Throwable` → `reason='other'` — **except a retryable lock failure**, which is rethrown to the tick's retry budget rather than quarantined

If `BackfillResult::hasStillUnmapped()` is true on any row, the chunk **rolls back entirely** — queue rows stay claimable for the next tick.

Since ADR 0034 scoped the enqueue to filterable fields, `capacity_wait` can only be triggered by a filterable field genuinely awaiting capacity, so sync-queue depth is a true signal of filterable backfill debt rather than a permanently stuck loop. Pre-existing queue rows whose only unmapped field is non-filterable drain themselves on the first tick after deploy: `stillUnmapped` comes back empty, the row is deleted, `chunk_complete` fires.

Events: `chunk_claimed` after the SELECT, `chunk_complete` when all survivors succeed, `chunk_partial` when any row went to DLQ.

### The exhaustion reservation — `UnmappedFieldReserver` (ADR 0007)

ADR 0007 resolves slot exhaustion as "write now, backfill once a free slot becomes available", but for a long time nothing in `src/` ever *made* one available for a plain unmapped filterable field. The rollback above was the whole story, so a field registered filterable through `schemaBuilder()` never acquired a slot and the Watcher's `pending_demand` gauge had nothing draining it.

So after the rollback, the still-unmapped field names go to `UnmappedFieldReserver::reserveFor(entryId, names)`, which resolves them to ids through the entry's own model and calls `SlotReserver::reserveForExhaustionBackfill()` once each:

- **At least one reserved** → return `WORK_DONE` and emit **no** `capacity_wait`. The reserver's own `slot_reserved` event is the record; firing `capacity_wait` on a recovery would make every recovery look like an alert. The next tick re-claims the same rows and drains them.
- **None reserved** → emit `capacity_wait`, return `CAPACITY_WAIT`, exactly as before. The Watcher's ADR 0035 unsatisfiable-demand trigger then provisions a page carrying the starved family's index.

**Three things here are load-bearing, none of them obvious:**

1. **The reservation happens after the rollback, never inside the chunk transaction.** `SlotReserver` bumps the `stardust_schema_version` singleton on every success; holding that row for a chunk's duration would make every Reconciler worker contend on it (ADR 0008).
2. **`requireIndexed` is hardcoded `true`** in `reserveForExhaustionBackfill()`. The slot goes live as `assigned`, so `MysqlNativeDriver::supportsFilterOn()` returns true at once — landing on an unindexed column would have the compiler emit a predicate against an unindexed column, violating ADR 0004. A deployment whose pages predate the Watcher's index-aware provisioning (ADR 0035) therefore keeps waiting, correctly, until the Watcher provisions an indexed one.
3. **A `PDOException` from the reservation is swallowed, not propagated.** Two workers can hit capacity-wait on the same field from disjoint chunks; the loser of the `FOR UPDATE` race picks a different free row and trips `ux_slot_assignments_field_live` (ADR 0017). That is the desired end state reached by someone else, so it is caught and *not* counted — the tick reports `capacity_wait` and the next one drains against the committed slot.

The field is filterable-but-incompletely-materialised until the queue drains. ADR 0007 names and accepts exactly that; the window is bounded by sync-queue depth, which that ADR already designates as the leading indicator to monitor. Do **not** "fix" this into a `backfilling → ready` promotion — that is ADR 0016's lifecycle for retypes, it has no promotion trigger on this path, and it would scan the whole `(tenant_id, model_id)` partition to write a handful of values.

## `ImportJobWorkSource`

Dual-path since the Gap 5 resolution (2026-06-18), mirroring the Chronicler's `ExportJobClaimer`.

**Pending path:** `UPDATE … SET status='processing', worker_identity=?, claimed_at=NOW(), heartbeat_at=NOW() WHERE status='pending' ORDER BY id LIMIT 1`.

**Abandoned path** (when the pending path claims nothing): `SELECT id … WHERE status='processing' AND heartbeat_at < (UTC_TIMESTAMP() - INTERVAL Config::$reconcilerImportLeaseTimeoutSeconds SECOND) ORDER BY heartbeat_at ASC LIMIT 1 FOR UPDATE SKIP LOCKED`, then `UPDATE … SET worker_identity=?, heartbeat_at=?` with **claimed_at preserved**.

`worker_identity = host:pid:uuid` for unique lease ownership.

**The lock retry here is per-window, not per-tick.** The claim is an autocommitted UPDATE that runs *before* any transaction, so a whole-tick retry would re-claim the job and re-read the artifact. `writeWindow()` exists for exactly that seam. On exhaustion the job stays `processing` under this worker's identity and nothing resumes it until `Config::$reconcilerImportLeaseTimeoutSeconds` (30 s) lapses and the abandoned-claim path picks it up from `manifest.entries_written` — lossless, and slower than the other four sources' next tick. That latency is the accepted cost of not failing the job.

`$entriesWritten` is passed by reference and incremented per entry *inside* the transaction, so a retry rewinds it to the window's starting value first.

### Resume semantics

It decodes the ADR 0028 single-document JSON artifact and **resumes from the `manifest` checkpoint**. `entries_written` is the count of already-committed entries, so a re-claimed job restarts at `offset = manifest.entries_written` and never re-processes a committed chunk — no duplicate `entry_data` rows. Resume stays exact even across a `chunkSize` change, because entries are position-indexed.

It iterates `entries` in `Config::$reconcilerChunkSize` windows calling `EntryWriter::writeWithinTransaction()` per entry.

### Lease-loss self-abort

Each window's transaction writes the running `manifest` **and** `heartbeat_at` via `UPDATE … SET heartbeat_at=?, manifest=? WHERE id=? AND worker_identity=self`.

`rowCount()===0` is the lease-loss detector — a re-claimer overwrote our identity. The worker rolls back the chunk, emits `lease_lost`, and returns **without** marking the row failed: the re-claimer owns terminal state, per schema_reference §5.5 / ADR 0025.

This is reliable because `manifest.entries_written` strictly increases, so a matched row is always *changed* — `rowCount()===0` can only mean an identity mismatch, never a no-op update.

### Terminal states

`completed` with a manifest of `{chunks, entries_written}`, or `failed` with `failed_reason` (`malformed_json` for artifact failures, `entry_write_failed` for per-entry failures) plus a DLQ row.

## DLQ (ADR 0018)

`DlqWriter::quarantine(DlqEntry)` inserts a row and emits `dlq_inserted` with the same `chunk_correlation_id` persisted in the column.

`DlqReplayer::replayById(int)` / `replayByReason(string)` re-enqueues into `stardust_sync_queue`, bumps `retry_count`, and deletes the DLQ row — all in one transaction. Throws `DlqReplayNotFoundException` on no match. `bulk_import` DLQ rows with NULL `entry_id` are deleted without re-enqueue, since `stardust_sync_queue` only accepts `entry_id`.
