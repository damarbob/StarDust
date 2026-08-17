# Slot reservation

## `SlotReserver`

Phase 2 atomic `free → assigned` transition. `reserve(int $fieldId): ?SlotAssignment` maps the field's `declared_type` to the slot family (`string→str`, `int→int`, `numeric→num`, `datetime→dt`), selects the oldest matching free row `FOR UPDATE`, updates `status` + `field_id`, bumps `stardust_schema_version.version`, and commits — or rolls back and returns `null` if no free slot of that family exists. The partial unique `ux_slot_assignments_field_live` enforces "at most one live slot per field" at the database level. Emits a `slot_reserved` NDJSON event on success.

Two more entry points:

- `reserveForBackfillWithinTransaction(int $fieldId, bool $requireIndexed = false)` (Phase 6b) — the `free → backfilling` variant, caller owns the surrounding tx. Used by `RetypeInitiator` to compose the slot reservation into its atomic registry tuple and by `RetypeBackfillWorkSource` for a deferred reservation; the caller emits the `slot_reserved` event post-commit via `emitSlotReservedEvent()`. When `$requireIndexed=true`, restricts candidates via `IndexedSlotPredicate::existsSql()`.
- `reserveForExhaustionBackfill(int $fieldId): ?SlotAssignment` — the ADR 0007 path, called by `SyncQueueWorkSource` when a backfill finds a registered filterable field still unmapped. `free → assigned` in its own transaction, with `requireIndexed` **hardcoded true**.

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

## `IndexedSlotPredicate`

**The single definition of "this slot column is indexed."** The reserver filters candidates with it and the Watcher's `CapacityReporter` counts usable inventory with it, and they MUST stay identical: if they drift, the Watcher reports capacity the reserver refuses, so a page looks healthy while every reservation against it returns `null`. `tests/Smoke/Slot/IndexedSlotPredicateTest` is a source scan enforcing that neither file inlines the literal.

The predicate tests "participates in any index", not specifically the `(tenant_id, slot)` composite — equivalent for engine-built pages, since the only other indexes a page carries are `PRIMARY KEY (entry_id)` and `ix_<table>_tenant`.

Because indexedness is derived from `information_schema.STATISTICS` rather than persisted, this class is also the seam that would keep a future `stardust_slot_assignments.is_indexed` column a one-file migration.

## `DECLARED_TYPE_TO_SLOT_TYPE`

`public const` so the Watcher's demand reader folds waiters into families with the same map.
