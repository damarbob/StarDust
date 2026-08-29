# Write path

Phase 3 surface. Phase 5 added two collaborators that the Reconciler reuses.

## `EntryWriter`

`write(EntryPayload)` runs the canonical single-entry sequence in one transaction:

1. INSERT into `entry_data` (system of record per ADR 0013).
2. Per-page `INSERT … ON DUPLICATE KEY UPDATE` into `entry_slots_page_N` for every live-slot field (statuses `assigned | backfilling | ready` — writes during backfill MUST land in the new slot).
3. A `stardust_sync_queue` enqueue iff one or more *registered, filterable* fields lack a live slot (ADR 0007 exhaustion fallback).

`writeWithinTransaction()` is the no-own-transaction variant that the bulk path AND the Phase 5 `ImportJobWorkSource` call inside their own chunk transactions.

### `update()` — full replace, and why it needs its own slot handling

`update(int $tenantId, int $entryId, array $fields)` is PUT, not PATCH: `$fields` becomes the entry's complete payload. It locks the row with `SELECT … FOR UPDATE` scoped to `tenant_id` + `deleted_at IS NULL`, resolves `model_id` from that row (**an update can never move an entry between models** — the caller does not supply it), then reuses steps 2 and 3 above verbatim, including the ADR 0007 enqueue.

The one thing it cannot reuse is the slot plan. `PayloadSplitter` only plans writes for fields *present* in the payload, so a field the caller omits would keep its old indexed value while vanishing from `entry_data.fields` — the JSON and the slot column would disagree, and a filter would still match on a value the entry no longer has. `withClearedSlots()` closes that: every live slot of the model whose field name is absent from the new payload is written as NULL in the same UPSERT. It uses `array_key_exists`, not `isset`, because an explicit `['x' => null]` is the caller *setting* the field and is already planned by the splitter; only genuinely absent keys are cleared.

There is deliberately **no** `updateWithinTransaction()` — no bulk-update path exists yet, and this repo does not grow surface area ahead of a caller.

## `EntryDeleter`

`delete(int $tenantId, int $entryId): bool` stamps `entry_data.deleted_at` and nothing else. That single column is the whole mechanism: `BoundedFetch`, `MysqlNativeDriver`, and `EntryDataPager` each independently filter `deleted_at IS NULL`, so the row leaves reads, search, point-reads, and exports at once.

- **Slot columns are intentionally retained.** Nothing can reach them without joining through a live `entry_data` row, so clearing them would be a write per occupied page for no observable difference. `EntryDeleteTest::testSlotValuesAreRetainedAfterDelete` pins this so a future change that starts nulling them is a decision rather than a drift.
- **Idempotent by construction.** `deleted_at IS NULL` in the WHERE clause means a second delete matches zero rows instead of overwriting the original timestamp — no pre-SELECT needed. Returns `false`, and emits no event, when nothing transitioned.
- **The asymmetry with `update()` is deliberate.** Delete returns `false` for a missing/foreign/already-deleted row; update throws `EntryNotFoundException`. Silently discarding an update loses data the caller believed it had written; a repeated delete has already achieved what the caller asked for.

There is no hard delete and no restore — `deleted_at` is the only lifecycle transition the schema models, and purging would additionally have to reclaim slot columns.

### ADR 0036: inbound keys are canonicalised before anything else

Both `writeWithinTransaction()` and `update()` load `LiveSlotMap` **before** `json_encode`, then run `$map->canonicalise($fields)`. That ordering is load-bearing, not stylistic — the canonicalised payload is what gets persisted, not just what gets planned.

A rename flips `stardust_fields.name` immediately, so a client that has not redeployed keeps sending the old name. Without canonicalisation that key is unknown to the map, `PayloadSplitter` drops it from the slot plan (see below), and the value lands in `entry_data.fields` under the stale key with **no slot write and no exhaustion enqueue** — so a filter on the new name matches a slot value the entry no longer has. Worse, for a row the rename backfill cursor has already passed, that key is never migrated and the value disappears entirely when `previous_name` is cleared. The read-side rename window is transient and self-healing; this one is permanent.

On `update()` there is a second reason: `withClearedSlots()` compares the map's (current) names against the payload's keys, so an un-canonicalised payload would make the renamed field look *absent* and NULL its slot on every update during the window.

`hasAliases()` gates the whole thing, so steady state is one `false` check.

### ADR 0037: a deleted field's key is stripped, not merely unmapped

`canonicalise()` does a second job: it removes any key naming a field whose deletion is in flight (`stardust_fields.deleted_at` non-null).

**Leaving the field out of the map is not sufficient, and assuming otherwise is the natural mistake.** An unregistered key is an *unknown* key, and per the two silently-dropped categories below, an unknown key's value **is preserved in `entry_data.fields`**. So a client still sending the deleted name would keep writing it back into every new entry, and into existing ones on update — including rows the deletion purge's cursor has already passed, which its single forward pass will never revisit. The field's values would outlive the field indefinitely.

`hasPendingDeletions()` gates it alongside `hasAliases()`, so the steady state is still two `false` checks. Pinned by `DeleteWindowTest::testWritingWithTheDeletedKeyDropsItSilently` and its `updateEntry()` twin.

### The two silently-dropped categories

Two categories never reach the slot plan; their values are preserved in `entry_data.fields` per ADR 0013:

- Unknown payload keys not in `stardust_fields`.
- **Non-filterable fields — JSON-only per ADR 0034.** Having no slot is the steady state here rather than a degradation, so they never enqueue and can never produce an unsatisfiable `capacity_wait`.

`LiveSlotMap` carries `is_filterable` per registered field name (`isFilterable(string): bool`, total — an unknown name returns `false`), and that single predicate is what `PayloadSplitter` branches on. **It is checked *before* the live-slot lookup**, which both scopes the enqueue and leaves a grandfathered pre-0034 slot unwritten. Inverting those two checks would re-create the infinite `capacity_wait` loop.

A payload touching only non-filterable fields plans zero pages and writes no `entry_slots_page_N` row at all — harmless, since the read path LEFT-JOINs and the Liberator sweeps by `entry_id`.

## Collaborators

- `SlotRowUpserter` — Phase 5 extraction. Pure helper owning the actual UPSERT, used by both `EntryWriter` and `BackfillExecutor`.
- `BackfillExecutor::backfill(int $entryId): BackfillResult` — the Phase 5 sync-queue drain helper. Loads `entry_data`, runs `LiveSlotMap` + `PayloadSplitter`, calls `SlotRowUpserter` per page. It does NOT touch `entry_data` or `stardust_sync_queue` (the Reconciler owns the queue), and it does **not** reserve: `BackfillResult::$stillUnmapped` reports the filterable fields waiting on a slot, and the Reconciler's `UnmappedFieldReserver` claims them after rolling the chunk back (ADR 0007 — see `src/Reconciler/CLAUDE.md`). Throws `EntryDataMissingException` when the queued `entry_id` no longer has a backing row — the Reconciler routes that to DLQ.
- `LiveSlotMap::loadFor(PDO, $modelId)` — reads the registry once per write.
- `PayloadSplitter` — pure (JSON value → slot column with type coercion) and fail-fast. A string value whose `mb_strlen` exceeds `FilterLimits::DEFAULT_MAX_STRING_LENGTH` (4096) is rejected with `UncoercibleSlotValueException` before any SQL (ADR 0030 — closes the residual raw-1406 risk for the string family, and keeps the write bound symmetric with the filter bound the slot is queried by).
- `TenantId::assertValid()` — runs at every public entry point before any SQL.

## Bulk paths

- `BulkIngestor::ingest()` enforces the 1 000-entity sync threshold (throws `PayloadTooLargeException` above it), chunks into `BulkIngestOptions::$chunkSize` transactions (default 500), applies the inter-chunk delay only *between* chunks (never before the first or after the last), and returns a per-chunk `BulkIngestResult` manifest.
- `BulkIngestSubmitter::submit()` is the async escape hatch (> 1 000 entities): writes the payload JSON artifact under `Config::$artifactDir` as a single-document JSON file per ADR 0028 (full batch buffered in PHP heap; **not** NDJSON), inserts a `stardust_import_jobs` row, returns an `ImportJobId`. Idempotency is enforced at the DB level by `ux_import_jobs_tenant_idempotency`.

## Reads and DTOs

`BulkIngestSubmitter::getJob(int $tenantId, int $jobId): ?ImportJob` is the tenant-isolated status read that resolves an `ImportJobId` — the polling half of the ADR 0011 contract (§26 requires the job record to carry status *and* manifest; §45 names the polling contract as part of the versioned surface). Returns `null` for not-found OR cross-tenant, mirroring `EntryReader::get()` and `ExportJobSubmitter::getJob()`. Surfaced as `StarDust::getImportJob()`. It emits no event, so it needs no ADR 0020 entry.

**It lives on the submitter rather than in a separate reader**, unlike `Schema\SchemaReader` — that split exists because `SchemaBuilder` is an acknowledged stopgap the read side had to survive, and `BulkIngestSubmitter` is a shipped ADR 0011 entry point with no replacement scheduled. The `artifactDir` constructor argument is not a reason to split: the constructor touches no filesystem (`mkdir` lives in `submit()`), and `StarDust::bulkSubmitter()` already supplies it. Extracting an `ImportJobReader` later never touches the public signature.

**The manifest is hoisted into typed `?int $chunks` / `?int $entriesWritten` / `list<ImportChunkRecord> $chunkManifest`; the raw array is not exposed.** `ExportJob`'s rule is *engine-generated envelopes get typed fields, consumer-supplied payloads pass through verbatim* — that is why `model_id` is hoisted but `.filter` is not. The import manifest is written only by `ImportJobWorkSource`, in a closed shape a consumer never supplies, so all of it falls on the typed side.

**`null` is not `0`, and the distinction is load-bearing.** The manifest is absent until the first chunk commits, and a `malformed_json` failure trips *before* any chunk runs. So `null` means "nothing was committed" and `0` would mean "ran and wrote nothing". This is why `getJob()` has its own private `decodeManifest()` rather than sharing `ImportJobWorkSource`'s, whose `null → 0` collapse is correct there (it needs an arithmetic resume offset) and wrong here. A reviewer will read the two as duplication; they are not.

**On a `failed` job, `entriesWritten` is the replay boundary.** The manifest is checkpointed inside every chunk transaction (`ImportJobWorkSource`) and the failure path never moves the counters, so the last committed count survives the failure. That is the single most useful thing this DTO reports and what `GetImportJobTest` pins hardest. Since ADR 0040 the failure path *does* append a terminal `failed` record naming which chunk broke — but it appends only, and `chunks` / `entries_written` are written back exactly as read.

Three shapes differ from `ExportJob` in ways that break an intuition carried over from it, all recorded on the DTO docblock: `artifact_path` is **`NOT NULL`** here (so it is not a completion signal, and it is stored verbatim — absolute from `submit()`, a bare filename from the Phase 5 fixtures — which is why the DTO must not try to resolve it); `heartbeat_at` is stamped on the **terminal** transition too, so it is non-null on every finished job; and `stardust_import_jobs` has no `model_id` and no `filter` column, so the DTO cannot mirror `ExportJob` field-for-field.

**`chunkManifest` is the per-chunk enumeration ADR 0011 §26 requires** — the async counterpart of the `BulkChunkResult` list a synchronous `bulkWrite()` returns, and the thing that stops a consumer losing per-chunk visibility merely by crossing §25's 1 000-entity threshold. Shaped by ADR 0040 (`Proposed`); the records themselves are written by `ImportJobWorkSource`, so the mechanics live in `src/Reconciler/CLAUDE.md`.

Three properties of it are easy to "fix" wrongly:

- **`outcome` is `committed | failed`, never `rolled_back`.** `ImportChunkRecord` is deliberately not a reuse of `BulkChunkResult` — the sync path skips a failed chunk and continues, so all three outcomes occur there; here the first failure is terminal for the job.
- **It is a *range* (`entryIdFirst` / `entryIdLast`), not a list**, because the manifest is re-encoded into the job row inside every chunk transaction. Measured at ~95 bytes per record; a full id list would be ~4 KB per chunk and would make the manifest the dominant write in a transaction ADR 0011 exists to keep short.
- **It can be empty on a job that plainly ran.** A job that resumed across the ADR 0040 upgrade carries records only for the chunks its post-upgrade worker committed; the counters are unaffected, because they are what the resume actually depends on.

## `EntryPayload` convergent factories

`fromArray()` / `fromJson()` build a single entry from a camelCase `{tenantId, modelId, fields}` envelope; `listFromArray()` / `listFromJson()` take a JSON array of envelopes for the bulk paths. All four are pure envelope-shape validators returning an ordinary `EntryPayload`, so a factory-built payload flows through the identical write path (same `TenantId::assertValid()` boundary, same `PayloadSplitter` coercion).

Structural failures raise `MalformedEntryPayloadException`, carrying the offending `$key` (e.g. `tenantId` or `[3].modelId`). The `tenant_id >= 1` rule and per-field coercion deliberately stay on the write path, **not** in the factory.
