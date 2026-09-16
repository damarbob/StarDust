# Watcher daemon

Phase 5 singleton page provisioner (ADR 0008). Process-level singleton enforcement is the CLI's job (`PidFileGuard::acquire(pidFileDir, 'watcher')` in `bin/stardust watcher`); the in-DB `GET_LOCK` is the safety net per ADR 0027.

**That lock's name is `stardust_page_provision` qualified per installation (ADR 0053).** Blueprint AC#2's base name and its 10-second timeout are both still normative and unchanged — the suffix only stops two StarDust installations on one shared `mysqld` from excluding each other, since `GET_LOCK` names are server-scoped rather than database-scoped. Note the interaction with ADR 0027's model: a PID file is per-host, so across two hosts the advisory lock *is* the Watcher's excluder, and qualification does not weaken that (both hosts resolve the same schema to the same suffix). See `src/Daemon/CLAUDE.md`.

## The tick

`Watcher` implements `Tickable`. Each `tick()`:

1. Asks `CapacityReporter` for the capacity snapshot and `PendingDemandReader` for the fields waiting on a slot.
2. Hands both to `ProvisioningPlanner::plan()`.
3. Emits `poll_started` carrying `free_ratio`, `threshold`, `total_slots`, `free_slots`, `pages_inspected` (the `COUNT(DISTINCT page_id)` that satisfies blueprint AC Watcher#6's "pages inspected" clause), plus `usable_free_slots`, `usable_total_slots`, `usable_free_ratio`, `pending_demand`, `pending_waiters`, `starved_families`.
4. If the plan says provision, calls `PageProvisioner::provision($plan->indexedColumns)` under `AdvisoryLock`.
5. If the 24 h jittered advisory timer is due — and this process wins the claim on it — runs **both** `CardinalitySampler::sample()` (ADR 0019) and `SpreadSampler::sampleAll()` (ADR 0031). The timer is persisted and shared since ADR 0052; see below.
6. Emits `poll_complete` with `action` + `trigger` + `advisories_sampled`.

**Both advisories take the cycle id**, so a daily sweep's hundreds of `cardinality_sampled` / `spread_sampled` lines read as one sweep instead of hundreds of unrelated observations. `CardinalitySampler::sample()` and `SpreadSampler::sampleAll()` still mint one when passed null, since `bin/stardust spread:report` is its own operation boundary. This was missed on the first pass of the ADR 0020 correlation work and is the reason `EventCorrelationTest` carries an explicit warning: both samplers *name* `correlation_id` at their emit sites, so a static scan passes them while they mint ids nothing else shares.

**One timer drives both advisories.** ADR 0031 §Sampling Triggers 1 requires it: spread drifts only on registry mutation, so a daily cadence is generous, and a second schedule would be a second stampede surface for nothing. A third advisory hangs off the same gate. The private members are named `shouldSampleAdvisories()` / `computeNextDue()` accordingly, while the `Config::$cardinality*` fields keep their names because those are public surface.

Provisioning emits `provision_started` → `provision_complete`, both carrying `trigger` + `indexed_columns` + `pending_demand` per AC#6. It catches `AdvisoryLockTimeoutException` → `lock_contention`, and any other Throwable → `provision_failed` with `indexed_columns`, then re-throws so the daemon exits.

**The cycle id is passed into `provision()`**, so `PageProvisioner`'s `page_provisioned` lands between that pair under the same `correlation_id` rather than under one the logger synthesised for it. Until it was, the one event naming the page could not be joined to the decision that created it — invisibly, because a synthesised UUID is indistinguishable from a threaded one on the wire. `WatcherDemandDrivenProvisionTest::testPageProvisionedJoinsTheTickThatOrderedIt` pins all three; background in `src/Logging/CLAUDE.md`.

## The advisory schedule is persisted, and therefore fleet-wide (ADR 0052)

It used to be `$nextAdvisorySampleAt`, a process-local field. That is correct for a persistent daemon and **unreachable under ADR 0048's combined tick**: `StarDust::tick()` builds a fresh object graph per invocation (`combinedTick()` is deliberately not memoised), so a cron-driven host got the always-false first due-check and exited, and neither advisory ever fired without `tick --advisories`. The state now lives in the `stardust_advisory_schedule` singleton via `AdvisoryScheduleRepository`.

`CombinedTick` needed **no change at all** for this, which is why the claim sits inside `Watcher::tick()` rather than in the tick: `CombinedTick` already calls `tick()` once per run unconditionally and again on every `CAPACITY_WAIT` round, and the claim makes the extra calls no-ops instead of duplicate sweeps.

**The consequence to state plainly: one sample now fires per interval across the whole deployment, not one per daemon.** Three hosts running `bin/stardust watcher` go from three daily sweeps to one. Both samplers scan the global pool, so the other two were duplicate work — but this is a behaviour change to the shipped persistent-daemon mode, not just an addition to the cron one.

`sampleAdvisories()` keeps its unconditional "sample now" contract for the `--advisories` flag and additionally **resets the timer**, so a forced sample is not followed minutes later by a scheduled one.

### Three traps, all measured on 8.0.13

- **The claim is the affected-row count of one UPDATE**, and the reschedule cannot be split out of it. Racing hosts serialize on the row lock and the loser re-evaluates the predicate against the winner's committed row. Verified with two real OS processes on separate pid dirs (so the Watcher pid guard was provably not the excluder — both reported `idle`): exactly one sampled.
- **MySQL reports *changed* rows, not matched rows**, and the engine cannot set `CLIENT_FOUND_ROWS` on an injected PDO. A claim writing back the value already stored reports zero despite matching, and the sample is skipped in silence — hence the `max(..., $from + 1)` floor in `computeNextDue()`. Unreachable at the 86 400 s default; only a degenerate `interval = 0, jitter = 0` reaches it, and `AdvisoryScheduleTest` covers exactly that.
- **A reused *named* placeholder is rejected.** `:now` three times in the claim throws `SQLSTATE[HY093]` under native prepares. Positional `?` with the value repeated — do not "tidy" them into named parameters.

**The next due time is computed *before* the samplers run**, not after, since the claim and the reschedule are one statement. Cadence therefore no longer drifts forward by each sweep's duration, which the in-memory version did by rescheduling from `now()` on completion. The flip side: the claim commits before the work, so a sampler that throws loses that interval's sample rather than retrying it. Unavoidable given one-statement claim-and-reschedule, and not a regression — the in-memory version rescheduled only on success too, but the exception killed the process and its replacement phase-randomised a fresh schedule, waiting up to an interval anyway. Both advisories are read-only, so the cost is a missing observation rather than inconsistent state.

**`poll_complete` carries `advisories_sampled`.** A field on an existing event, not a new event name, so `EventVocabularyTest`'s allowlist is untouched — and it is the only way to tell "not due" from "due but another host won the claim" on the wire, since a lost claim is silent by design (the Liberator's contended-tick precedent).

**A fixture trap.** `Phase5TestCase::makeWatcher()` passes no `advisorySchedule`, so it falls back to constructing one from the test PDO and clock. That is why `WatcherCardinalityJitterTest`'s three tests pass **unmodified** against the persisted schedule — which is the regression contract for ADR 0019's stampede semantics. If one of them ever needs editing, the semantics moved and that needs saying out loud rather than fixing the test.

**A test trap worth knowing before writing another one.** A Watcher-level test cannot prove the claim is exclusive: each tick reads then immediately claims, so a second Watcher's *read* already sees the first's advanced schedule and returns before `claimDue()` is called. Measured — a two-Watcher test stayed green with the dueness predicate deleted. `AdvisoryScheduleTest` therefore drives the repository directly for that assertion, with the two claims passing **different** next-due values: identical values would make MySQL's changed-rows behaviour supply the exclusion instead of the predicate, and that version stayed green under the neuter too.

## Provisioning is demand-driven as of ADR 0035

Two OR-composed triggers:

- **`unsatisfiable_demand`** — a slot family someone is waiting on has zero claimable indexed-and-free slots. Fires **regardless of threshold**; this is the starvation-freedom guarantee.
- **`low_capacity`** — global free ratio below threshold. Unchanged from Phase 5.

`$action` keeps its closed set `no_action | provisioned | lock_contention`; the reason lives in the separate closed `trigger` set `none | unsatisfiable_demand | low_capacity`, with `unsatisfiable_demand` taking precedence.

Indexed columns are sized `clamp(max(waiters − indexedFree, headroom), floor, familyCapacity)` for **every** family, where `floor` is one for a demanded family and zero otherwise. The floor exists because AC#1 binds the low-capacity path too, where the shortfall can be zero or negative.

`headroom` comes from an injected `IndexHeadroomPolicy` (ADR 0042), currently `FlatIndexHeadroom` carrying `Config::$pageIndexHeadroom`, default **4** — so a new page carries sixteen indexed columns rather than the one to three current demand justifies.

**Since ADR 0043 that number is also the page's capacity**, not just its index count: `PageProvisioner` creates exactly the columns it is given, so `k` sets how many slots a page has at all. A plan that names no column therefore describes a page with no inventory rows — one that adds nothing to the capacity totals, leaves the ratio that triggered it below threshold, and gets provisioned again every tick. `plan()` downgrades that to `no_action`, which is reachable only from the low-capacity trigger with no demand at `k = 0`; the demanded-family floor means a starved family always names a column, so the starvation-freedom guarantee is untouched. `ProvisioningPlannerTest::testZeroHeadroomWithNoDemandDeclinesRatherThanProvisionNothing` pins it, and `PageProvisioner::provision()` rejects an empty list independently.

**Why it is not sized to demand.** Indexing only what someone is waiting for was coherent while ADR 0003 let a non-filterable field hold a slot "for typed retrieval". ADR 0034 withdrew that, and with ADR 0004 requiring a filterable field's slot to be indexed, an unindexed slot column can no longer be occupied by anything — so a page's usable capacity is permanently whatever it indexed at birth, and ADR 0012 forbids widening it afterwards. Measured on MySQL 8.0.13, promoting three fields of one model *serially* therefore produced three pages, `free_ratio 0.9833`, and a state nothing could repair: compaction needs indexed free slots to absorb its moves and there were none, while the Watcher saw no starved family and reported `no_action` for ever. `Watcher/SerialPromotionSpreadTest` is that repro inverted, and it goes red at `k = 0` with "3 is identical to 1".

**A `low_capacity` page with no demand is no longer dead weight.** It used to carry zero indexes — sixty columns no reservation could claim. It now carries `4k` columns, all of them claimable, which is also what makes ADR 0032 affinity operative: affinity can only prefer a page holding an indexed free slot of the field's family, and demand-sizing guaranteed there was never one, so every reservation logged `affinity: fallback`.

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

One page serves all four families simultaneously, so the minimum is the largest per-family requirement. Summing the per-family minima is the natural-looking error and would make every multi-family model read as permanently fragmented. `SpreadSample`'s statics are public because ADR 0033's compaction planner needs the identical formula to pick a target page set.

### …and since ADR 0044 the per-family requirement is read off real pages

Until 0044 it was `ceil(count[f] / capacity[f])` where capacity came from `PageProvisioner::slotColumnsForType()` — 25/15/10/10. **That was a page's real capacity only while every page carried all sixty columns and all sixty were claimable**, which ADR 0034 ended and ADR 0043 finished. Measured at the default `k = 4`: five serially promoted `str` fields on two four-column pages reported `theoretical_min_pages: 1` against a true floor of 2, and nine fields fired `high_spread_model` at an optimally packed model while `compactModel()` refused it. ADR 0031's own Consequences predicted exactly this ("if a future ADR introduces heterogeneous page layouts, the min-pages formula must be revised in lockstep"), which is why no ADR had to be argued into existence — only written.

The requirement is now: per family, take the candidate pages roomiest-first and count how many it takes to cover `count[f]`. Three things about the input are load-bearing:

- **Candidates are the pages the model occupies**, matching ADR 0033's v1 restriction that compaction never migrates a model onto an untouched page. Free capacity elsewhere is unreachable, so counting it would report a floor no operation can deliver — `SpreadSample::fromLiveSlots()` intersects the free-capacity map against the model's own pages for exactly this reason.
- **A page's capacity is `indexed free + this model's own live slots there`** (`SpreadSample::hostableByPage()`) — what compaction can use, since fields already on a target page stay put. Do not substitute the page's total column count: a compaction could then finish cleanly and still leave `excess_pages > 0`, which is the criterion that makes the shared formula worth sharing.
- **Free capacity and hostable capacity are not interchangeable.** The floor counts `free + own`; `CompactionPlanner::assign()` spends `free` alone. Conflating them would let a relocation target a slot another field already holds.

Two consequences to keep in mind when reading a sample: **the number is time-varying** (another model claiming a slot on a shared page moves this model's floor), and **spread forced by `Config::$pageIndexHeadroom` reports zero excess** — `k + 1` fields of one family cannot share a `k`-column page, so those joins are structural and compaction is not the lever. A second `layout_min_pages` field was considered for that case and rejected; `pages_occupied` already carries the raw join count.

`collect()` therefore runs **two** queries, not one — the live-slot join, then `Slot\IndexedFreeCapacityReader` once per run (global, grouped by page, not per model). Both are registry-only, so the "safe over every model, every day" property is intact.

## `CardinalitySampler`

`sample()` is the Phase 5 24-h periodic scan over every live slot (`trigger='periodic'`), running the ADR 0019 normative aggregate per `(tenant, slot)`. Emits `cardinality_sampled` always, and `low_cardinality_index` when the selectivity or distinct floor is breached.

Phase 6b adds `sampleSlot(int $slotAssignmentId): void` — the single-slot variant called post-promotion by `RetypeBackfillWorkSource`, emitting `cardinality_sampled` with `trigger='post_backfill'`.

**Cardinality events carry `source: 'registry'`, not `'watcher'`**, per ADR 0020 line 49 — the Watcher merely owns the schedule.

### Three triggers since ADR 0052, deliberately mirroring `SpreadSampler`

`report(?int $tenantId, ?int $modelId): list<CardinalitySample>` is the third — `trigger='on_demand'`, backing `bin/stardust cardinality:report`, and the only one that returns its samples so the CLI prints without running the aggregates twice. It exists because the spread advisory had an on-demand CLI and the cardinality advisory did not, which left an operator no way to take a reading between scheduled runs.

**It is not the equivalent of `spread:report`, and the help text must not say it is.** `SpreadSampler` is registry-only and documented safe against production at any time; this one reads `COUNT(*)` / `COUNT(DISTINCT col)` off every matching extension page. Read-only, but not free.

**`--model` narrows which *slots* are sampled, not which *rows* are counted.** ADR 0019's aggregate is per `(tenant, slot)` across the tenant's whole partition on that page, because the index it describes is `(tenant_id, slot_column)` and a page is shared by every model with a slot on it. Narrowing the counts instead would measure something the optimizer never sees.

**`--tenant` must confirm the tenant is on the page, not assume it** — `tenantsPresentOn()` is a point lookup returning `[$id]` or `[]`, standing in for the `SELECT DISTINCT tenant_id` the unfiltered path runs. The first version took the flag's value on trust, which looks like a free optimisation and is not: a tenant with no rows on a page produced an invented sample at `row_count: 0, distinct_values: 0`, which then **tripped `low_cardinality_index` on the distinct floor** — so `cardinality:report --tenant=<mistyped id>` warned about every live slot in the deployment holding none of that tenant's data. Measured (`report(999)` emitted exactly one spurious warning per live slot) and pinned by `testATenantWithNoRowsOnThePageYieldsNoSampleAndNoWarning`. The lookup is still indexed — `tenant_id` leads every slot's composite index — so the filter keeps the scan it was there to avoid.

**The join to `stardust_fields` is a LEFT join and has to stay one.** `--model` needs `f.model_id`, but a tombstoned or grandfathered slot carries `field_id = NULL`, and an INNER join would silently shrink the unfiltered scan the periodic trigger has always covered — the sample would simply stop reporting orphaned slots, with nothing failing. `CardinalitySamplerTest::testAnOrphanedSlotIsStillSampledWithANullFieldId` pins it, and both filters were validated by neutering them.

## ADR 0038: `PendingDemandReader`'s safety is not a local property

`PendingDemandReader` gates on `f.is_filterable = 1 AND f.deleted_at IS NULL` and carries **no model predicate**. A model deletion marks every existing field, so those are excluded for free — but a field created *after* severance is not marked, and nothing about this query would stop it being read as demand.

What actually closes that door is `SchemaBuilder::insertField()`, which refuses to add a field to a model whose `deleted_at` is set. Measured on MySQL 8.0.13: without it the INSERT succeeds, this reader returns the new field, `UnmappedFieldReserver` reserves it a slot on the ADR 0007 exhaustion path, and the model purge's **final** chunk then fails errno 1451 — permanently, after earlier chunks have already destroyed their entries, with no dead-letter route out.

So the guard that protects this query lives in another package. Do not "simplify" `insertField()`'s model check away, and if this reader is ever refactored, keep the coupling in mind rather than assuming the predicate above is sufficient on its own.
