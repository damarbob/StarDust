# Export submission API

Phase 7 synchronous submission path (ADR 0010). The asynchronous half is the Chronicler — see `src/Chronicler/CLAUDE.md`.

## `ExportJobSubmitter::submit(ExportJobRequest)`

Enforces the per-tenant active-job cap **atomically**. One transaction holds:

```sql
SELECT id FROM stardust_export_jobs
WHERE tenant_id = ? AND status IN ('pending','processing')
FOR UPDATE
```

The `(tenant_id, status)` composite plus InnoDB gap locks close the TOCTOU window between two concurrent submitters at the cap boundary. Throws `ExportJobActiveCapExceededException` when `rowCount() >= chroniclerPerTenantActiveCap`; otherwise INSERTs a `pending` row with `worker_identity = NULL` and `last_cursor = NULL`, and emits `export_accepted` (source `export_api`) after commit.

`tests/Smoke/Chronicler/ExportJobSubmitterCapConcurrencyTest` proves the lock actually holds, by pinning the gap-lock range from a sibling session.

## A non-empty filter is refused, not stored

`submit()` throws `ExportFilterNotSupportedException` when `$request->filter !== []`. Export predicate filtering is not implemented — `EntryDataPager` selects on `tenant_id / model_id / deleted_at` only and nothing reads the stored filter back — so accepting one turned a request for a subset into a **full extract of the model**, silently, into an artifact the consumer keeps and which is never retried.

**The guard's position is load-bearing.** It sits after `TenantId::assertValid()` and before the envelope build, which puts it before any SQL, before `beginTransaction()`, and before the `FOR UPDATE` cap probe. So a refused request inserts nothing and burns none of the tenant's active-job slots (`ExportJobSubmitterTest::testRejectedFilterInsertsNoRowAndConsumesNoCapSlot` pins both). Keeping it outside the `try` block also means it never needs adding to the `ExportJobActiveCapExceededException` pass-through clause in the catch chain.

It lives here rather than in `ExportJobRequest`'s constructor because that validates `format` with a bare `RuntimeException`, whereas this is a typed domain failure belonging beside the cap exception. `StarDust::submitExport()` delegates, so the facade inherits it with no second check.

**No ADR governs this.** ADR 0010 and `blueprints/async_exports.md` were both relocated to StarGate in May 2026 and neither mentions filters, predicates or QueryFilter. Implementing filtering later is undesigned work, not a resumption.

## The submission id is persisted, not just emitted

`ExportJobRequest::$correlationId` lets a consumer pass their own request id; null mints one. It is written to `stardust_export_jobs.correlation_id` inside the same transaction as the INSERT, so the Chronicler — a different process, possibly hours later — emits `job_claimed` / `job_complete` / `job_failed` under it rather than under an id of its own.

This is the closest analogue in the engine to the four registry lifecycles: a genuine per-job event pair on both sides of a process boundary. It is also the **only** one of the three async handoffs where the drain side can take the submission id *directly*; the import path has no per-job event and needs a companion field, and the sync queue cannot join at all on the success path. See `src/Reconciler/CLAUDE.md` for both.

## The `{model_id, filter}` envelope

The submitter wraps the consumer's QueryFilter inside a `{model_id, filter}` envelope before storing. This preserves the schema_reference §5.2 intent ("`filter` holds the consumer QueryFilter") while letting the Chronicler hydrate `model_id` on claim without an extra column.

`ExportJob` exposes `modelId` as a typed first-class field; `.filter` returns the stored consumer payload unmodified, so a future QueryFilter validator never has to peel out the engine's stamping.

The envelope shape is retained **even though `filter` is now always `[]`** — `ExportJobClaimer::extractModelId()` reads `model_id` out of it, and `Phase7TestCase::seedExportJob()` builds the same shape. Do not flatten it to a bare `model_id`.

## Reads and DTOs

`ExportJobSubmitter::getJob(int $tenantId, int $jobId)` is the tenant-isolated status read — returns `null` for not-found OR cross-tenant, mirroring `EntryReader::get()`.

`ExportJobRequest` validates `format` at construction (`csv` | `json`). `ExportJob` is the read-side projection consumers receive from `StarDust::getExportJob()`.

**No idempotency key** — the per-tenant cap is the duplicate-submission guard. Adding one later is non-breaking via the append-only DTO.
