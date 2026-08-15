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
