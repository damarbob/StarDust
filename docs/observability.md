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

**Where this earns its keep is the asynchronous work.** A write that outruns the available index capacity logs `entry_written` and `exhaustion_fallback` under your id, and if the background backfill later gives up on that entry, the dead-letter row records your id alongside the id of the worker cycle that failed it — so a support ticket quoting one request id is answerable. Likewise an export you submit and a `job_complete` emitted by a separate daemon process share the id you passed.

Two details worth knowing when you read the output:

- **A background worker processes many requests in one batch.** Where that happens, the batch's own `correlation_id` describes the batch, and your id appears under a second key — `job_correlation_id` for bulk imports, `origin_correlation_id` on dead-letter rows.
- **Successful background backfills are not logged per entry**, only per batch. The absence of a per-entry record is normal; the queue depth is the signal to watch.
