# Search driver / adapter

Phase 8 execution surface. The input language it consumes lives in `src/Filter/`.

## `EntrySearchInterface` (ADR 0022)

Six methods:

- `list(SearchRequest): SearchResult`
- `get(int $tenantId, int $entryId): ?Entry`
- `supportedOperators(): list<string>`
- `supportsFilterOn(int $fieldId): bool`
- `supportsFuzzySearch(): bool`
- `consistencyModel(): string` — one of `ConsistencyModel::STRONG | EVENTUAL`

`Config::$searchDriver` is the ADR 0026 construction-time injection seam; `StarDust::search()` lazily instantiates a `MysqlNativeDriver` when it is `null`.

## DTOs

`SearchRequest` carries `(tenantId, modelId, ?FilterNode $filter, ?selectFields, pageSize, ?cursor, correlationId)`. `pageSize` is validated at construction (`PageSizeOutOfRangeException`) — that validation used to live in the deleted `QueryValidator`.

`SearchResult` mirrors `EntryPage`'s shape; both DTOs interop via `toEntryPage()` / `fromEntryQuery()` factories, which is what lets the Phase 4 public signatures survive unchanged.

## `PreFlight/PreFlightPipeline`

Three single-method visitors in fixed order:

1. **`FieldRefResolver`** — resolves every leaf's `FieldRef` against the snapshot; raises `UnknownFieldException` with a `pre_flight_rejected` event.
2. **`CapabilityChecker`** — checks `driver.supportedOperators()` then `driver.supportsFilterOn(fieldId)`. Raises `QueryFilterValidationException(capability_unsupported)` with a **distinct `capability_unsupported` event**, or `FieldNotFilterableException` for non-filterable fields.
3. **`ValueTypeValidator`** — per-leaf declared-type and bounds enforcement: string max 4096 chars, int signed-64-bit, numeric finite, datetime RFC 3339 with an explicit UTC offset.

The `capability_unsupported` event is deliberately separate from the generic `pre_flight_rejected` so operators can metric "consumer asked for a feature this driver doesn't service" on its own.

## `SearchService::execute()`

Top-level orchestrator, and the single path for `search()`, `read()`, and `get()` alike. It allocates a `correlation_id` if absent, snapshots the schema, runs pre-flight (when `filter !== null`), dispatches to the driver, and emits one `search_request` event carrying `latency_ms`, `rows_returned`, `has_more`, `tree_node_count`, and `compile_strategy`.

## `Mysql/MysqlNativeDriver`

Wraps Phase 4's collaborators (`SchemaVersionCache`, `PaginatedProbe`, `BoundedFetch`, `ResultAssembler`).

`supportsFilterOn(fieldId)` runs a small `JOIN stardust_slot_assignments` lookup, returning `FieldDescriptor::isIndexedNow()` — so MySQL's `is_filterable` semantics stay on the driver, where ADR 0022 places them rather than in the shared pipeline.

## `Mysql/SqlFilterCompiler`

Adaptive. `chooseStrategy(?FilterNode)` returns `'joins'` for `null` or pure-AND trees (`containsDisjunction()` false), and `'exists'` otherwise.

- **JOIN strategy** reuses the Phase 4 INNER-JOIN-per-distinct-page shape **verbatim**, including the LIKE-escape rules and IN-list placeholder layout. This is what preserves the Phase 4 AC#4 composite-index range scans bit-for-bit; changing it silently regresses read performance and the `EXPLAIN` assertion is the only thing that will tell you.
- **EXISTS strategy** emits `EXISTS (SELECT 1 FROM <table> s WHERE s.tenant_id = entry_data.tenant_id AND s.entry_id = entry_data.id AND <pred>)` per leaf (or `NOT EXISTS`), composing the tree with native SQL `AND` / `OR` / `NOT (...)`.

The Architecture Blueprint §1.2 tenant-isolation invariant holds on **both** strategies. Verify it on any new strategy.

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
