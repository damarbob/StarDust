# Read path

Phase 4 surface. **Phase 8 hollowed this package out**: `EntryReader` is now a thin façade that builds a `SearchService` from a `(PDO, LoggerInterface)` pair and routes `read()` / `get()` through it — the constructor signature is unchanged, so Phase 4 tests did not need rewiring. `QueryValidator` was deleted outright (its logic moved into `src/Search/PreFlight/` + `SearchRequest`'s constructor), and `PaginatedProbe` was reduced to a thin caller that delegates to `SqlFilterCompiler` and runs the prepared statement. See `src/Search/CLAUDE.md` before changing anything here.

## The two-query bounded sequence (ADR 0005)

`EntryReader::read(EntryQuery)` still describes the pipeline, even though the orchestration now lives in `SearchService`:

1. `SchemaVersionCache` resolves the per-model `SnapshotEntry`. Cache is keyed on `stardust_schema_version.version`; refresh emits `api: cache_miss` per ADR 0015's coordination contract, with a 60 s bounded-staleness TTL fallback on probe failure.
2. Phase 8's `PreFlightPipeline` rejects unknown / non-filterable / non-indexed (`backfilling | tombstoned | unmapped`) filter targets pre-flight per **ADR 0004** with an `api: pre_flight_rejected` event.
3. `PaginatedProbe` SELECTs only `entry_data.id` with the keyset predicate and `LIMIT pageSize + 1`. The `+1` is the sole next-page signal — no `COUNT(*)`, no `OFFSET`, per **ADR 0006**. Unsorted that predicate is `id > :cursor`; under a sort it is the three-branch form in "Sorting" below. **With no cursor there is no predicate at all** — the old `id > 0` sentinel was a no-op on every first page and outright wrong under `DESC`.
4. `BoundedFetch` materialises only the probed ids plus indexed slot columns via LEFT JOIN, then **restores the probe's ordering in PHP**. It used to re-sort with `ORDER BY entry_data.id ASC`, which was invisible only because the probe also ordered by id; under any other sort that clause actively destroyed the ordering the probe had just chosen. Reordering against the probe's own id sequence is exact for every sort mode and removes a database sort rather than adding one — but it makes this the single place that must agree with the probe.
5. `ResultAssembler` sources each field from the slot column only when the field is filterable AND status is `assigned`/`ready` (`FieldDescriptor::isIndexedNow()` — **both** conditions, not status alone), otherwise from the decoded `entry_data.fields` payload.

**ADR 0036 adds a second fallback on top of that one.** When the field's `previous_name` is non-null a rename backfill is still draining, so `ResultAssembler` tries the current name and then the old one. Without it every un-migrated row would read `null` for the renamed field for the whole drain — silently, because the payload miss path is `?? null`. `SlotResolver` picks `previous_name` up from the `stardust_fields` SELECT it already runs, so this costs no extra query, and `SnapshotEntry::hasRenamesInFlight()` keeps the steady state at one boolean. The class docblock's "equivalent to a `JSON_EXTRACT` projection" claim is explicitly scoped to exclude this window — a single-path extract on the new name would be wrong there.

That last point is the JSON-payload fallback: the slot column is never consulted for non-filterable, `backfilling`, `tombstoned`, or unmapped fields, which is what satisfies the Phase 4 exit criterion. Per ADR 0034, non-filterable fields are JSON-only and should never be assigned slots at all.

Tenant isolation is enforced at every `WHERE` and `JOIN` per Architecture Blueprint §1.2.

**ADR 0037 adds the mirror-image case: a field the snapshot must stop reporting.** `SlotResolver` excludes any row with a non-null `deleted_at` from `fieldsByName`, which is what makes `read()` drop the field and the pre-flight reject a filter on it the instant the deletion commits — while the values are still physically in `entry_data` for the length of the purge. The names are still carried, on `SnapshotEntry::$pendingDeletionNames`, for the point read alone.

## Point read

`EntryReader::get(int $tenantId, int $entryId)` — no slot joins; the JSON payload is the system of record per ADR 0013.

Because it returns the payload verbatim, it is the one read surface that would otherwise leak a key the registry has already severed. `SnapshotEntry::canonicalisePayloadKeys()` therefore does two jobs: it rewrites an in-flight rename's old key forward, and it strips an in-flight deletion's key outright. **Without the second, `get()` and `read()` would disagree about the same entry for the whole purge window** — the paginated read is driven by `fieldsByName`, which excludes the field, and the point read is not. `DeleteWindowTest::testPointReadAgreesWithPaginatedReadDuringTheWindow` pins it. Both halves are guarded by an eagerly-computed boolean so the steady-state cost stays at one check.

## Cursors

`CursorCodec` encodes the opaque next-page token in one of two formats. **Consumers MUST NOT inspect either.**

- `base64url("v1:" . entryId)` — an unsorted read. Byte-identical to what shipped before sorting existed, so tokens already in flight keep working.
- `base64url("v2:" . json)` — a sorted read. Carries the anchor id **plus the sort key identity and direction**.

`decodePayload()` handles both and returns a `CursorPayload`; `decode()` survives as the entry-id-only wrapper. A v1 token is accepted by an explicitly-default sort, because `null` and `SortSpec::byId()` name the same ordering.

**A v2 token deliberately does not carry the anchor row's sort value.** A string slot holds 4096 characters, so a self-contained token would reach ~22 KB — past every practical URL and header limit. The value is resolved from the anchor row at query time instead, which keeps tokens constant-size and keeps ADR 0006's "cursor derived from the id of the last record seen" literally true. The cost is that a cursor whose anchor row is removed mid-pagination resumes from the NULL block rather than erroring.

Stamping the ordering is what makes ADR 0006's "a cursor is invalidated if the caller changes sort order" **enforceable** — `SortValidator` raises `InvalidCursorException` rather than letting the walk silently change sequence.

## Sorting (ADR 0041)

`EntryQuery::$sort` is an appended `?SortSpec`; `null` means `entry_data.id ASC`, so every pre-sort caller is unaffected. `SortSpec::byId()` / `byCreatedAt()` / `byField()`, direction via the `SortDirection` enum. One key only — `entry_data.id` is always appended in the same direction as the tiebreak that makes the ordering total, which is what makes the cursor stable.

**The cost split is the thing to know.** The two intrinsic targets stay index-ordered (`id DESC` is a backward index scan; `created_at` rides `(tenant_id, deleted_at, created_at)`). A **field** sort plans as `Using temporary; Using filesort` over the whole filtered set, because ordering by a slot column cannot use its index while the query leads with `entry_data`. ADR 0041 scopes ADR 0005's bounded-discovery guarantee accordingly.

**The keyset predicate has three branches and all of them are load-bearing.** A slot column is nullable — a row awaiting an ADR 0007 exhaustion backfill reads NULL — and `col > NULL` is UNKNOWN, so a two-branch predicate silently drops the entire NULL block. Deleting the first branch makes an ascending walk stop at the end of the NULL block and never reach the valued rows; `SortCursorPaginationTest::testWalksThroughTheNullBlockInBothDirections` pins exactly that and was validated by neutering.

**String slots sort exactly, on the full value.** ADR 0030 §51 claimed `max_sort_length` truncates an `ORDER BY` on TEXT at 1024 bytes; probed on 8.0.13 it does not, even with the setting forced to 8 and values differing only at byte 36,856. A truncating collation-key design was drafted against that claim and discarded on the evidence — see the dated correction on ADR 0030. **Do not reintroduce a `LEFT(col, N)` sort key**: it would create the inexactness the ADR feared rather than avoid it.

## `EntryQuery`

The typed PHP DTO accepted at the boundary. Since Phase 8 its `?FilterNode $filter` takes the full AND/OR/NOT AST, and since ADR 0041 it carries an appended `?SortSpec $sort`. `EntryQuery::fromFlatFilters()` is the migration factory for Phase 4 callers that used to pass a flat, implicitly-ANDed leaf list: 0 leaves → `null`, 1 → unwrapped, 2+ → wrapped in an `AndNode`.
