# Watcher daemon

Phase 5 singleton page provisioner (ADR 0008). Process-level singleton enforcement is the CLI's job (`PidFileGuard::acquire(pidFileDir, 'watcher')` in `bin/stardust watcher`); the in-DB `GET_LOCK` is the safety net per ADR 0027.

## The tick

`Watcher` implements `Tickable`. Each `tick()`:

1. Asks `CapacityReporter` for the capacity snapshot and `PendingDemandReader` for the fields waiting on a slot.
2. Hands both to `ProvisioningPlanner::plan()`.
3. Emits `poll_started` carrying `free_ratio`, `threshold`, `total_slots`, `free_slots`, `pages_inspected` (the `COUNT(DISTINCT page_id)` that satisfies blueprint AC Watcher#6's "pages inspected" clause), plus `usable_free_slots`, `usable_total_slots`, `usable_free_ratio`, `pending_demand`, `pending_waiters`, `starved_families`.
4. If the plan says provision, calls `PageProvisioner::provision($plan->indexedColumns)` under `AdvisoryLock`.
5. If the 24 h jittered advisory timer is due, runs **both** `CardinalitySampler::sample()` (ADR 0019) and `SpreadSampler::sampleAll()` (ADR 0031).
6. Emits `poll_complete` with `action` + `trigger`.

**One timer drives both advisories.** ADR 0031 §Sampling Triggers 1 requires it: spread drifts only on registry mutation, so a daily cadence is generous, and a second schedule would be a second stampede surface for nothing. A third advisory hangs off the same gate. The private members are named `$nextAdvisorySampleAt` / `shouldSampleAdvisories()` / `scheduleNextAdvisorySample()` accordingly, while the `Config::$cardinality*` fields keep their names because those are public surface.

Provisioning emits `provision_started` → `provision_complete`, both carrying `trigger` + `indexed_columns` + `pending_demand` per AC#6. It catches `AdvisoryLockTimeoutException` → `lock_contention`, and any other Throwable → `provision_failed` with `indexed_columns`, then re-throws so the daemon exits.

## Provisioning is demand-driven as of ADR 0035

Two OR-composed triggers:

- **`unsatisfiable_demand`** — a slot family someone is waiting on has zero claimable indexed-and-free slots. Fires **regardless of threshold**; this is the starvation-freedom guarantee.
- **`low_capacity`** — global free ratio below threshold. Unchanged from Phase 5.

`$action` keeps its closed set `no_action | provisioned | lock_contention`; the reason lives in the separate closed `trigger` set `none | unsatisfiable_demand | low_capacity`, with `unsatisfiable_demand` taking precedence.

Indexed columns are sized `clamp(max(waiters − indexedFree, headroom), floor, familyCapacity)` for **every** family, where `floor` is one for a demanded family and zero otherwise. The floor exists because AC#1 binds the low-capacity path too, where the shortfall can be zero or negative.

`headroom` comes from an injected `IndexHeadroomPolicy` (ADR 0042), currently `FlatIndexHeadroom` carrying `Config::$pageIndexHeadroom`, default **4** — so a new page carries sixteen indexed columns rather than the one to three current demand justifies.

**Why it is not sized to demand.** Indexing only what someone is waiting for was coherent while ADR 0003 let a non-filterable field hold a slot "for typed retrieval". ADR 0034 withdrew that, and with ADR 0004 requiring a filterable field's slot to be indexed, an unindexed slot column can no longer be occupied by anything — so a page's usable capacity is permanently whatever it indexed at birth, and ADR 0012 forbids widening it afterwards. Measured on MySQL 8.0.13, promoting three fields of one model *serially* therefore produced three pages, `free_ratio 0.9833`, and a state nothing could repair: compaction needs indexed free slots to absorb its moves and there were none, while the Watcher saw no starved family and reported `no_action` for ever. `Watcher/SerialPromotionSpreadTest` is that repro inverted, and it goes red at `k = 0` with "3 is identical to 1".

**A `low_capacity` page with no demand is no longer dead weight.** It used to carry zero indexes — sixty columns no reservation could claim. It now carries `4k`, which is also what makes ADR 0032 affinity operative: affinity can only prefer a page holding an indexed free slot of the field's family, and demand-sizing guaranteed there was never one, so every reservation logged `affinity: fallback`.

**The floor and the cap live in the planner, not in the policy, and that is deliberate.** It means no implementation — including `k = 0`, and including one a consumer writes — can break the starvation-freedom guarantee above or emit a column that does not exist. The `max(0, …)` guarding the slice looks redundant to PHPStan and is not: PHP enforces only `int` on `headroomFor()`, and `array_slice($cols, 0, -3)` returns everything *but* the last three columns, so a negative return would silently index most of a family instead of none of it.

### The headroom fallback duplicates `Config`'s default

`Watcher::__construct()` takes `?IndexHeadroomPolicy $headroomPolicy = null` and falls back to `FlatIndexHeadroom(4)` — the same shape as `$provisionLockTimeoutSeconds = 10` two lines above it, and carrying the same hazard. **The literal must track `Config::$pageIndexHeadroom`'s default by hand**; it was moved 1 → 4 alongside it, and a future change to one is a change to both. `Phase5TestCase::makeWatcher()` constructs `Watcher` directly and passes no policy, so **every Watcher smoke test runs on that fallback rather than on `Config::$pageIndexHeadroom`.** Raising the Config default in ADR 0042's second landing without raising the fallback alongside it would leave the entire Watcher suite silently exercising the old value while production ran the new one.

`Watcher/IndexHeadroomWiringTest` is the only test that reaches the daemon through `StarDust::watcher()`, and therefore the only one that reads the Config field at all. It exists because **that factory had no coverage whatsoever** — a wrong field name or a dropped named argument in it would have left the whole suite green. Verified by neutering the wiring to a literal `1` and confirming the raised-headroom case fails; the default case correctly stays green, which is why the test asserts both.

### Two traps

**`usable_free_ratio` is logged but is deliberately NOT a trigger.** As a threshold it diverges — one waiter can cascade seven pages. `ProvisioningPlanner`'s docblock and ADR 0035 carry the proof. Do not "fix" it into one.

**`threshold: 0.0` no longer means "never provision"**, since the demand trigger ignores it. A test that wants the Watcher quiet must also leave no filterable field without a slot.

## `PendingDemandReader`

Covers both demand sources named by the blueprint in **one** query over unmapped filterable fields. A field with a `running` `retype_field_%` checkpoint provably has no live slot (the initiator tombstones it in the same transaction) and its `declared_type` is already the target, so deferred retype waiters are a subset that folds in under the correct family with no double-counting.

Phase 6b never provisions pages (ADR 0016 commitment 4 — no eager DDL); it is one of the two demand *sources*, not a provisioner.

**`pending_demand` is self-draining.** It was not when this gauge first shipped: nothing in `src/` reserved a slot for a plain unmapped filterable field, so a field registered through `schemaBuilder()` sat in this query forever and the gauge showed permanent backlog. The Reconciler's `UnmappedFieldReserver` now closes that loop (`src/Reconciler/CLAUDE.md`), so a demand entry that persists across many polls is a genuine signal — either the Reconciler is not running, or the family has no indexed free capacity and the provisioning path is stuck.

## `SpreadSampler` / `SpreadSample`

ADR 0031 advisory: how many distinct extension pages one model's live filterable slots occupy (`pages_occupied`), how few it could occupy (`theoretical_min_pages`), and the difference (`excess_pages` — the count of avoidable `INNER JOIN`s a fully-referencing query pays). Emits `spread_sampled` on every sample and `high_spread_model` when `pages_occupied >= 2` **and** `excess_pages >= Config::$spreadExcessPageThreshold` (default 2). Both `source: registry`. **Purely observational — it never blocks, rejects, or remediates.**

Three triggers, one per method: `sampleAll()` (`periodic`, from the Watcher), `sampleModel()` (`post_relocation`, from `RetypeBackfillWorkSource` at promotion), `report()` (`on_demand`, backing `bin/stardust spread:report`, and the only one that returns its samples so the CLI can print them).

### The published ADR query does not run — do not "restore" it

ADR 0031 §Sampling Method filters `WHERE sa.tenant_id = :tenant_id`, but **`stardust_slot_assignments` has no `tenant_id` column**. Tenancy reaches a slot only via `field_id → stardust_fields.model_id → stardust_models.tenant_id`, which is what the code joins and what the operator runbook (`maintaining_low_spread.md` §3.3) already documents. Editorial error in the ADR, not a different design.

### Two predicates that look redundant and are not

- **`status IN ('assigned','ready')`** is the *liveness* discriminator, and it is why the emitted field is **`live_slot_count`, never `filterable_slot_count`**. A `backfilling` slot belongs to a filterable field but services no query, so it adds no join cost — counting it would inflate both the slot count and `pages_occupied`.
- **`f.is_filterable = 1`** survives ADR 0034 despite that ADR making non-filterable fields JSON-only. Pre-0034 databases still hold grandfathered live slots on non-filterable fields, which ADR 0034 §5 declines to migrate.

### `theoretical_min_pages` is a max, never a sum

`max over families of ceil(count[f] / capacity[f])` — one page serves all four families simultaneously. Summing the per-family minima is the natural-looking error and would make every multi-family model read as permanently fragmented. Capacities come from `PageProvisioner::slotColumnsForType()` rather than restated 25/15/10/10, so the formula cannot drift from the DDL. `SpreadSample`'s statics are public because ADR 0033's compaction planner needs the identical formula to pick a target page set.

## `CardinalitySampler`

`sample()` is the Phase 5 24-h periodic scan over every live slot (`trigger='periodic'`), running the ADR 0019 normative aggregate per `(tenant, slot)`. Emits `cardinality_sampled` always, and `low_cardinality_index` when the selectivity or distinct floor is breached.

Phase 6b adds `sampleSlot(int $slotAssignmentId): void` — the single-slot variant called post-promotion by `RetypeBackfillWorkSource`, emitting `cardinality_sampled` with `trigger='post_backfill'`.

**Cardinality events carry `source: 'registry'`, not `'watcher'`**, per ADR 0020 line 49 — the Watcher merely owns the schedule.

## ADR 0038: `PendingDemandReader`'s safety is not a local property

`PendingDemandReader` gates on `f.is_filterable = 1 AND f.deleted_at IS NULL` and carries **no model predicate**. A model deletion marks every existing field, so those are excluded for free — but a field created *after* severance is not marked, and nothing about this query would stop it being read as demand.

What actually closes that door is `SchemaBuilder::insertField()`, which refuses to add a field to a model whose `deleted_at` is set. Measured on MySQL 8.0.13: without it the INSERT succeeds, this reader returns the new field, `UnmappedFieldReserver` reserves it a slot on the ADR 0007 exhaustion path, and the model purge's **final** chunk then fails errno 1451 — permanently, after earlier chunks have already destroyed their entries, with no dead-letter route out.

So the guard that protects this query lives in another package. Do not "simplify" `insertField()`'s model check away, and if this reader is ever refactored, keep the coupling in mind rather than assuming the predicate above is sufficient on its own.
