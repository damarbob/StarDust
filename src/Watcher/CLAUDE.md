# Watcher daemon

Phase 5 singleton page provisioner (ADR 0008). Process-level singleton enforcement is the CLI's job (`PidFileGuard::acquire(pidFileDir, 'watcher')` in `bin/stardust watcher`); the in-DB `GET_LOCK` is the safety net per ADR 0027.

## The tick

`Watcher` implements `Tickable`. Each `tick()`:

1. Asks `CapacityReporter` for the capacity snapshot and `PendingDemandReader` for the fields waiting on a slot.
2. Hands both to `ProvisioningPlanner::plan()`.
3. Emits `poll_started` carrying `free_ratio`, `threshold`, `total_slots`, `free_slots`, `pages_inspected` (the `COUNT(DISTINCT page_id)` that satisfies blueprint AC Watcher#6's "pages inspected" clause), plus `usable_free_slots`, `usable_total_slots`, `usable_free_ratio`, `pending_demand`, `pending_waiters`, `starved_families`.
4. If the plan says provision, calls `PageProvisioner::provision($plan->indexedColumns)` under `AdvisoryLock`.
5. Runs the 24 h jittered cardinality sample via `CardinalitySampler`.
6. Emits `poll_complete` with `action` + `trigger`.

Provisioning emits `provision_started` → `provision_complete`, both carrying `trigger` + `indexed_columns` + `pending_demand` per AC#6. It catches `AdvisoryLockTimeoutException` → `lock_contention`, and any other Throwable → `provision_failed` with `indexed_columns`, then re-throws so the daemon exits.

## Provisioning is demand-driven as of ADR 0035

Two OR-composed triggers:

- **`unsatisfiable_demand`** — a slot family someone is waiting on has zero claimable indexed-and-free slots. Fires **regardless of threshold**; this is the starvation-freedom guarantee.
- **`low_capacity`** — global free ratio below threshold. Unchanged from Phase 5.

`$action` keeps its closed set `no_action | provisioned | lock_contention`; the reason lives in the separate closed `trigger` set `none | unsatisfiable_demand | low_capacity`, with `unsatisfiable_demand` taking precedence.

Indexed columns are sized `clamp(waiters − indexedFree, 1, familyCapacity)` per demanded family — floored at one because AC#1 binds the low-capacity path too, and empty when there is no demand (no speculative indexing, per ADR 0003 + Phase 2).

### Two traps

**`usable_free_ratio` is logged but is deliberately NOT a trigger.** As a threshold it diverges — one waiter can cascade seven pages. `ProvisioningPlanner`'s docblock and ADR 0035 carry the proof. Do not "fix" it into one.

**`threshold: 0.0` no longer means "never provision"**, since the demand trigger ignores it. A test that wants the Watcher quiet must also leave no filterable field without a slot.

## `PendingDemandReader`

Covers both demand sources named by the blueprint in **one** query over unmapped filterable fields. A field with a `running` `retype_field_%` checkpoint provably has no live slot (the initiator tombstones it in the same transaction) and its `declared_type` is already the target, so deferred retype waiters are a subset that folds in under the correct family with no double-counting.

Phase 6b never provisions pages (ADR 0016 commitment 4 — no eager DDL); it is one of the two demand *sources*, not a provisioner.

## `CardinalitySampler`

`sample()` is the Phase 5 24-h periodic scan over every live slot (`trigger='periodic'`), running the ADR 0019 normative aggregate per `(tenant, slot)`. Emits `cardinality_sampled` always, and `low_cardinality_index` when the selectivity or distinct floor is breached.

Phase 6b adds `sampleSlot(int $slotAssignmentId): void` — the single-slot variant called post-promotion by `RetypeBackfillWorkSource`, emitting `cardinality_sampled` with `trigger='post_backfill'`.

**Cardinality events carry `source: 'registry'`, not `'watcher'`**, per ADR 0020 line 49 — the Watcher merely owns the schedule.
