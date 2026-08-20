# Model compaction

ADR 0033 operator-initiated model compaction — the *cure* for a model that is already spread. ADR 0031 measures spread, ADR 0032 prevents new spread, and neither converges an existing mess: the metric only observes and affinity is forward-only.

**Never scheduled, never daemon-triggered, never automatic.** The trigger is always an operator reading `high_spread_model` and deciding this specific model is worth a data migration. ADR 0031 rejected timer-driven repacking and that rejection stands.

## Thin orchestration over shipped machinery

Compaction relocates a model's live filterable slots onto a minimal page set as a **sequence of same-type retypes**. The ADR 0024 coercion matrix short-circuits its identity diagonal, so a same-type retype is mechanically nothing but a slot move with a data copy — `RetypeBackfillWorkSource` drains the resulting checkpoints **unmodified**, and the chunked backfill, `backfilling → ready` promotion, schema-version bumps, ADR 0019 cardinality sample and ADR 0031 `post_relocation` spread sample all fire per existing spec.

There is **no compaction daemon, no work source, and no compaction state table.** The retype checkpoints are the durable state. That is what makes resume-is-re-run true.

Locked by `tests/Smoke/Retype/SameTypeRelocationTest.php` — the premise everything here rests on, covering all four families' identity diagonals.

## The pieces

- `CompactionRepository` — two registry reads. **Registry-only**; it never touches `entry_data` or an extension page, so planning and `--dry-run` are safe against production at any time.
- `CompactionPlanner` — **pure**. Takes the loaded projection, returns a `CompactionPlan`; no connection, no locks, no mutation. That split is what makes the whole policy DB-free testable.
- `CompactionService` — initiates one relocation at a time and waits, emitting `compaction_planned` / `compaction_complete`.
- `ModelSlot` / `FieldRelocation` / `CompactionPlan` — DTOs.

## Four things that are load-bearing

**The population is ADR 0031's, deliberately.** `status IN ('assigned','ready')` + `is_filterable = 1`, the identical predicate pair `SpreadSampler` uses. Not a coincidence to be tidied: it is what makes `excess_pages → 0` a real success criterion instead of two subsystems agreeing by luck. `theoretical_min_pages` is reused from `SpreadSample` for the same reason — reimplementing it lets a compaction and the metric verifying it disagree.

**Free capacity counts only *indexed* free slots.** A relocated field stays filterable, so the pinned reservation passes `requireIndexed: true` (ADR 0016 commitment 1 / ADR 0004). A planner counting unindexed free slots would build plans the reservation then refuses, turning a clean up-front `CompactionCapacityException` into a mid-flight failure. Uses the shared `IndexedSlotPredicate`.

**Double-occupancy is handled by construction, not by a correction term.** Capacity is counted from rows that are `free` *now*. A slot this plan is about to vacate becomes `tombstoned` and only returns after the Liberator sweeps it (ADR 0009), so the arithmetic can never spend capacity the operation is about to release. This is the trap ADR 0033 calls out, and it is why compaction needs headroom exactly when pages are fragmented.

**Candidate pages are limited to those the model already occupies.** A deliberate v1 restriction: compaction *consolidates*, never migrating a model onto a page it has never touched. Bounds the blast radius and guarantees the search terminates, since the current layout is always feasible. The cost is that a large empty page elsewhere is not considered. Widening it needs a policy for how aggressively compaction may claim shared capacity.

## Pin-or-fail, not defer

The one deliberate divergence from ADR 0016 commitment 4. `RetypeInitiator::initiateRelocation()` reserves on the planner's page via `SlotReserver::reserveForBackfillOnPageWithinTransaction()`, and a miss **throws inside the transaction** so the whole tuple rolls back — old slot un-tombstoned, no checkpoint, no version bump. A failed relocation leaves nothing to unpick, which is what makes "re-run to replan" safe advice.

Deferring instead would let the work source later reserve on whatever page it picks, silently producing a compaction that does not compact. Stage C measured why that bites hardest here: relocating a model's **last field off a page** empties its affine set, so an unpinned reservation falls back to global-oldest exactly when placement matters most.

## Sequential, and why

One field in flight. During a field's relocation window its filters are rejected (ADR 0004 / ADR 0016) while reads fall back to the JSON payload (ADR 0013) — relocating K fields at once would reject filters on all K simultaneously. `--parallel=N` is described by ADR 0033 as an explicit opt-in and is **not implemented yet**; the CLI surface stays forward-compatible.

`CompactionService` blocks on `RetypeCheckpointRepository::statusForField()`, which exists because `existsRunningForField()` returns `false` for both `completed` and `failed` — an orchestrator that cannot tell them apart marches past a failed relocation and reports success on a compaction that left a field behind. A `failed` checkpoint aborts the run; the poll budget turns a stopped Reconciler into a clear error instead of a hang.

## A trap worth knowing before reading the tests

After a successful compaction the model's fields sit in **two different statuses, both correct**: a relocated field was promoted to `ready`, while a field that was already on the target page is a no-op and stays `assigned`, never having moved. Asserting `ready` for all of them asserts that compaction pointlessly rewrote a field that was already home.
