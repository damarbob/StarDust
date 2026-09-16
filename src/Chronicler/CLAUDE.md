# Chronicler daemon

Phase 7 multi-worker async export drain (ADR 0010, ADR 0025, ADR 0027, ADR 0047, ADR 0050). Eight `final` collaborators plus the orchestrator, SOLID-decomposed. The synchronous submission half is `src/Export/`.

**Multi-worker by design**: no PID guard; `SELECT … FOR UPDATE SKIP LOCKED` is the only coordination primitive. Horizontal scaling = more `bin/stardust chronicler` processes.

## `Chronicler::tickRound(?YieldSignal $yield = null): ChroniclerOutcome`

`tick()` (the `Tickable` contract the standalone poll loop uses) delegates here and discards the result — the same seam `Reconciler::tickRound()` and `Liberator::sweepBatch()` already are, so `CombinedTick` (ADR 0048/0050) can tell an idle round from one where a job is converging without a second query.

1. Calls `DiskPressureGate::sample()` exactly once and reads `DiskPressureReading::shouldSkipClaim()` off the result — every field the `low_disk` event logs (`partition`, `free_pct`, `threshold_pct`) comes off that same reading, so the logged value can never disagree with the one that decided to skip. Below threshold ⇒ emit `low_disk` (cycle-scoped) and fall through to GC, return `IDLE`. **In-flight jobs are unaffected** — this gates claiming only. The probe is always taken against `artifactDir` itself (never a `sys_get_temp_dir()` fallback), so `partition` never names a directory other than the one measured; a missing `artifactDir` (not yet created by `ArtifactStreamFactory`) reads as `null` and fails open, same as any other probe failure. **The gate does not yet see a per-account disk quota** — tracked as open build-sequencing work.
2. Asks `ExportJobClaimer::claimPendingOrAbandoned()` for one job. `null` ⇒ run `GcSweeper::sweep()`, return `IDLE`.
3. Otherwise emits `job_claimed` and calls `ExportJobProcessor::process($claim, $correlationId, $yield ?? $this->yieldSignal)` — the per-call `$yield` overrides the instance's own constructor default rather than replacing it. Maps `JobOutcome::Yielded` to `ChroniclerOutcome::YIELDED`; everything else (completed, any failure flavour, lease lost) to `WORKED`.

## `ExportJobClaimer`

**Pending path first:**

```sql
SELECT … artifact_path, artifact_bytes … FROM stardust_export_jobs j WHERE status='pending'
ORDER BY (SELECT MIN(j2.created_at) FROM stardust_export_jobs j2
          WHERE j2.status='pending' AND j2.tenant_id=j.tenant_id) ASC,
         j.created_at ASC
LIMIT 1 FOR UPDATE SKIP LOCKED
```

The subquery materialises per-tenant round-robin (chronicler_daemon.md §4 AC#3) at claim time, without a separate column. Then `UPDATE … SET status='processing', worker_identity=?, claimed_at=?, heartbeat_at=?` in the same transaction.

**Per ADR 0050, this path now selects and forwards `artifact_path`/`artifact_bytes` too — the same columns the abandoned path already did.** A `pending` row carrying a usable anchor (non-null path, positive byte count — the identical predicate `ArtifactStreamFactory::from()` applies before attempting adoption) is a previously-*yielded* job, not a fresh submission; the claim kind is `ClaimKind::Resumed` rather than `Pending` in that case. **This was the original defect in the yield's first sketch**: without it, a yielded job re-claimed through this path got no anchor, a fresh path from `ArtifactStreamFactory`, and restarted from byte zero on every tick — orphaning one partial per tick and never converging on a job larger than one chunk.

**Abandoned path on idle:** `WHERE status='processing' AND heartbeat_at < (UTC_TIMESTAMP() - INTERVAL leaseTimeout SECOND) FOR UPDATE SKIP LOCKED`. **Per ADR 0047 the claimer does NOT unlink the prior `artifact_path`** — it hands `artifact_path`/`artifact_bytes` forward on the `ClaimedJob` so `ExportJobProcessor`'s stream can attempt a verified re-open (see below). `UPDATE … SET worker_identity=?, heartbeat_at=?` — **claimed_at preserved**, so operators still see the original claim time.

Worker identity = `host:pid:UuidV4` via `WorkerIdentity::mint()`.

## `ExportJobProcessor::process(ClaimedJob, correlationId, ?YieldSignal $yield = null)`

Resolves the deterministic CSV header via `HeaderResolver::resolve($tenantId, $modelId)` (alphabetically-sorted union of `stardust_fields.name` for the model), opens an `ArtifactStream` via `ArtifactStreamFactory::from()` (single dispatch on `$job->format`), reads `$stream->resumedFromByte()` to decide the starting cursor (see below), emits `artifact_resumed`, then loops:

1. `EntryDataPager::fetchChunk()` runs `SELECT id, fields FROM entry_data WHERE tenant_id=? AND model_id=? AND deleted_at IS NULL AND id > :cursor ORDER BY id ASC LIMIT pageSize+1` — the `+1` is the next-page signal per ADR 0005.
2. Per row, `ArtifactStream::appendRow()` encodes and writes. CSV: RFC 4180 quoting, `\r\n` line ending, header derived from `stardust_fields`. JSON: single-document array streamed with a leading `[`, a `,`-prefix for subsequent rows, and a trailing `]` on close.
3. Checks `bytesWritten() > artifactSizeCapBytes`.
4. `$stream->flush()`, THEN — on a non-final chunk, if `$yield?->yieldCause()` is non-null — captures the anchor and closes the stream (see below), THEN commits the chunk atomically: `UPDATE stardust_export_jobs SET last_cursor=?, heartbeat_at=?, skip_count=?, artifact_path=?, artifact_bytes=? [, status='completed', completed_at=? if isFinal | , status='pending', worker_identity=NULL if yielding] WHERE id=? AND worker_identity=?`. **`artifact_path`/`artifact_bytes` are written on EVERY commit now, not only the final one** — that's what makes the row a usable resume anchor for the next abandoned re-claim, per ADR 0047, and now for the next yield-resume too. The flush-before-write ordering matters: the DB must never promise bytes the OS hasn't taken yet, or a future resume's verification could work against phantom bytes.

### The resume anchor (ADR 0047)

**`ArtifactStreamFactory::from()` always mints a fresh, never-before-used path**, and — when the claim carries `artifactPath`/`artifactBytes` — ALSO passes the anchor path/bytes separately. `open()` tries the anchor first: `fopen($anchor, 'c+b')`, `flock(LOCK_EX|LOCK_NB)`, verify the file **exists** (checked explicitly — `'c+b'` would otherwise silently CREATE a missing one, masking `missing` as a 0-byte `short`), holds ≥ the anchored bytes, and — CSV only — that its first line still matches today's resolved header (a rename between attempts changes it). On success: `ftruncate()` to exactly the anchored bytes (discarding anything a crashed chunk wrote past that point), `fseek()` there, skip the prelude, return `resumedFromByte() > 0`.

**On ANY verification failure it opens the FRESH path instead — never the rejected anchor path.** This is deliberate, not incidental: a `locked` rejection specifically can mean another live process (a zombie worker that hasn't yet self-detected its lease loss) is still writing the anchor file, and reusing that exact path would risk two writers on one file — verified empirically on this project's Windows dev environment, where `fopen(..., 'wb')` on a path another handle holds `flock(LOCK_EX)` on SUCCEEDS but the subsequent `fwrite()` FAILS. `path()` reports whichever path ended up in use; the processor reads it via `$stream->path()` on every commit.

`ExportJobProcessor` never trusts `$job->lastCursor` directly — it checks `$stream->resumedFromByte() > 0` after `open()` and only then starts the probe there; otherwise it starts at 0 regardless of what the row said. This is what makes the invariant self-enforcing: a pending claim's `ClaimedJob` carries no anchor at all, so it always resumes at byte 0 and therefore always probes at row 0 — no special-casing by `ClaimKind` needed.

### The lease-loss detector

**The `WHERE worker_identity = self_identity` predicate IS the detector.** `PDOStatement::rowCount() === 0` ⇒ a re-claimer overwrote our row ⇒ emit `lease_lost`, `close()` — releasing the lock and handle only, **NOT `delete()`** — return `JobOutcome::LeaseLost` **without** marking the row failed. The re-claimer owns terminal state, and per ADR 0047 it may already be resuming from these exact bytes, so deleting here would be the single most dangerous interaction the resume design has to avoid.

**A yield commit uses this SAME predicate, unmodified** — see below. A yield racing a genuine concurrent re-claim is not a new race to reason about; it resolves exactly like any other chunk commit racing one, because it IS the same UPDATE shape with two extra `SET` clauses.

### Cooperative yield (ADR 0050)

After a **non-final** chunk's `flush()`, `process()` checks `$yield?->yieldCause()`. A non-null cause (`'budget'` | `'shutdown'`) means: capture the anchor, close the stream, commit with `status='pending', worker_identity=NULL` folded into the SAME UPDATE as the ordinary chunk commit, return `JobOutcome::Yielded`. Never checked on the final chunk (the last chunk always completes the job) and never before the first commit (a yield always carries at least one chunk of progress) — together these bound convergence to at most N ticks for an N-chunk job.

**Capture-before-close is load-bearing, not stylistic.** `path()` and `bytesWritten()` are read immediately after `flush()`, BEFORE any `close()` call:

```php
$artifactPath  = $stream->path();
$artifactBytes = $stream->bytesWritten();   // captured before close()

if ($yieldCause !== null) {
    try { $stream->close(); } catch (ChroniclerArtifactDiskFullException) { /* anchor already captured */ }
}
```

`JsonArtifactStream::close()` writes the trailing `]` through the same `writeRaw()` that increments `bytesWritten()`. Capturing after `close()` would anchor the terminator byte into the committed `artifact_bytes`; the next resume's `ftruncate()` would keep that `]` and append the next row's bytes directly after it — invalid JSON. `CsvArtifactStream::close()` has no equivalent write, which is exactly why this bug would survive a CSV-only test — `tests/Smoke/Chronicler/ChroniclerYieldTest` covers both formats for this reason, asserting the yielded-and-resumed artifact is **byte-identical** to a control run that never yielded, not merely that the job eventually reaches `completed`.

**Close-before-commit is the yield's one asymmetry against an abandoned claim.** Releasing the file's exclusive lock before the row becomes claimable means a resumer's `open()` verification never observes `restart_cause: 'locked'` — the one ADR 0047 rejection cause reserved for "another process may still be actively writing this." An abandoned claim's prior worker may still be alive and holding the lock (that's why `locked` exists); a yield's prior worker has, by construction, already released it.

A `close()` that itself trips `ChroniclerArtifactDiskFullException` (the trailing `]` doesn't fit) is caught and ignored on the yield path: the anchor already captured describes bytes the filesystem genuinely took, so the yield still commits; the next resume's `ftruncate()` trims past whatever partial terminator landed, and the next `appendRow()` on the resumed stream trips disk-full properly if the condition persists.

**`ChunkOutcome::$yielded`** distinguishes a successful yield commit from a lease-lost one — `commitChunk(..., yield: true)` sets it only when `$affected > 0`, so `process()`'s existing `if ($outcome->leaseLost)` check (unmodified, checked first) always wins over a yield that lost the race.

### Failure semantics (ADR 0025)

- **Deadlock** (`SQLSTATE 40001`): retries the same chunk after `interChunkDelayMicros`, up to `deadlockRetryBudget` (3) attempts. Exhaustion emits `chunk_skipped{cause:deadlock_budget_exhausted}`, advances the cursor by `pageSize`, charges `skip_count += pageSize`, and continues.
- **Per-row encoding failure**: emits `row_skipped` + `skip_count++` with the closed-taxonomy `reason` (`format_invalid` | `unrepresentable_codepoint`).
- **`skip_count > skipCountCap`** (1 000): delete partial, mark `failed:excessive_skips`, emit `job_failed{reason:excessive_skips}`.
- **Artifact over cap**: emit `artifact_oversized` (an event distinct from `job_failed`), delete partial, mark `failed:artifact_size_exceeded`.
- **`ENOSPC` during append**: `failed:disk_full`.

**Every terminal-failure path's `markFailed()` UPDATE also NULLs `artifact_path`/`artifact_bytes` in the same transaction as the status flip** (ADR 0047) — the anchor dies with the file the same statement's caller already deleted, so a `failed` row never advertises a resume path to bytes that no longer exist.

### DB disconnect mid-pagination

Triggers the fixed `[1, 4, 16]`-second backoff schedule, slept via the injected `$sleepFn`. Each attempt rebuilds a **fresh** connection through the injected `Config::$pdoConnector` and re-points BOTH `$this->pdo` and `$this->pager`, so the chunk loop resumes from `last_cursor`.

That double re-point is ADR 0025 Commitment 6 and the substance of the 2026-06-03 fix: PDO never self-heals, so re-pinging the dead handle was a no-op.

When no connector is wired, or the schedule exhausts ⇒ `failed:query_failure` with `last_cursor` **preserved** for operator-initiated restart, plus `job_failed{reason:db_disconnect_exhausted}`.

The reconnect heals only the in-flight job's connection. A disconnect that outlives the job lets the next claim tick fail loudly and the process exit (restart → fresh PDO) — `ExportJobClaimer` and `GcSweeper` are **intentionally** not re-pointed.

### Why `skip_count` is persisted every chunk

So a re-claimer continues charging from the previous worker's count. Otherwise a dying worker could let a re-claimer charge another full cap before tripping.

## The job's correlation id comes from the submission

`Chronicler::tick()` adopts `ClaimedJob::$correlationId` — read from `stardust_export_jobs.correlation_id`, written by `ExportJobSubmitter` — instead of minting one, so `export_accepted` and every event this worker emits about the job join across the two processes. It falls back to a fresh id for a job submitted before the column existed.

**This deliberately covers `chunk_written` too**, which is the opposite of the Reconciler's rule and not an inconsistency. There, one tick claims rows from many unrelated operations, so a chunk is its own operation. Here the Chronicler processes one job across all its chunks in a single continuous `process()` call, so the job *is* the operation and every event of it shares the id.

**An abandoned re-claim — or a resumed (previously yielded, ADR 0050) claim — reuses the same id**, for the same reason: it is the same job. `worker_identity` is what separates the attempts, and it is already on every one of these events.

**`job_yielded`'s `rows_streamed_total` is scoped to this worker's attempt, NOT cumulative across the job's whole lifetime** — the same limitation `job_complete`'s own field already has across an abandoned re-claim. A job yielded three times and re-claimed each time emits four separate row counts (three `job_yielded` plus one final `job_complete`); summing all four is how an operator gets the true total.

## `GcSweeper`

Scans two buckets:

- `status='completed' AND completed_at < UTC_TIMESTAMP() - INTERVAL artifactTtlSeconds SECOND` (24 h default)
- `status='failed' AND completed_at < UTC_TIMESTAMP() - INTERVAL orphanedPartialTtlSeconds SECOND` (1 h default)

Per row: `@unlink` + `UPDATE … SET artifact_path = NULL`. `gc_swept` is emitted ONLY when `artifactsDeleted > 0`, so idle cycles produce no event spam.

Since ADR 0047 made `artifact_path`/`artifact_bytes` populated on every chunk commit rather than only the final one, a job that dies mid-flight and later reaches `failed` (its terminal-failure path already deletes the file and NULLs both columns — see above) never reaches this sweep with a stale path; the bucket-2 orphan case here is specifically for a crash between that delete and the column NULL, same as before this ADR.

## ADR 0036 rename aliases — CSV only, deliberately

`HeaderResolver::resolveAliases()` returns current-name → pre-rename-name for fields whose rename backfill is still draining (empty in steady state). `ExportJobProcessor` threads it into `ArtifactStreamFactory::from()` and on into `CsvArtifactStream`.

It is needed because CSV uses **one `list<string>` for two roles**: the header text and the payload projection key. During a rename window those diverge — the header must say the new name (an operator reads it) while rows behind the backfill cursor are still keyed by the old one. Without the alias, `$row->fields[$name] ?? null` yields `''` for every un-migrated row, so the artifact is a correct header over blank cells: no exception, no `row_skipped`, no `skip_count`, and unlike a read it is never retried. The header cell always shows the current name; only the lookup falls back.

`tests/Smoke/Chronicler/RenameExportWindowTest` pins all of this: that CSV cells stay populated for rows still on the pre-rename key, that JSON keeps emitting the old key verbatim, and that a settled rename leaves an export byte-identical to a never-renamed model's. The CSV case was validated by temporarily neutering `resolveAliases()` and confirming it fails with a blank cell — so it is not a fixture that could not have failed.

**`JsonArtifactStream` is deliberately not aliased.** Its documented contract is the verbatim payload per ADR 0013, so a JSON consumer sees the old key and can cope; a CSV consumer sees a header that lies. Do not "fix" the asymmetry — and note `resolve()`'s return shape was left alone for the same reason, since both stream types depend on it.
