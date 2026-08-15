# Slot reservation

## `SlotReserver`

Phase 2 atomic `free → assigned` transition. `reserve(int $fieldId): ?SlotAssignment` maps the field's `declared_type` to the slot family (`string→str`, `int→int`, `numeric→num`, `datetime→dt`), selects the oldest matching free row `FOR UPDATE`, updates `status` + `field_id`, bumps `stardust_schema_version.version`, and commits — or rolls back and returns `null` if no free slot of that family exists. The partial unique `ux_slot_assignments_field_live` enforces "at most one live slot per field" at the database level. Emits a `slot_reserved` NDJSON event on success.

Phase 6b adds two more entry points:

- `reserveForBackfill(int $fieldId, bool $requireIndexed = false): ?SlotAssignment` — the `free → backfilling` variant. When `$requireIndexed=true`, restricts candidates via `IndexedSlotPredicate::existsSql()`.
- `reserveForBackfillWithinTransaction()` — caller owns the surrounding tx. Used by `RetypeInitiator` to compose the slot reservation into its atomic registry tuple; the caller emits the `slot_reserved` event post-commit via `emitSlotReservedEvent()`.

### Only a filterable field may hold a slot (ADR 0034)

All three entry points funnel through the private chokepoint `reserveCore()`, which no longer resolves the field itself. The private `resolveReservableSlotType()` reads `declared_type` + `is_filterable`, throws `NonFilterableFieldSlotException` for a non-filterable field, and returns the already-mapped `slot_type` that `reserveCore()` requires as a parameter — so **the guard is structurally unbypassable and a future fourth entry point inherits it**.

The resolver is called from the two wrappers *before* `beginTransaction()` on the own-transaction paths, so a rejected reservation opens no transaction and takes no `FOR UPDATE` gap locks.

### Which entry points are actually live

`reserveForBackfillWithinTransaction()` is the only reservation path with production callers (`RetypeInitiator` + `RetypeBackfillWorkSource`). `reserve()` is reached only from `docker/seed.php` and tests. `reserveForBackfill()` has no callers at all.

This is a **known** gap, not an oversight: no production path reserves a slot for a plain unmapped filterable field, so the Watcher's `pending_demand` gauge has nothing that drains it. A field registered filterable through `schemaBuilder()` never acquires a slot; `SyncQueueWorkSource` rolls the chunk back and emits `capacity_wait` rather than reserving. Severity is misleading signal, not a loop — provisioning stays stable, since the unsatisfiable-demand trigger fires once and then clears.

So do not "fix" the apparently-dead entry points on sight, and do not treat a perpetually non-zero `pending_demand` as a bug in the Watcher. Ask before changing either.

## `IndexedSlotPredicate`

**The single definition of "this slot column is indexed."** The reserver filters candidates with it and the Watcher's `CapacityReporter` counts usable inventory with it, and they MUST stay identical: if they drift, the Watcher reports capacity the reserver refuses, so a page looks healthy while every reservation against it returns `null`. `tests/Smoke/Slot/IndexedSlotPredicateTest` is a source scan enforcing that neither file inlines the literal.

The predicate tests "participates in any index", not specifically the `(tenant_id, slot)` composite — equivalent for engine-built pages, since the only other indexes a page carries are `PRIMARY KEY (entry_id)` and `ix_<table>_tenant`.

Because indexedness is derived from `information_schema.STATISTICS` rather than persisted, this class is also the seam that would keep a future `stardust_slot_assignments.is_indexed` column a one-file migration.

## `DECLARED_TYPE_TO_SLOT_TYPE`

`public const` so the Watcher's demand reader folds waiters into families with the same map.
