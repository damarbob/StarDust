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

The Chronicler claims one job per tick — pending first (per-tenant round-robin so a single tenant cannot starve others), then abandoned jobs whose heartbeat lapsed beyond `chroniclerLeaseTimeoutSeconds`. On a re-claim it best-effort-deletes the prior partial artifact and resumes from `last_cursor`. Lease loss is self-detected at every chunk commit through a `WHERE worker_identity = self` predicate — a worker whose row was overwritten by a re-claimer emits `lease_lost`, deletes its partial, and bails without mutating the row (the re-claimer owns terminal state). Failure semantics: 3-deadlock budget per chunk before `chunk_skipped`, combined skip cap of 1 000 before `failed:excessive_skips`, fixed `[1, 4, 16]`-second DB-disconnect backoff before `failed:query_failure` (with `last_cursor` preserved for restart), `ENOSPC` mid-write yields `failed:disk_full`, and bytes-exceeding-5 GB emits `artifact_oversized` (a distinct event from `job_failed`) and marks `failed:artifact_size_exceeded`. Idle ticks GC TTL'd completed artifacts and orphaned failed-job partials; a pre-claim disk-pressure gate emits `low_disk` and skips new claims when free space falls below `chroniclerLowDiskThresholdPct` (in-flight jobs continue).
