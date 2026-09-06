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

## Six things that are load-bearing

**Planning refuses while a field of the model is mid-lifecycle (ADR 0039).** `CompactionService::plan()` raises `RetypeInProgressException` when `RetypeCheckpointRepository::existsRunningForAnyFieldOfModel()` is true. A field mid-relocation holds a `tombstoned` old slot and a `backfilling` new one, so the population below counts neither and the planner cannot see where it is going to land — measured, that produced `pages_after: 1` against a true answer of 2, with `spread:report` still showing `excess_pages: 1`. **The guard is on `plan()`, so `--dry-run` is refused too**, matching the ADR 0038 deleting-model guard directly above it and for the same reason: a dry run exists to report numbers. It is checkpoint-keyed rather than compaction-keyed, so an ordinary `promoteFieldToFilterable()` trips it as well — the planner is exactly as blind to that. It is **not a lock**: two operators can still both pass it between one's `plan()` and its first `initiateRelocation()`, and the per-field guard catches the real collision. Note this narrows ADR 0033's "resume is re-run" to "re-run once the window closes".

**The population is ADR 0031's, deliberately.** `status IN ('assigned','ready')` + `is_filterable = 1`, the identical predicate pair `SpreadSampler` uses. Not a coincidence to be tidied: it is what makes `excess_pages → 0` a real success criterion instead of two subsystems agreeing by luck. `theoretical_min_pages` is reused from `SpreadSample` for the same reason — reimplementing it lets a compaction and the metric verifying it disagree — and since ADR 0044 so is its *input*, the per-page capacity both derive the floor from.

**Free capacity counts only *indexed* free slots.** A relocated field stays filterable, so the pinned reservation passes `requireIndexed: true` (ADR 0016 commitment 1 / ADR 0004). A planner counting unindexed free slots would build plans the reservation then refuses, turning a clean up-front `CompactionCapacityException` into a mid-flight failure. The query lives in `Slot\IndexedFreeCapacityReader`, which `SpreadSampler` now shares; `CompactionRepository::loadIndexedFreeCapacity()` delegates to it. Uses the shared `IndexedSlotPredicate`.

**The floor counts `free + own`; assignment spends `free` alone (ADR 0044).** The two capacities look interchangeable and are not. `theoreticalMinPages()` asks what each candidate page *could hold for this model* — its indexed free slots plus the slots the model already occupies there, since those fields stay put — while `assign()` may only place a move into a slot that is `free`. Passing the hostable map to `assign()` would relocate a field onto a slot another field already holds. Two consequences of the corrected floor are worth not re-deriving: **a model with no free capacity anywhere is at its floor, not inadmissible** (page capacity then equals what the model already holds, so covering the counts takes every page), which is why `CompactionCapacityException` is now rare and reachable mainly through families that each fit on one page but disagree about which; and the exception no longer advises Watcher provisioning, because `rankCandidates()` draws only from `pagesOccupied` and a new page is never a candidate.

**Double-occupancy is handled by construction, not by a correction term.** Capacity is counted from rows that are `free` *now*. A slot this plan is about to vacate becomes `tombstoned` and only returns after the Liberator sweeps it (ADR 0009), so the arithmetic can never spend capacity the operation is about to release. This is the trap ADR 0033 calls out, and it is why compaction needs headroom exactly when pages are fragmented.

**Candidate pages are limited to those the model already occupies.** A deliberate v1 restriction: compaction *consolidates*, never migrating a model onto a page it has never touched. Bounds the blast radius and guarantees the search terminates, since the current layout is always feasible. The cost is that a large empty page elsewhere is not considered. Widening it needs a policy for how aggressively compaction may claim shared capacity.

## The operation id threads all the way down

`compact()` has minted a `correlation_id` at its boundary since ADR 0033, and it was the engine's model for what a `registry`-source id should be — but it only ever covered its own two events. It now goes into `RetypeInitiator::initiateRelocation()` as well, so each relocation's `retype_started` and `slot_reserved` carry it too, and the whole compaction is one joinable operation three levels deep.

This is the widest span the engine has, and the reason it is worth having: a compaction is one thing an operator did, and without the threading each relocation reads in the log as an unrelated retype that happened to occur nearby. Read `src/Logging/CLAUDE.md` before concluding a missing id would have been visible — it would not have been, and that is the whole point of `RegistryCorrelationTest`.

Note the id does **not** reach `promote_to_ready` by this route. It gets there through `backfill_checkpoints.correlation_id`, which the relocation's own checkpoint carries — so the drain that completes a relocation joins the compaction as well, across the process boundary.

## Pin-or-fail, not defer

The one deliberate divergence from ADR 0016 commitment 4. `RetypeInitiator::initiateRelocation()` reserves on the planner's page via `SlotReserver::reserveForBackfillOnPageWithinTransaction()`, and a miss **throws inside the transaction** so the whole tuple rolls back — old slot un-tombstoned, no checkpoint, no version bump. A relocation that fails *at initiation* therefore leaves nothing to unpick.

**That is a statement about initiation only, and it used to be over-claimed here as "which is what makes 're-run to replan' safe advice" (corrected 2026-08-27).** It does not extend to a relocation that got *past* initiation: that one holds a `tombstoned` old slot, a `backfilling` new one and a `running` checkpoint, none of which the planner's population counts — so re-running mid-drain used to replan around a field it could not see. ADR 0039 closes that by refusing rather than by widening the population; see below.

Deferring instead would let the work source later reserve on whatever page it picks, silently producing a compaction that does not compact. Stage C measured why that bites hardest here: relocating a model's **last field off a page** empties its affine set, so an unpinned reservation falls back to global-oldest exactly when placement matters most.

## Sequential, and why

One field in flight. During a field's relocation window its filters are rejected (ADR 0004 / ADR 0016) while reads fall back to the JSON payload (ADR 0013) — relocating K fields at once would reject filters on all K simultaneously. `--parallel=N` is described by ADR 0033 as an explicit opt-in and is **not implemented yet**; the CLI surface stays forward-compatible.

`CompactionService` blocks on `RetypeCheckpointRepository::statusForField()`, which exists because `existsRunningForField()` is a bool and cannot separate `completed` from a field with no checkpoint at all. The poll budget turns a stopped Reconciler into a clear error instead of a hang.

**There is no longer a `failed` branch, and that is deliberate (2026-08-27).** One used to sit here, aborting the run and telling the operator to inspect the reconciler DLQ — advice for a state nothing in `src/` could produce, because `RetypeCheckpointRepository::markFailed()` had no caller anywhere. Both were removed rather than wired together: a relocation's realistic stall is lock contention, which the retype work source now retries and then defers as `TickOutcome::LOCK_WAIT`, leaving the checkpoint `running` and drainable. Failing it would convert a self-clearing condition into one needing operator action. A relocation that genuinely stops progressing surfaces through the poll budget, same as a Reconciler that is not running — and the remedy is the same in both cases.

## A trap worth knowing before reading the tests

After a successful compaction the model's fields sit in **two different statuses, both correct**: a relocated field was promoted to `ready`, while a field that was already on the target page is a no-op and stays `assigned`, never having moved. Asserting `ready` for all of them asserts that compaction pointlessly rewrote a field that was already home.
