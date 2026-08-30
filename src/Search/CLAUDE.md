# Search driver / adapter

Phase 8 execution surface. The input language it consumes lives in `src/Filter/`.

## `EntrySearchInterface` (ADR 0022)

Seven methods:

- `list(SearchRequest): SearchResult`
- `get(int $tenantId, int $entryId): ?Entry`
- `supportedOperators(): list<string>`
- `supportsFilterOn(int $fieldId): bool`
- `supportsSortOn(int $fieldId): bool` — added by ADR 0041
- `supportsFuzzySearch(): bool`
- `consistencyModel(): string` — one of `ConsistencyModel::STRONG | EVENTUAL`

**`supportsSortOn()` is separate from `supportsFilterOn()` on purpose**, even though `MysqlNativeDriver` implements it by delegating: the two answers coincide only there. An external engine may index a field for matching without keeping it orderable, and ADR 0022 puts that judgement on the driver ("one method per coarse capability") rather than in the shared pipeline. Adding it was a **breaking change to a public interface**, taken deliberately while v0.3.0 is untagged and no third-party driver can exist yet.

`Config::$searchDriver` is the ADR 0026 construction-time injection seam; `StarDust::search()` lazily instantiates a `MysqlNativeDriver` when it is `null`.

## DTOs

`SearchRequest` carries `(tenantId, modelId, ?FilterNode $filter, ?selectFields, pageSize, ?cursor, correlationId, ?SortSpec $sort)`. **`withFilter()`, `withCorrelationId()` and `fromEntryQuery()` all rebuild the DTO field-by-field**, so anything appended to the constructor must be threaded through all three or it is silently dropped on the way to the driver. `pageSize` is validated at construction (`PageSizeOutOfRangeException`) — that validation used to live in the deleted `QueryValidator`.

`SearchResult` mirrors `EntryPage`'s shape; both DTOs interop via `toEntryPage()` / `fromEntryQuery()` factories, which is what lets the Phase 4 public signatures survive unchanged.

## `PreFlight/PreFlightPipeline`

Four single-method visitors in fixed order:

1. **`FieldRefResolver`** — resolves every leaf's `FieldRef` against the snapshot; raises `UnknownFieldException` with a `pre_flight_rejected` event.
2. **`CapabilityChecker`** — checks `driver.supportedOperators()` then `driver.supportsFilterOn(fieldId)`. Raises `QueryFilterValidationException(capability_unsupported)` with a **distinct `capability_unsupported` event**, or `FieldNotFilterableException` for non-filterable fields.
3. **`ValueTypeValidator`** — per-leaf declared-type and bounds enforcement: string max 4096 chars, int signed-64-bit, numeric finite, datetime RFC 3339 with an explicit UTC offset.
4. **`SortValidator`** (ADR 0041) — resolves the sort target, asks `driver.supportsSortOn()`, and checks the cursor was issued for this ordering. Raises `UnknownFieldException`, `FieldNotSortableException`, or `InvalidCursorException`, each with a `pre_flight_rejected` event carrying a new `reason` (`sort_field_unknown`, `sort_field_not_sortable`, `cursor_sort_mismatch`) — **`reason` values, not event names, so ADR 0020 is untouched**.

`SortValidator` hangs off a **separate `validateSort()` entry point** rather than a fourth call inside `validate()`, because a sort is not part of the filter tree and has to be checked when there is no filter at all.

The `capability_unsupported` event is deliberately separate from the generic `pre_flight_rejected` so operators can metric "consumer asked for a feature this driver doesn't service" on its own.

## `SearchService::execute()`

Top-level orchestrator, and the single path for `search()`, `read()`, and `get()` alike. It allocates a `correlation_id` if absent, snapshots the schema, runs pre-flight, dispatches to the driver, and emits one `search_request` event carrying `latency_ms`, `rows_returned`, `has_more`, `tree_node_count`, and `compile_strategy`.

**The pre-flight gate is `filter !== null || sort !== null || cursor !== null`**, widened from filter-only by ADR 0041. The old condition let a match-all sorted read skip pre-flight entirely — no field resolution, no capability check, no cursor/sort agreement check — which is exactly the shape a "newest first" listing takes.

## `Mysql/MysqlNativeDriver`

Wraps Phase 4's collaborators (`SchemaVersionCache`, `PaginatedProbe`, `BoundedFetch`, `ResultAssembler`).

`supportsFilterOn(fieldId)` runs a small `JOIN stardust_slot_assignments` lookup, returning `FieldDescriptor::isIndexedNow()` — so MySQL's `is_filterable` semantics stay on the driver, where ADR 0022 places them rather than in the shared pipeline. `supportsSortOn()` delegates to it: on MySQL both questions reduce to "the field has a live indexed slot".

`list()` encodes its next-page token with `CursorCodec::encodeFor($request->sort, …)`, so the token records the ordering it was issued under. A null sort still emits the v1 format.

## `Mysql/SqlFilterCompiler`

Adaptive. `chooseStrategy(?FilterNode)` returns `'joins'` for `null` or pure-AND trees (`containsDisjunction()` false), and `'exists'` otherwise.

- **JOIN strategy** reuses the Phase 4 INNER-JOIN-per-distinct-page shape **verbatim**, including the LIKE-escape rules and IN-list placeholder layout. This is what preserves the Phase 4 AC#4 composite-index range scans bit-for-bit; changing it silently regresses read performance and the `EXPLAIN` assertion is the only thing that will tell you.
- **EXISTS strategy** emits `EXISTS (SELECT 1 FROM <table> s WHERE s.tenant_id = entry_data.tenant_id AND s.entry_id = entry_data.id AND <pred>)` per leaf (or `NOT EXISTS`), composing the tree with native SQL `AND` / `OR` / `NOT (...)`.

The Architecture Blueprint §1.2 tenant-isolation invariant holds on **both** strategies, and on the two clauses ADR 0041 added — the sort join replays `tenant_id`, and so does the anchor subquery. Verify it on any new strategy.

### Sorting (ADR 0041)

Three additions, and each has a trap:

- **The sort field's page is joined whatever the strategy chose.** You cannot `ORDER BY` a column that exists only inside an `EXISTS` subquery, so the EXISTS path grows an outer join it otherwise has none of.
- **That join is `LEFT`, never `INNER`.** Filter joins are INNER because a filter demands a match; an INNER sort join would silently reduce the result to "entries that have a row on this page", turning a sort into a filter. When the filter already joined that page the compiler reuses its alias instead.
- **The anchor is a one-row derived table**, `CROSS JOIN (SELECT (SELECT col FROM tbl WHERE entry_id = ? AND tenant_id = ?) AS av) sort_anchor`. Written as `SELECT (SELECT …)` rather than `SELECT … FROM …` because the latter yields **zero** rows when the anchor has no row on that page, and a CROSS JOIN against zero rows annihilates the result set. Binding `tenant_id` rather than correlating to `entry_data.tenant_id` keeps it uncorrelated, which is why it plans as `SUBQUERY … const` and is evaluated once rather than per row — verified on 8.0.13.

`reorderBindings()` is gone. Bindings are now collected per clause and concatenated in SQL order (anchor → tenant/model → keyset → filter → limit); the old flat list plus a fixed four-element tail splice only worked while every query had the same outer shape.

## What Phase 8 did to `src/Read/`

`PaginatedProbe` was reduced to a thin caller delegating to `SqlFilterCompiler`. `QueryValidator` was deleted, its logic moving into `PreFlightPipeline` + `SearchRequest`'s constructor. `EntryReader` became a thin façade building a `SearchService` from a `(PDO, LoggerInterface)` pair — the constructor signature is unchanged, so Phase 4 tests did not need rewiring.

## `get()` and the ADR 0036 rename window

The point read returns the payload verbatim, so during a rename backfill it would hand back the pre-rename key for rows behind the cursor and the post-rename key for rows ahead of it — inconsistent between two entries of the same model, and inconsistent with `read()` on the very same entry. It now loads the snapshot and calls `SnapshotEntry::canonicalisePayloadKeys()`.

**This costs a schema-version probe `get()` did not previously pay**, and that was a deliberate trade rather than an oversight: `read()` pays it on every call, ADR 0015 designs the probe to be sub-millisecond, and the rewrite itself is gated behind a precomputed `hasRenamesInFlight()` bool so steady state is one boolean check. `get()`'s previous single-query shape was incidental, not a designed optimisation. If that ever needs revisiting, the alternative is to leave it verbatim and document the divergence — but it must stay a decision, not drift.

**Filters are not aliased and must not become so.** `FieldRefResolver` resolves leaves by current name only, so a filter on a renamed field's old name raises `UnknownFieldException` from the instant the rename commits. That is correct: a rejected filter loses nothing, whereas a rejected write loses data, which is why the write path converges instead. The slot is never touched by a rename, so a filter on the *new* name is correct immediately.

## ADR 0038: going dark is the driver's job, not the pipeline's

`MysqlNativeDriver::list()` returns an empty `SearchResult` and `get()` returns `null` when the snapshot carries `isModelDeleting()`. Both checks live in the driver rather than in `SearchService` or the pre-flight, for two structural reasons:

- **`SearchService` resolves the snapshot only when `filter !== null`.** A match-all read would sail straight past a check placed there. The driver resolves it unconditionally, so one site covers filtered and unfiltered alike.
- **`get()` bypasses `SearchService` entirely.** Putting the check in the driver is what keeps `get()` and `list()` agreeing, which is the invariant `DeleteWindowTest` already pins for fields.

In `get()` the check must come **before** `canonicalisePayloadKeys()`, not be folded into it: a model deletion marks every field, so the canonicaliser would strip every key and hand back a real `Entry` with a real id and `fields: []` — a positive existence claim, worse than the leak it was meant to prevent.

**A third-party ADR 0022 driver does not inherit any of this.** That is the same jurisdiction split the interface already draws for `is_filterable` via `supportsFilterOn()`, so it is consistent rather than an oversight — but a custom driver must implement the darkening itself or it will serve entries from a model the rest of the engine reports as gone.

Filters are the deliberate exception, and the asymmetry is correct: severance marks every field, so `FieldRefResolver` raises `UnknownFieldException` before the driver is reached. That is the same answer an unknown model id gives today, so the model still cannot be distinguished from one that never existed.
