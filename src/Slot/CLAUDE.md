# Slot reservation

## `SlotReserver`

Phase 2 atomic `free → assigned` transition. `reserve(int $fieldId): ?SlotAssignment` maps the field's `declared_type` to the slot family (`string→str`, `int→int`, `numeric→num`, `datetime→dt`), selects the oldest matching free row `FOR UPDATE`, updates `status` + `field_id`, bumps `stardust_schema_version.version`, and commits — or rolls back and returns `null` if no free slot of that family exists. The partial unique `ux_slot_assignments_field_live` enforces "at most one live slot per field" at the database level. Emits a `slot_reserved` NDJSON event on success.

Two more entry points:

- `reserveForBackfillWithinTransaction(int $fieldId, bool $requireIndexed = false)` (Phase 6b) — the `free → backfilling` variant, caller owns the surrounding tx. Used by `RetypeInitiator` to compose the slot reservation into its atomic registry tuple and by `RetypeBackfillWorkSource` for a deferred reservation; the caller emits the `slot_reserved` event post-commit via `emitSlotReservedEvent()`. When `$requireIndexed=true`, restricts candidates via `IndexedSlotPredicate::existsSql()`.
- `reserveForExhaustionBackfill(int $fieldId): ?SlotAssignment` — the ADR 0007 path, called by `SyncQueueWorkSource` when a backfill finds a registered filterable field still unmapped. `free → assigned` in its own transaction, with `requireIndexed` **hardcoded true**.
- `reserveForBackfillOnPageWithinTransaction(int $fieldId, int $pageId): ?SlotAssignment` (ADR 0033) — the page-pinned variant compaction needs. **No affinity pass and no global-oldest spill**: exactly the given page or `null`, because a compaction reservation that lands elsewhere produces a compaction that does not compact. `requireIndexed` is hardcoded true. The caller turns `null` into a hard failure (pin-or-fail); see `src/Compaction/CLAUDE.md`.

Phase 6b's `reserveForBackfill()` (the own-transaction `free → backfilling` variant) was deleted when the ADR 0007 path landed: it never acquired a production caller.

### Only a filterable field may hold a slot (ADR 0034)

All three entry points funnel through the private chokepoint `reserveCore()`, which no longer resolves the field itself. The private `resolveReservableSlotType()` reads `declared_type` + `is_filterable`, throws `NonFilterableFieldSlotException` for a non-filterable field, and returns the already-mapped `slot_type` that `reserveCore()` requires as a parameter — so **the guard is structurally unbypassable and any future entry point inherits it**. `reserveForExhaustionBackfill()` is the proof: it was added later and needed no guard of its own.

The resolver is called from the two wrappers *before* `beginTransaction()` on the own-transaction paths, so a rejected reservation opens no transaction and takes no `FOR UPDATE` gap locks.

### `reserveCore()` checks `rowCount()`, and that check is not decoration

The claiming UPDATE can be refused by `ux_slot_assignments_field_live`. Under `ERRMODE_EXCEPTION` that raises and the caller's catch handles it — but the engine takes an **injected** PDO (ADR 0026), and on `ERRMODE_SILENT` `execute()` merely returns `false`. Without the `rowCount() === 0` guard the method bumped the schema version and returned a `SlotAssignment` for a slot it never claimed.

That phantom is worse than a crash on the ADR 0007 path: the caller logs `slot_reserved`, counts progress, suppresses `capacity_wait`, and repeats the identical no-op every tick — a silent loop with nothing in the event stream to show an operator. `rowCount() === 0` is the same "my write did not land" detector `ImportJobWorkSource` uses for lease loss, and it is exact here because the UPDATE always changes `status`, so a matched row is always a changed row.

Behaviour verified against **MySQL 8.0.13** (the project floor): the collision is errno **1062** / `SQLSTATE 23000` when the rival reservation has committed, and errno **1205** / `SQLSTATE HY000` while it is still open — the latter after blocking for up to `innodb_lock_wait_timeout`, default **50 s**. Regression test: `SlotReserverTest::testReserveReportsNullRatherThanAPhantomSlotUnderErrmodeSilent`.

### Which entry points are actually live

Two of the three have production callers:

- `reserveForBackfillWithinTransaction()` — the retype lifecycle (`RetypeInitiator` + `RetypeBackfillWorkSource`).
- `reserveForExhaustionBackfill()` — the ADR 0007 exhaustion path (`SyncQueueWorkSource`, via `UnmappedFieldReserver`).

`reserve()` is reached only from `docker/seed.php` and tests, and keeps its Phase 2 `requireIndexed: false` default for exactly that reason. Under ADR 0034 every slot belongs to a filterable field and ADR 0004 implies filterable ⇒ indexed, so flipping that default is defensible — but it has a wide test blast radius and needs its own change.

**The ADR 0007 path closed a gap this section used to warn about.** Before it, no production path reserved for a plain unmapped filterable field, so a field registered filterable through `schemaBuilder()` never acquired a slot and the Watcher's `pending_demand` gauge had nothing that drained it. `SyncQueueWorkSource` now reserves (see `src/Reconciler/CLAUDE.md`), so a perpetually non-zero `pending_demand` is once again a real signal worth investigating.

## Model affinity (ADR 0032)

`reserveCore()` biases candidate selection toward pages already hosting a **live** slot of the same model, falling back to global-oldest when no affine candidate of the family exists. The model id rides along from `resolveReservableField()`'s existing row read, so no `reserve*()` signature gained a parameter. The outcome lands on `SlotAssignment::$affinity` (`co_located | fallback`) and in the existing `slot_reserved` event — additive, so no ADR 0020 change.

**It is a bias, never a constraint.** When the affine page has no free slot of the family, reservation spills to global-oldest. Affinity must never fail a reservation that would otherwise succeed, or ADR 0007 write availability breaks and a reservation can starve. `requireIndexed` narrows *eligibility* and therefore outranks affinity's *ordering*: an unindexed affine page loses to an indexed non-affine one, because ADR 0004 forbids a filterable field on an unindexed column.

### Why it is two queries and not an `ORDER BY` key — measured, not guessed

The obvious implementation of the ADR's conceptual ordering is one query with `ORDER BY (a.page_id IN (…)) DESC, a.page_id, a.id`. **That is a serious concurrency regression.** On MySQL 8.0.13:

| shape | plan | free rows locked |
| :-- | :-- | :-- |
| global-oldest (pre-affinity) | `type=index`, no filesort | 1 of 15 |
| `ORDER BY (page_id IN …) DESC` | `type=ref`, **filesort** | **all of them** |
| affine-scoped `WHERE` + `FORCE INDEX` | `type=range`, no filesort | 1 of 15 |

The ordering expression cannot come from an index, so the optimiser drops the index-ordered `LIMIT 1` walk and filesorts the family's whole free pool — and `FOR UPDATE` locks every row it examines. Concurrent reservers for unrelated models would fully serialise. So affinity is scoped into the `WHERE` of a first query, with the global-oldest query unchanged as the fallback.

**`FORCE INDEX (ix_slot_assignments_page_status)` is load-bearing.** Without it the optimiser picks `index_merge … Using intersect(...)` for `page_id IN (…) AND status='free'` and the footprint expands again. Do not remove it without re-measuring.

### The affine status set differs from the spread metric's, deliberately

Affinity uses `LiveSlotMap::LIVE_STATUSES` — including `backfilling`. `SpreadSampler` (ADR 0031) uses only `('assigned','ready')`. Different questions: spread measures join cost and a `backfilling` slot serves no query, whereas affinity asks where the model is *going* to live. Do not unify them.

The affine page-set read is a **plain `SELECT`, before the candidate query and never inside it**. ADR 0032 makes that normative: a correlated `EXISTS` in the `FOR UPDATE` statement can lock the model's live sibling slots, which the write path reads and relocations mutate. `SlotAffinityTest::testHeldReservationDoesNotLockTheModelsLiveSiblings` is the guard.

## `IndexedSlotPredicate`

**The single definition of "this slot column is indexed."** The reserver filters candidates with it and the Watcher's `CapacityReporter` counts usable inventory with it, and they MUST stay identical: if they drift, the Watcher reports capacity the reserver refuses, so a page looks healthy while every reservation against it returns `null`. `tests/Smoke/Slot/IndexedSlotPredicateTest` is a source scan enforcing that neither file inlines the literal.

The predicate tests "participates in any index", not specifically the `(tenant_id, slot)` composite — equivalent for engine-built pages, since the only other indexes a page carries are `PRIMARY KEY (entry_id)` and `ix_<table>_tenant`.

Because indexedness is derived from `information_schema.STATISTICS` rather than persisted, this class is also the seam that would keep a future `stardust_slot_assignments.is_indexed` column a one-file migration.

## `DECLARED_TYPE_TO_SLOT_TYPE`

`public const` so the Watcher's demand reader folds waiters into families with the same map.
