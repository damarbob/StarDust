# Async exports

Submitting and polling an export, the per-tenant active-job cap, and the Chronicler's claim, resume and failure semantics.

```php
use StarDust\Export\ExportJobRequest;

// Submit an async export. The call enforces a per-tenant active-job
// cap (default ≤ 3 pending+processing) inside one transaction; a 4th
// concurrent submission throws ExportJobActiveCapExceededException.
// Format is 'csv' or 'json'. An export always covers every
// (non-deleted) entry in the model: predicate filtering is not
// implemented, so a non-empty filter is rejected outright with
// ExportFilterNotSupportedException rather than silently ignored.
// The argument stays on the DTO for a future implementation.
$jobId = $engine->submitExport(new ExportJobRequest(
    tenantId: 42,
    modelId:  $modelId,
    format:   'csv',
    filter:   [],
));
// $jobId->jobId — pass back to getExportJob() to poll status

// Poll status. Returns null when the job does not exist for this
// tenant (tenant isolation is enforced by the WHERE clause).
$job = $engine->getExportJob(tenantId: 42, jobId: $jobId->jobId);
if ($job?->status === 'completed') {
    // $job->artifactPath holds the absolute path to the CSV/JSON
    // file under Config::$artifactDir. Serve it to the caller,
    // then trust the Chronicler's idle-cycle GC to clean it up
    // after the configured TTL (24 h default).
    serveDownload($job->artifactPath);
}
```

Run one or more Chronicler workers (multi-worker safe — no PID guard):

```bash
vendor/bin/stardust chronicler   # scale by spawning more processes
```

The Chronicler claims one job per tick — pending first (per-tenant round-robin so a single tenant cannot starve others), then abandoned jobs whose heartbeat lapsed beyond `chroniclerLeaseTimeoutSeconds`. On a re-claim it attempts to resume the prior worker's partial artifact in place: it re-opens the file, verifies it genuinely holds the bytes the row's `last_cursor` claims, and only then continues writing from where the dead worker left off. If that verification fails for any reason — the file is missing, shorter than claimed, its header no longer matches the current field set, or another process still has it open — the Chronicler starts a brand-new artifact from scratch rather than risk skipping or corrupting rows; either way the resulting artifact is always complete. Lease loss is self-detected at every chunk commit through a `WHERE worker_identity = self` predicate — a worker whose row was overwritten by a re-claimer emits `lease_lost` and releases its file without deleting it, since the re-claimer may already be resuming from those exact bytes (the re-claimer owns terminal state). Failure semantics: 3-deadlock budget per chunk before `chunk_skipped`, combined skip cap of 1 000 before `failed:excessive_skips`, fixed `[1, 4, 16]`-second DB-disconnect backoff before `failed:query_failure` (with `last_cursor` preserved for restart), `ENOSPC` mid-write yields `failed:disk_full`, and bytes-exceeding-5 GB emits `artifact_oversized` (a distinct event from `job_failed`) and marks `failed:artifact_size_exceeded`. A job that fails outright has its partial artifact deleted immediately, rather than waiting for GC. Idle ticks GC TTL'd completed artifacts, orphaned failed-job partials, and any leaked disk-probe file the pressure gate below left behind after a mid-probe crash; a pre-claim disk gate emits `low_disk` and skips new claims either when free space falls below `chroniclerLowDiskThresholdPct` or when a small write probe into the artifact directory fails — which is what catches a per-account quota, since those are invisible to a free-space reading (in-flight jobs continue).

A `SIGTERM` to a running `bin/stardust chronicler` process no longer waits for the current job to finish before exiting: the worker yields at the next chunk boundary, returning the job to `pending` with its resume anchor intact, and the next worker to claim it (this one restarting, or another already running) continues from exactly that byte offset rather than the whole export starting over. The same mechanism lets `bin/stardust tick --exports` (see [Deployment requirements](deployment.md)) bound export work the way it already bounds everything else it composes: a large export cooperatively yields back to `pending` once that run's own budget deadline is reached, and resumes on a later invocation. This is off by default — pass `--exports` explicitly. The disk gate above now does see a per-account quota, but two reasons to opt in deliberately remain: the gate proves only that its own probe size can be written, not that a large export will fit, and garbage collection runs only on idle ticks, so a permanently busy export schedule never reclaims timed-out artifacts. See the [deployment doc](deployment.md) before enabling it on constrained hosting.
