# Write path

Phase 3 surface. Phase 5 added two collaborators that the Reconciler reuses.

## `EntryWriter`

`write(EntryPayload)` runs the canonical single-entry sequence in one transaction:

1. INSERT into `entry_data` (system of record per ADR 0013).
2. Per-page `INSERT … ON DUPLICATE KEY UPDATE` into `entry_slots_page_N` for every live-slot field (statuses `assigned | backfilling | ready` — writes during backfill MUST land in the new slot).
3. A `stardust_sync_queue` enqueue iff one or more *registered, filterable* fields lack a live slot (ADR 0007 exhaustion fallback).

`writeWithinTransaction()` is the no-own-transaction variant that the bulk path AND the Phase 5 `ImportJobWorkSource` call inside their own chunk transactions.

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

## `EntryPayload` convergent factories

`fromArray()` / `fromJson()` build a single entry from a camelCase `{tenantId, modelId, fields}` envelope; `listFromArray()` / `listFromJson()` take a JSON array of envelopes for the bulk paths. All four are pure envelope-shape validators returning an ordinary `EntryPayload`, so a factory-built payload flows through the identical write path (same `TenantId::assertValid()` boundary, same `PayloadSplitter` coercion).

Structural failures raise `MalformedEntryPayloadException`, carrying the offending `$key` (e.g. `tenantId` or `[3].modelId`). The `tenant_id >= 1` rule and per-field coercion deliberately stay on the write path, **not** in the factory.
