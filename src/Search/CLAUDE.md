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
3. **`ValueTypeValidator`** — per-leaf declared-type and bounds enforcement: string max 4096 chars, int signed-64-bit, numeric finite, datetime RFC 3339 with an explicit UTC offset. **It also normalises, which is why it returns a node rather than `void`** — see below.
4. **`SortValidator`** (ADR 0041) — resolves the sort target, asks `driver.supportsSortOn()`, and checks the cursor was issued for this ordering. Raises `UnknownFieldException`, `FieldNotSortableException`, or `InvalidCursorException`, each with a `pre_flight_rejected` event carrying a new `reason` (`sort_field_unknown`, `sort_field_not_sortable`, `cursor_sort_mismatch`) — **`reason` values, not event names, so ADR 0020 is untouched**.

`SortValidator` hangs off a **separate `validateSort()` entry point** rather than a fourth call inside `validate()`, because a sort is not part of the filter tree and has to be checked when there is no filter at all.

The `capability_unsupported` event is deliberately separate from the generic `pre_flight_rejected` so operators can metric "consumer asked for a feature this driver doesn't service" on its own.

### Datetime bounds are normalised in two layers, and each layer alone would look sufficient

The wire format demands an explicit UTC offset on every `datetime` bound and rejects the naive form. Until this landed nothing then *applied* the offset: `SqlFilterCompiler` bound the accepted string verbatim, and MySQL truncates an RFC 3339 literal at the zone designator — so `…T10:00:00+07:00` matched the row holding `10:00`, not the row holding `03:00`. Silent in both directions: the value passed pre-flight, the query still planned as a range scan, and the only complaint was a `Warning 1292` nothing reads. It was also asymmetric with the write path, which normalises in `PayloadSplitter`, so an instant written and then filtered for did not match itself. This is what the wire-format blueprint §4.5 criterion 20 already required ("The API normalises all datetime values to UTC before handing the filter to the driver"); it was an unimplemented criterion, not an open question, so it needed no ADR.

- **`ValueTypeValidator` rewrites the bound to canonical UTC RFC 3339** (`…T03:00:00Z`) once the value has been accepted, covering the scalar, set (`in` / `nin`) and range (`between`) shapes. Deliberately **not** a MySQL `DATETIME` literal: the AST is driver-neutral per ADR 0022, and a driver backed by something other than MySQL has to receive an unambiguous instant.
- **`SqlFilterCompiler::toMysqlLiteral()` renders that as `Y-m-d H:i:s[.u]` at bind time**, through `scalar()` and `listValue()` — the two funnels every bound already passes through. MySQL-specific formatting belongs in the MySQL-specific class, and it is what removes the 1292 from the emitted SQL.

**Either layer alone fixes the MySQL result set, which is a trap for anyone "simplifying" one away.** Neutering just one leaves every behavioural test in `DatetimeBoundNormalisationTest` green, so each layer carries a pin of its own that no behavioural case can substitute for — `testPreFlightHandsTheDriverCanonicalUtcRfc3339` and `testTheCompilerBindsAMysqlDatetimeLiteral`. The behavioural tests were validated by neutering *both*, on the `SlotAffinityTest` precedent, and each pin by neutering its own layer.

`toMysqlLiteral()` converts only a value carrying a zone designator, and **that guard is load-bearing rather than defensive**: its own output carries none, so a second application is a no-op. Without it, re-converting `'2026-01-01 03:00:00'` would parse in PHP's default timezone and then shift to UTC — a silent offset on any host not set to UTC, introduced by nothing worse than an extra call.

**Do not try to assert the 1292's absence through `SHOW WARNINGS`.** The suite's PDO uses native prepares by default (the emulated CI cell aside), `SHOW WARNINGS` is unsupported in that protocol, and the attempt replaces the list it was going to read — it comes back holding a lone `1295`, so the assertion passes no matter what was emitted. Asserting after `search()` fails for a second, independent reason: a bounded read is two queries, the filter is in the first, and the second resets the list before the call returns. The warning's absence was verified directly against 8.0.13; the suite pins the binding that causes it.

**Fractional seconds are preserved, not floored.** A slot column is `DATETIME` at second precision, so flooring the bound looks free — but MySQL compares a fractional constant against it exactly (measured on 8.0.13: `'…10:00:00' < '…10:00:00.5'` is true), so a floored `lt` bound would wrongly exclude the row sitting on the boundary. `testFractionalSecondsAreNotTruncatedAwayFromABound` was validated by flooring and confirming it goes red. Note this differs from `PayloadSplitter` and `RetypeCoercionEngine`, which *do* truncate — correctly, because they format a value being **stored** into that column, where a bound is being **compared** against it.

**The sort anchor is not a third site.** `compileAnchorJoin()` binds only `$anchorId` and `$tenantId`; it resolves the sort value from the anchor row itself, so that value is already column-native and never passes through a bound.

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

### Driving leaf (ADR 0056)

A filtered read in the default order used to lead with `entry_data` and order by `entry_data.id`. On MySQL an equality matching a few percent of a tenant then read the whole match set and sorted it to find one page (10 161 rows touched for a 5% integer equality on 200 000 rows, against 102 with the rule). When the strategy is `joins`, the order is the default or `Id`, and some leaf is an `eq` on a full-width slot column (`i_int_`, `i_num_`, `i_dt_`) whose index the snapshot knows, `compile()` emits `SELECT STRAIGHT_JOIN entry_data.id FROM {driving page} {alias} FORCE INDEX (…) INNER JOIN entry_data … {other pages}`, adds `{alias}.tenant_id = ?`, and moves the keyset and `ORDER BY` onto `{alias}.entry_id`. Every other query keeps its SQL byte for byte.

- **Aliases are untouched.** `compileAsJoins()` still hands out `p0`, `p1`, … in leaf order. The driving page keeps its alias, so the leaf that drives need not be `p0`, and it simply gets no `INNER JOIN` because it moved to `FROM`. The other pages' `ON` clauses name `entry_data`, which now comes before them, so they needed no change.
- **The binding order is the clause order.** `[tenant, model, driving tenant, keyset anchor?, filter…, limit]`. The `ON` clauses carry no placeholders, so moving the joins moves no binding. The driving tenant predicate is inserted ahead of `deleted_at IS NULL`, which has none.
- **`STRAIGHT_JOIN` and `FORCE INDEX` are each load-bearing, and neither works alone.** The hint without the pin leaves `FROM entry_data` first, and the optimiser starts there and sorts the join (one `eq` matching 50%: 222 rows to 200 001 on 8.0.46). The pin without the hint lets a string leaf in the same AND pull the plan onto the tenant index (13 618 rows against 768).
- **The index name comes from `SnapshotEntry::orderedIndexFor()`, never from `ix_{table}_{column}`.** A page from before ADR 0043 or an operator's index may be named anything, and a hint naming a missing index fails the read with errno 1176. It is quoted as an identifier because it comes from the data dictionary.
- **Only equality drives, and a string equality drives only through its key.** The first pass takes the first `eq` on a full-width slot (`i_int_`, `i_num_`, `i_dt_`). Only when it finds none does the second pass (ADR 0057) take the first `eq` on a string slot whose snapshot entry has a `StringKey`, and the hint names that key's index. A string slot on a prefix page is indexed on a 766 character prefix, which cannot answer an equality alone, so it never drives, even when it comes first. Numbers and dates go first so that no query that drives today changes its plan. `in` must not take the shape (238 rows became 200 001), and a range or `prefix` gains nothing. The family is read from the physical column prefix, not from `declaredType`, because the column is what the index covers.
- **"First qualifying leaf" is the deterministic choice, not the cheapest.** The compiler does not know selectivity. In an AND the bound is the driving leaf's match count, not the page size, so a broad leaf written first costs more than a narrow one written second. Measured on 200,000 entries, an `eq` matching 50,008 written before one matching 10 touched 150,025 rows on MySQL 8.0.46 and MariaDB 11.8 (3 × 50,008 + 1), against 25 and 31 for the old SQL and 31 with the rare leaf first. So filter order is a cost the caller controls, and `docs/reading-entries.md` tells them to write the most selective equality first. A follow-up that keeps the old SQL when two or more leaves qualify is possible, and neither built nor measured.
- **MariaDB already plans the old shape well, so a green MariaDB run proves nothing about this rule, and it can plan the old shape better than the rule does.** A 5% leaf written after a 50% one touched 324 rows with the old SQL and 3,231 driven on MariaDB 11.8. `DrivingLeafCompilerTest` pins the SQL shape and `BoundedFilteredReadTest` the rows touched, and it is the MySQL cells that can fail.
- **`compile_strategy` still reports `joins`**, and `PaginatedProbe`, `TypedBinding`, the cursor codec and `SearchService` are unchanged.

### String keys (ADR 0057)

On a page that indexes a string slot through its generated key, a JOIN-strategy leaf on that slot compiles to `{key predicate} AND {original}` (`compileJoinedLeaf()`), and `Support\StringKeyLayout` builds every key predicate. The snapshot says which slots have a key (`SnapshotEntry::stringKeyFor()`), and a slot it records none for compiles exactly as before.

- **The original predicate always stays as the recheck.** The key only has to find a superset of the matches, so a key bound that is too wide costs rows read and one that is too narrow costs rows missed. `StringKeyMatchingTest` is the test that decides: several hundred probes over hostile values, on native and emulated prepares and under three connection collations, each compared with the slot column evaluated alone.
- **The key predicate's bindings are pushed first**, because its placeholders come first in the SQL. The placeholders are positional and repeated, since a named one used twice fails under native prepares (HY093), and the layout counts them so the bindings cannot drift from the SQL.
- **A prefix binds the raw text to the key and the escaped pattern to the `LIKE`.** The key bound is built from the prefix as written, and the `%` and `_` escaping belongs to the recheck only.
- **`neq`, `nin`, `is_null`, `is_not_null` and the EXISTS strategy are untouched.** `neq` and `nin` cannot use an index with or without a key, and EXISTS reaches the page by primary key per entry. The two presence tests were measured both ways at 100,000 rows per tenant: asking the key column too changed no plan on MySQL 8.0.46 or on MariaDB, and made `is_not_null` at 5% slower on MySQL 8.0.13 (25,113 rows touched and a sort, against 1,618 and none). Do not add them back without a plan check on 8.0.13.
- **Three traps each return wrong rows without an error, and each was checked by breaking it.** The search value is cast to the page's collation before its weight string is taken, because a bound value arrives in the connection's collation. The lower bounds are padded with zero bytes and the upper bounds filled with 0xFF. A prefix's upper bound is built from the unpadded head. `StringKeyLayout`'s docblock has the full list.
- **A driven text equality needs its key predicate.** Without it the query still returns the right rows, but the hint names an index whose range is bounded only by the tenant, so it reads the tenant's keys and sorts. `BoundedFilteredReadTest` catches that on MySQL, and a MariaDB run proves nothing about it.
- **A bound reaches the index only through the key's head.** The head is the first 32 bytes of the weight string, about 16 Latin letters or 8 CJK characters, so a longer prefix or range bound on a column whose values all share that start can no longer narrow the index. That was accepted together with the key, and ADR 0057 has the numbers.

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
