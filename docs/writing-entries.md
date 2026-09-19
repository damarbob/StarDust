# Writing entries

Single writes, chunked synchronous bulk ingest, async submission and job polling, and the update / soft-delete pair.

```php
use StarDust\Write\BulkIngestOptions;
use StarDust\Write\EntryPayload;

// Single-entry write. Atomic INSERT into entry_data + per-page
// INSERT … ON DUPLICATE KEY UPDATE into entry_slots_page_N for
// each field with a live slot; falls back to stardust_sync_queue
// (in the same transaction) if any *filterable* field lacks a
// live slot (exhaustion fallback — the call still succeeds).
// Non-filterable fields live in the JSON payload only, so they
// never occupy a slot and never queue.
$result = $engine->write(new EntryPayload(
    tenantId: 42,
    modelId:  $modelId,
    fields:   ['name' => 'Acme', 'employees' => 120],
));
// $result->entryId, $result->enqueuedForBackfill, $result->slotsWritten

// Synchronous chunked bulk ingest (≤ 1 000 entities). Each chunk
// (default 500) commits in its own transaction so InnoDB lock
// duration stays bounded. Returns a per-chunk manifest.
$bulk = $engine->bulkWrite(
    payloads: $listOfEntryPayloads,
    options:  new BulkIngestOptions(chunkSize: 500, interChunkDelayMicros: 0),
);

// Async submission (> 1 000 entities, or smaller batches you want
// processed off-thread). Writes the payload to Config::$artifactDir,
// inserts a stardust_import_jobs row, returns the Import Job ID.
// A running Reconciler (bin/stardust reconciler) drains the job.
$jobId = $engine->submitBulkWrite(
    tenantId:        42,
    payloads:        $largeBatch,
    idempotencyKey:  'monthly-import-2026-05',
);

// Poll status. Returns null when the job does not exist for this
// tenant (tenant isolation is enforced by the WHERE clause).
$job = $engine->getImportJob(tenantId: 42, jobId: $jobId->jobId);

// entriesWritten against entryCount is the progress fraction. Both
// entriesWritten and chunks are null until the first chunk commits.
if ($job?->status === 'completed') {
    echo "{$job->entriesWritten} of {$job->entryCount} entries written";
}
if ($job?->status === 'failed') {
    // A failed job stops where it broke: entries already written stay
    // written, and later ones are never attempted. entriesWritten is
    // the durable boundary between the two, so a retry resubmits from
    // there. It is null when the job failed before writing anything.
    echo "failed ({$job->failedReason}); resume from " . ($job->entriesWritten ?? 0);
}

// chunkManifest enumerates the chunks the job has processed, in order:
// each record carries its size, its outcome, and the range of entry ids
// it wrote. This is the same per-chunk detail a synchronous bulkWrite()
// returns, so crossing the size threshold does not cost you visibility.
// A failed job ends with one 'failed' record naming the chunk that
// broke; its id range is null, because that chunk was rolled back.
foreach ($job?->chunkManifest ?? [] as $chunk) {
    echo "chunk {$chunk->index}: {$chunk->outcome}, {$chunk->size} entries";
    if ($chunk->entryIdFirst !== null) {
        echo " (ids {$chunk->entryIdFirst}-{$chunk->entryIdLast})";
    }
}

// Build payloads from JSON / arrays instead of the typed constructor —
// handy when entries arrive off a wire (CMS, HTTP, queue). The envelope
// is {tenantId, modelId, fields} (camelCase). These are *convergent*
// factories: they validate envelope shape and return an ordinary
// EntryPayload, so the value flows through the identical write path.
$engine->write(EntryPayload::fromArray([
    'tenantId' => 42, 'modelId' => $modelId,
    'fields'   => ['name' => 'Acme', 'employees' => 120],
]));
$engine->write(EntryPayload::fromJson($rawJsonObjectBody));

// Bulk: a JSON array (or PHP list) of envelopes:
$engine->bulkWrite(EntryPayload::listFromJson($rawJsonArrayBody));
$engine->submitBulkWrite(tenantId: 42,
    payloads: EntryPayload::listFromArray($decodedEnvelopes));
```

Envelope-shape errors raise `MalformedEntryPayloadException` (carrying the
offending `$key`, e.g. `'tenantId'` or `'[3].modelId'`). The `tenant_id >= 1`
rule and per-field type coercion stay on the write path — identical to the typed
constructor — so a factory-built payload behaves exactly like `new EntryPayload(...)`.
Pair this with [Searching with the JSON wire format](query-filter.md)
for an end-to-end JSON loop: JSON in, JSON-filtered out.

`tenant_id` is validated at every entry point (must be `>= 1`) before any SQL executes. All write-path operations emit structured NDJSON log events — `entry_written`, `entry_updated`, `entry_deleted`, `exhaustion_fallback`, `bulk_chunk_committed`, `bulk_chunk_rolled_back`, `bulk_accepted`, `payload_too_large`.

A `datetime` field's value must be a `DateTimeInterface`, a naive `Y-m-d H:i:s` string (assumed UTC), or an RFC 3339 string with an explicit UTC offset (`Z` or `±HH:MM`, converted to UTC on write) — the same offset requirement [Searching with the JSON wire format](query-filter.md) already documents for filter bounds. Any other string shape, including a locale-formatted date such as `05/01/2026`, is rejected with `UncoercibleSlotValueException` rather than guessed at: a slash-separated date is read as month-first regardless of what the caller intended, so a day-first value only *looks* like it worked — it lands on the wrong date, silently, for any day ≤ 12.

## Updating and deleting entries

```php
// Update is a full replace, not a patch: $fields becomes the entry's
// complete payload. A field you omit is removed from the JSON *and*
// its indexed slot column is cleared, so a filter can never match a
// value the entry no longer carries.
$engine->updateEntry(tenantId: 42, entryId: $entryId, fields: [
    'name'      => 'Acme Holdings',
    'employees' => 141,
]);

// Soft delete. One timestamp, and the entry is gone from read(),
// get(), search(), and exports alike.
$deleted = $engine->deleteEntry(tenantId: 42, entryId: $entryId);
// true on the transition; false if it was already deleted or not yours
```

`model_id` is immutable — an update never moves an entry between models. Coercion, tenant isolation, and the capacity fallback all behave exactly as they do on `write()`: an update that introduces a filterable field with no free slot still succeeds, storing the value in the JSON payload and queueing it for backfill.

`updateEntry()` throws `EntryNotFoundException` when the entry does not exist, belongs to another tenant, or is already deleted — silently discarding an update would lose data the caller believed it had written. `deleteEntry()` takes the opposite stance and returns `false` in those same cases, because a repeated delete has already achieved what the caller asked for. There is no hard delete and no restore.
