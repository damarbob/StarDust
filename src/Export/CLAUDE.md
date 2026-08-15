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

## The `{model_id, filter}` envelope

The submitter wraps the consumer's QueryFilter inside a `{model_id, filter}` envelope before storing. This preserves the schema_reference §5.2 intent ("`filter` holds the consumer QueryFilter") while letting the Chronicler hydrate `model_id` on claim without an extra column.

`ExportJob` exposes `modelId` as a typed first-class field; `.filter` returns the original consumer payload unmodified, so a QueryFilter validator never has to peel out the engine's stamping.

## Reads and DTOs

`ExportJobSubmitter::getJob(int $tenantId, int $jobId)` is the tenant-isolated status read — returns `null` for not-found OR cross-tenant, mirroring `EntryReader::get()`.

`ExportJobRequest` validates `format` at construction (`csv` | `json`). `ExportJob` is the read-side projection consumers receive from `StarDust::getExportJob()`.

**No idempotency key** — the per-tenant cap is the duplicate-submission guard. Adding one later is non-breaking via the append-only DTO.
