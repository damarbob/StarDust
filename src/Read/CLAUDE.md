# Read path

Phase 4 surface. **Phase 8 hollowed this package out**: `EntryReader` is now a thin façade that builds a `SearchService` from a `(PDO, LoggerInterface)` pair and routes `read()` / `get()` through it — the constructor signature is unchanged, so Phase 4 tests did not need rewiring. `QueryValidator` was deleted outright (its logic moved into `src/Search/PreFlight/` + `SearchRequest`'s constructor), and `PaginatedProbe` was reduced to a thin caller that delegates to `SqlFilterCompiler` and runs the prepared statement. See `src/Search/CLAUDE.md` before changing anything here.

## The two-query bounded sequence (ADR 0005)

`EntryReader::read(EntryQuery)` still describes the pipeline, even though the orchestration now lives in `SearchService`:

1. `SchemaVersionCache` resolves the per-model `SnapshotEntry`. Cache is keyed on `stardust_schema_version.version`; refresh emits `api: cache_miss` per ADR 0015's coordination contract, with a 60 s bounded-staleness TTL fallback on probe failure.
2. Phase 8's `PreFlightPipeline` rejects unknown / non-filterable / non-indexed (`backfilling | tombstoned | unmapped`) filter targets pre-flight per **ADR 0004** with an `api: pre_flight_rejected` event.
3. `PaginatedProbe` SELECTs only `entry_data.id` with `id > :cursor LIMIT pageSize + 1`. The `+1` is the sole next-page signal — no `COUNT(*)`, no `OFFSET`, per **ADR 0006**.
4. `BoundedFetch` materialises only the probed ids plus indexed slot columns via LEFT JOIN.
5. `ResultAssembler` sources each field from the slot column only when the field is filterable AND status is `assigned`/`ready` (`FieldDescriptor::isIndexedNow()` — **both** conditions, not status alone), otherwise from the decoded `entry_data.fields` payload.

**ADR 0036 adds a second fallback on top of that one.** When the field's `previous_name` is non-null a rename backfill is still draining, so `ResultAssembler` tries the current name and then the old one. Without it every un-migrated row would read `null` for the renamed field for the whole drain — silently, because the payload miss path is `?? null`. `SlotResolver` picks `previous_name` up from the `stardust_fields` SELECT it already runs, so this costs no extra query, and `SnapshotEntry::hasRenamesInFlight()` keeps the steady state at one boolean. The class docblock's "equivalent to a `JSON_EXTRACT` projection" claim is explicitly scoped to exclude this window — a single-path extract on the new name would be wrong there.

That last point is the JSON-payload fallback: the slot column is never consulted for non-filterable, `backfilling`, `tombstoned`, or unmapped fields, which is what satisfies the Phase 4 exit criterion. Per ADR 0034, non-filterable fields are JSON-only and should never be assigned slots at all.

Tenant isolation is enforced at every `WHERE` and `JOIN` per Architecture Blueprint §1.2.

**ADR 0037 adds the mirror-image case: a field the snapshot must stop reporting.** `SlotResolver` excludes any row with a non-null `deleted_at` from `fieldsByName`, which is what makes `read()` drop the field and the pre-flight reject a filter on it the instant the deletion commits — while the values are still physically in `entry_data` for the length of the purge. The names are still carried, on `SnapshotEntry::$pendingDeletionNames`, for the point read alone.

## Point read

`EntryReader::get(int $tenantId, int $entryId)` — no slot joins; the JSON payload is the system of record per ADR 0013.

Because it returns the payload verbatim, it is the one read surface that would otherwise leak a key the registry has already severed. `SnapshotEntry::canonicalisePayloadKeys()` therefore does two jobs: it rewrites an in-flight rename's old key forward, and it strips an in-flight deletion's key outright. **Without the second, `get()` and `read()` would disagree about the same entry for the whole purge window** — the paginated read is driven by `fieldsByName`, which excludes the field, and the point read is not. `DeleteWindowTest::testPointReadAgreesWithPaginatedReadDuringTheWindow` pins it. Both halves are guarded by an eagerly-computed boolean so the steady-state cost stays at one check.

## Cursors

`CursorCodec` encodes the opaque next-page token as `base64url("v1:" . entryId)`. **Consumers MUST NOT inspect it.**

## `EntryQuery`

The typed PHP DTO accepted at the boundary. Since Phase 8 its `?FilterNode $filter` takes the full AND/OR/NOT AST. `EntryQuery::fromFlatFilters()` is the migration factory for Phase 4 callers that used to pass a flat, implicitly-ANDed leaf list: 0 leaves → `null`, 1 → unwrapped, 2+ → wrapped in an `AndNode`.
