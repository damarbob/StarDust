# Tracing a request through the logs

Every event StarDust emits carries a `correlation_id`. By default the engine mints one per operation, but **you can supply your own** — pass your HTTP request id and it flows through every event that operation produces, including the ones a background daemon emits minutes later in a different process.

```php
$payload = new EntryPayload($tenantId, $modelId, $fields, correlationId: $requestId);
$engine->write($payload);

$query = new EntryQuery($tenantId, $modelId, correlationId: $requestId);
$engine->read($query);

$engine->submitExport(new ExportJobRequest(
    tenantId: $tenantId,
    modelId: $modelId,
    format: ExportJobRequest::FORMAT_CSV,
    correlationId: $requestId,
));

$engine->bulkWrite($payloads, new BulkIngestOptions(correlationId: $requestId));
$engine->submitBulkWrite($tenantId, $payloads, $idempotencyKey, $requestId);
$engine->updateEntry($tenantId, $entryId, $fields, $requestId);
$engine->deleteEntry($tenantId, $entryId, $requestId);
```

Every parameter is optional and appended, so existing code keeps working and simply gets a generated id.

**Where this earns its keep is the asynchronous work.** A write that outruns the available index capacity logs `entry_written` and `exhaustion_fallback` under your id, and if the background backfill later gives up on that entry, the dead-letter row records your id alongside the id of the worker cycle that failed it — so a support ticket quoting one request id is answerable. Likewise an export you submit and a `job_complete` emitted by a separate daemon process share the id you passed — and if a large export cooperatively yields partway through (see [Async exports](exports.md)), every `job_yielded` along the way, and whichever worker eventually finishes it, still carry the same id.

Two details worth knowing when you read the output:

- **A background worker processes many requests in one batch.** Where that happens, the batch's own `correlation_id` describes the batch, and your id appears under a second key — `job_correlation_id` for bulk imports, `origin_correlation_id` on dead-letter rows.
- **Successful background backfills are not logged per entry**, only per batch. The absence of a per-entry record is normal; the queue depth is the signal to watch.

**`bin/stardust tick` (see [Deployment requirements](deployment.md)) is its own trace boundary, not a joining one.** Each run mints one `correlation_id` and emits `tick_started` and `tick_complete` under it (plus `tick_skipped` when it declines to run at all because another process already holds a pid file), but the Watcher, Liberator, Reconciler — and, with `--exports`, the Chronicler — it composes each mint their own per-job or per-tick ids as usual (the Chronicler's `job_claimed` / `job_yielded` / `job_complete` still join under the exporting *job's* id, exactly as they do under a persistent `bin/stardust chronicler`) — a run's own log does not join end-to-end under a single id the way a request's does. If you need to correlate everything one `tick` invocation did, group by timestamp proximity in that run's own log output rather than by `correlation_id`.
