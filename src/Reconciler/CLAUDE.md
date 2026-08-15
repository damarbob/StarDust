# Reconciler daemon

Phase 5 multi-worker drain (ADR 0008). No PID guard — `SELECT … FOR UPDATE SKIP LOCKED` is the only coordination primitive. Horizontal scale = more `bin/stardust reconciler` processes.

## The tick

`Reconciler` implements `Tickable`. Each `tick()` generates one `chunk_correlation_id` via `UuidV4` and ticks each `ReconcilerWorkSource` once, round-robin: `SyncQueueWorkSource`, then `ImportJobWorkSource`, then Phase 6b's `RetypeBackfillWorkSource` (which lives in `src/Retype/`).

- `CAPACITY_WAIT` from any source sleeps `Config::$reconcilerCapacityWaitMillis` and short-circuits the tick.
- `WORK_DONE` from a source sleeps `Config::$reconcilerInterChunkDelayMicros` before moving to the next source — paces drain throughput per the Phase 5 deliverable; default `0` means no pacing.

## `SyncQueueWorkSource`

`tickOne($corrId)` claims a chunk via `SELECT id, entry_id … ORDER BY id LIMIT N FOR UPDATE SKIP LOCKED`, then calls `BackfillExecutor::backfill()` per row inside the chunk transaction. Exception routing to DLQ:

- `EntryDataMissingException` → `reason='missing_entry_data'`
- `UncoercibleSlotValueException` → `reason='schema_incompatibility'`
- any other `Throwable` → `reason='other'`

If `BackfillResult::hasStillUnmapped()` is true on any row, the chunk **rolls back entirely** and emits `capacity_wait` — queue rows stay claimable for the next tick.

Since ADR 0034 scoped the enqueue to filterable fields, `capacity_wait` can only be triggered by a filterable field genuinely awaiting capacity, so sync-queue depth is a true signal of filterable backfill debt rather than a permanently stuck loop. Pre-existing queue rows whose only unmapped field is non-filterable drain themselves on the first tick after deploy: `stillUnmapped` comes back empty, the row is deleted, `chunk_complete` fires.

Events: `chunk_claimed` after the SELECT, `chunk_complete` when all survivors succeed, `chunk_partial` when any row went to DLQ.

## `ImportJobWorkSource`

Dual-path since the Gap 5 resolution (2026-06-18), mirroring the Chronicler's `ExportJobClaimer`.

**Pending path:** `UPDATE … SET status='processing', worker_identity=?, claimed_at=NOW(), heartbeat_at=NOW() WHERE status='pending' ORDER BY id LIMIT 1`.

**Abandoned path** (when the pending path claims nothing): `SELECT id … WHERE status='processing' AND heartbeat_at < (UTC_TIMESTAMP() - INTERVAL Config::$reconcilerImportLeaseTimeoutSeconds SECOND) ORDER BY heartbeat_at ASC LIMIT 1 FOR UPDATE SKIP LOCKED`, then `UPDATE … SET worker_identity=?, heartbeat_at=?` with **claimed_at preserved**.

`worker_identity = host:pid:uuid` for unique lease ownership.

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
