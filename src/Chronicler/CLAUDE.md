# Chronicler daemon

Phase 7 multi-worker async export drain (ADR 0010, ADR 0025, ADR 0027). Eight `final` collaborators plus the orchestrator, SOLID-decomposed. The synchronous submission half is `src/Export/`.

**Multi-worker by design**: no PID guard; `SELECT … FOR UPDATE SKIP LOCKED` is the only coordination primitive. Horizontal scaling = more `bin/stardust chronicler` processes.

## `Chronicler::tick()`

1. Checks `DiskPressureGate::shouldSkipClaim()`. Below threshold ⇒ emit `low_disk` (cycle-scoped) and fall through to GC. **In-flight jobs are unaffected** — this gates claiming only.
2. Asks `ExportJobClaimer::claimPendingOrAbandoned()` for one job.
3. On idle (no claim), runs `GcSweeper::sweep()`.

## `ExportJobClaimer`

**Pending path first:**

```sql
SELECT … FROM stardust_export_jobs j WHERE status='pending'
ORDER BY (SELECT MIN(j2.created_at) FROM stardust_export_jobs j2
          WHERE j2.status='pending' AND j2.tenant_id=j.tenant_id) ASC,
         j.created_at ASC
LIMIT 1 FOR UPDATE SKIP LOCKED
```

The subquery materialises per-tenant round-robin (chronicler_daemon.md §4 AC#3) at claim time, without a separate column. Then `UPDATE … SET status='processing', worker_identity=?, claimed_at=?, heartbeat_at=?` in the same transaction.

**Abandoned path on idle:** `WHERE status='processing' AND heartbeat_at < (UTC_TIMESTAMP() - INTERVAL leaseTimeout SECOND) FOR UPDATE SKIP LOCKED`. Best-effort `@unlink` of the prior `artifact_path` happens AFTER the claim commits, then `UPDATE … SET worker_identity=?, heartbeat_at=?` — **claimed_at preserved**, so operators still see the original claim time.

Worker identity = `host:pid:UuidV4` via `WorkerIdentity::mint()`.

## `ExportJobProcessor::process(ClaimedJob, correlationId)`

Resolves the deterministic CSV header via `HeaderResolver::resolve($tenantId, $modelId)` (alphabetically-sorted union of `stardust_fields.name` for the model), opens an `ArtifactStream` via `ArtifactStreamFactory::from()` (single dispatch on `$job->format`), then loops:

1. `EntryDataPager::fetchChunk()` runs `SELECT id, fields FROM entry_data WHERE tenant_id=? AND model_id=? AND deleted_at IS NULL AND id > :cursor ORDER BY id ASC LIMIT pageSize+1` — the `+1` is the next-page signal per ADR 0005.
2. Per row, `ArtifactStream::appendRow()` encodes and writes. CSV: RFC 4180 quoting, `\r\n` line ending, header derived from `stardust_fields`. JSON: single-document array streamed with a leading `[`, a `,`-prefix for subsequent rows, and a trailing `]` on close.
3. Checks `bytesWritten() > artifactSizeCapBytes`.
4. Commits the chunk atomically: `UPDATE stardust_export_jobs SET last_cursor=?, heartbeat_at=?, skip_count=? [, status='completed', artifact_path=?, completed_at=? if isFinal] WHERE id=? AND worker_identity=?`.

### The lease-loss detector

**The `WHERE worker_identity = self_identity` predicate IS the detector.** `PDOStatement::rowCount() === 0` ⇒ a re-claimer overwrote our row ⇒ emit `lease_lost`, delete the partial, return `JobOutcome::LeaseLost` **without** marking the row failed. The re-claimer owns terminal state.

### Failure semantics (ADR 0025)

- **Deadlock** (`SQLSTATE 40001`): retries the same chunk after `interChunkDelayMicros`, up to `deadlockRetryBudget` (3) attempts. Exhaustion emits `chunk_skipped{cause:deadlock_budget_exhausted}`, advances the cursor by `pageSize`, charges `skip_count += pageSize`, and continues.
- **Per-row encoding failure**: emits `row_skipped` + `skip_count++` with the closed-taxonomy `reason` (`format_invalid` | `unrepresentable_codepoint`).
- **`skip_count > skipCountCap`** (1 000): delete partial, mark `failed:excessive_skips`, emit `job_failed{reason:excessive_skips}`.
- **Artifact over cap**: emit `artifact_oversized` (an event distinct from `job_failed`), delete partial, mark `failed:artifact_size_exceeded`.
- **`ENOSPC` during append**: `failed:disk_full`.

### DB disconnect mid-pagination

Triggers the fixed `[1, 4, 16]`-second backoff schedule, slept via the injected `$sleepFn`. Each attempt rebuilds a **fresh** connection through the injected `Config::$pdoConnector` and re-points BOTH `$this->pdo` and `$this->pager`, so the chunk loop resumes from `last_cursor`.

That double re-point is ADR 0025 Commitment 6 and the substance of the 2026-06-03 fix: PDO never self-heals, so re-pinging the dead handle was a no-op.

When no connector is wired, or the schedule exhausts ⇒ `failed:query_failure` with `last_cursor` **preserved** for operator-initiated restart, plus `job_failed{reason:db_disconnect_exhausted}`.

The reconnect heals only the in-flight job's connection. A disconnect that outlives the job lets the next claim tick fail loudly and the process exit (restart → fresh PDO) — `ExportJobClaimer` and `GcSweeper` are **intentionally** not re-pointed.

### Why `skip_count` is persisted every chunk

So a re-claimer continues charging from the previous worker's count. Otherwise a dying worker could let a re-claimer charge another full cap before tripping.

## `GcSweeper`

Scans two buckets:

- `status='completed' AND completed_at < UTC_TIMESTAMP() - INTERVAL artifactTtlSeconds SECOND` (24 h default)
- `status='failed' AND completed_at < UTC_TIMESTAMP() - INTERVAL orphanedPartialTtlSeconds SECOND` (1 h default)

Per row: `@unlink` + `UPDATE … SET artifact_path = NULL`. `gc_swept` is emitted ONLY when `artifactsDeleted > 0`, so idle cycles produce no event spam.
