# Read path

Phase 4 surface. **Phase 8 hollowed this package out**: `EntryReader` is now a thin façade that builds a `SearchService` from a `(PDO, LoggerInterface)` pair and routes `read()` / `get()` through it — the constructor signature is unchanged, so Phase 4 tests did not need rewiring. `QueryValidator` was deleted outright (its logic moved into `src/Search/PreFlight/` + `SearchRequest`'s constructor), and `PaginatedProbe` was reduced to a thin caller that delegates to `SqlFilterCompiler` and runs the prepared statement. See `src/Search/CLAUDE.md` before changing anything here.

## The two-query bounded sequence (ADR 0005)

`EntryReader::read(EntryQuery)` still describes the pipeline, even though the orchestration now lives in `SearchService`:

1. `SchemaVersionCache` resolves the per-model `SnapshotEntry`. Cache is keyed on `stardust_schema_version.version`; refresh emits `api: cache_miss` per ADR 0015's coordination contract, with a 60 s bounded-staleness TTL fallback on probe failure.
2. Phase 8's `PreFlightPipeline` rejects unknown / non-filterable / non-indexed (`backfilling | tombstoned | unmapped`) filter targets pre-flight per **ADR 0004** with an `api: pre_flight_rejected` event.
3. `PaginatedProbe` SELECTs only `entry_data.id` with `id > :cursor LIMIT pageSize + 1`. The `+1` is the sole next-page signal — no `COUNT(*)`, no `OFFSET`, per **ADR 0006**.
4. `BoundedFetch` materialises only the probed ids plus indexed slot columns via LEFT JOIN.
5. `ResultAssembler` sources each field from the slot column only when the field is filterable AND status is `assigned`/`ready` (`FieldDescriptor::isIndexedNow()` — **both** conditions, not status alone), otherwise from the decoded `entry_data.fields` payload.

That last point is the JSON-payload fallback: the slot column is never consulted for non-filterable, `backfilling`, `tombstoned`, or unmapped fields, which is what satisfies the Phase 4 exit criterion. Per ADR 0034, non-filterable fields are JSON-only and should never be assigned slots at all.

Tenant isolation is enforced at every `WHERE` and `JOIN` per Architecture Blueprint §1.2.

## Point read

`EntryReader::get(int $tenantId, int $entryId)` — no slot joins; the JSON payload is the system of record per ADR 0013.

## Cursors

`CursorCodec` encodes the opaque next-page token as `base64url("v1:" . entryId)`. **Consumers MUST NOT inspect it.**

## `EntryQuery`

The typed PHP DTO accepted at the boundary. Since Phase 8 its `?FilterNode $filter` takes the full AND/OR/NOT AST. `EntryQuery::fromFlatFilters()` is the migration factory for Phase 4 callers that used to pass a flat, implicitly-ANDed leaf list: 0 leaves → `null`, 1 → unwrapped, 2+ → wrapped in an `AndNode`.
