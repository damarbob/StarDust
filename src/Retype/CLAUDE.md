# Retype pipeline

Phase 6b field retype + filterability promotion (ADR 0016, ADR 0024). Five `final` collaborators, SOLID-decomposed, mirroring Phase 6a's Liberator shape.

## `RetypeInitiator`

`initiate(tenantId, fieldId, ?newDeclaredType, ?newIsFilterable)` runs the atomic registry transaction. Guards first, all before any mutation:

- Validates the field exists and belongs to the tenant.
- Rejects ADR 0024 categorical retypes (`int↔datetime`, `numeric↔datetime`) with `IncompatibleRetypeException`.
- Refuses overlapping retypes via `RetypeCheckpointRepository::existsRunningForField()` with `RetypeInProgressException`.

**The field read and all four guards run inside the transaction, not ahead of it**, because `loadField()` takes `FOR UPDATE OF f` and that lock is only worth anything while a transaction holds it — in autocommit it would be dropped the instant the SELECT finished. Same structural reason `RenameInitiator::assertNameAvailable()` sits inside its caller's transaction. "Before any mutation" still holds: nothing writes until step 1, so a guard that throws rolls back an empty transaction.

`OF f` and not a bare `FOR UPDATE`: the statement joins `stardust_models` only to resolve the tenant, and locking that row too would contend with `deleteModel()` for nothing. Measured on MySQL 8.0.13 — the `OF` clause parses, a concurrent `UPDATE stardust_models` on the joined row proceeds untouched, and a second initiator's identical SELECT serialises.

### Two triggers, three shapes

The *trigger* decides what changes on `stardust_fields`. Whether the **target** is filterable decides whether there is any backfill at all — because under ADR 0034 only a filterable field may hold a slot.

**Filterable target** (retype of a filterable field, or a `false → true` promotion) — the full tuple in one tx:

1. Update `stardust_fields`.
2. Tombstone the current live slot if any (`assigned/backfilling/ready → tombstoned`, `field_id = NULL`).
3. Reserve a new `backfilling` slot via `SlotReserver::reserveForBackfillWithinTransaction()` with `requireIndexed: true` — now a literal, since the call site is unreachable for a non-filterable field. Or defer if no matching indexed free slot exists; per ADR 0016 commitment 4 there is no eager DDL.
4. Bump `stardust_schema_version`.
5. Open a `running` `backfill_checkpoints` row with `job_name = 'retype_field_{id}'` plus `source_declared_type`, so the work source can pick the right matrix cell after the field's `declared_type` has been overwritten. An upsert, not an INSERT — see "A field is not a one-way door" below.

**Non-filterable target** (retype of a JSON-only field, or a `true → false` demotion) — **registry-only**: update, tombstone a grandfathered legacy slot if one exists, bump, stop. No reservation, no checkpoint, nothing for the Reconciler to claim. The JSON payload is authoritative per ADR 0013, and on demotion reads fall straight back to `JSON_EXTRACT`.

Under ADR 0034 a promotion normally has *no* old slot to tombstone; the tombstoner already returned `null` cleanly for that case, so no new code was needed.

**Step 2 now delegates to `Slot\LiveSlotTombstoner`** rather than a private method. ADR 0037's `DeleteFieldInitiator` needs the identical two-step sequence, and duplicating twenty lines of index- and FK-defending SQL is exactly the thing that drifts. Behaviour is unchanged; the ordering rationale moved into that class's docblock, where it also records the consequence this package never needed — nulling `field_id` first releases the `RESTRICT` foreign key inside the transaction, which is what lets a field deletion drop the registry row without waiting on a Liberator sweep.

### Relocations are model-affine, with one shape that is not (ADR 0032)

The replacement reservation goes through the same chokepoint as every other, so it prefers a page already hosting a **live** slot of the same model. That matters here more than anywhere else: ADR 0016 re-rolls page placement on *every* retype and promotion, which is the main mechanism by which a model scatters.

**But a model's *only* field loses affinity on relocation.** Step 2 tombstones the current slot in the same transaction that step 3 reserves the replacement, so by then the model has no live slot anywhere, the affine set is empty, and the replacement lands on global-oldest — possibly a different page. Harmless in isolation (a one-field model occupies one page either way, so `excess_pages` stays 0), but it means **a relocation is not guaranteed to be page-stable**. ADR 0033 compaction pins its target page explicitly rather than trusting affinity, for exactly this reason. Pinned by `SlotAffinityTest::testSingleFieldModelLosesAffinityOnRelocation`.

### Exactly one schema-version bump on every branch

`SlotReserver::reserveCore()` bumps on its success path only, and the initiator's `if ($newSlot === null)` compensating bump covers both the deferred reservation and the registry-only transition. Check this invariant if you add a fourth shape.

### Events

Emits `retype_started` post-commit carrying `backfill_required` — false means the lifecycle started and finished in that one transaction, so a missing later `promote_to_ready` is not a stall. `deferred_assignment` is guarded on `backfill_required`: a registry-only transition always leaves `$newSlot` null but is *complete*, not deferred, and reporting it as deferred would show operators permanent phantom backlog.

### `initiateRelocation()` — the ADR 0033 sibling

`initiateRelocation(tenantId, fieldId, pinnedPageId)` runs the same tuple as `initiate()` (both delegate to a shared private `runTuple()`), with two differences that are the entire reason it exists:

- **The reservation is page-pinned**, via `SlotReserver::reserveForBackfillOnPageWithinTransaction()`.
- **Pin-or-fail, not defer.** Where `initiate()` treats an unavailable slot as a deferral for the work source to retry, this throws `CompactionCapacityException` *inside* the transaction, so the catch rolls the whole tuple back.

It passes **both type arguments as `null`** on purpose. Step 1 skips the `stardust_fields` UPDATE entirely when neither is supplied, so a relocation leaves the field row genuinely untouched — passing the current type explicitly would issue a no-op UPDATE that still moved `updated_at` on every compacted field.

`statusForField()` on the checkpoint repository exists for the same operation: `existsRunningForField()` is a bool and cannot separate `completed` from a field that has no checkpoint at all. It used to also have to report `failed` — `markFailed()` and the compaction branch that consumed it were both removed in 2026-08-27, because nothing in `src/` ever called it and a relocation's realistic stall is lock contention, which is now retried and then deferred as `LOCK_WAIT`, leaving the checkpoint `running` and drainable.

## `RetypeCheckpointRepository`

Encapsulates all SQL against `backfill_checkpoints` rows whose `job_name LIKE 'retype_field_%'`. `loadOneClaimable()` JOINs `stardust_fields ⨝ stardust_models` to hydrate the partition tuple `(tenant_id, model_id, fieldName, sourceDeclaredType, targetDeclaredType, targetIsFilterable)`.

## `RetypeCoercionEngine`

`attempt(value, valuePresent, from, to): CoercionOutcome` is the pure-static ADR 0024 matrix, with three states:

- `Coerced(value)`
- `NotAttempted` — JSON key absent OR value is JSON `null`. No event.
- `NullCoerced(reason)` — attempted but failed, with the closed taxonomy `out_of_range | non_integer | malformed_datetime | malformed_number | epoch_coercion_rejected | unparseable`.

Covered by the DB-free `tests/Smoke/Retype/RetypeCoercionMatrixTest`.

## `RetypeBackfillExecutor`

`processChunk()` SELECTs `id, fields FROM entry_data WHERE tenant_id=? AND model_id=? AND id > :cursor LIMIT N`, runs the coercion engine per row, calls `SlotRowUpserter::upsert()` (the Phase 3 helper, reused verbatim), and collects `CoercionNullEvent`s for post-commit emission.

## `RetypeBackfillWorkSource`

Implements `ReconcilerWorkSource` — the third work source in the Reconciler's round-robin. Per tick:

1. Claims one running checkpoint via `FOR UPDATE SKIP LOCKED LIMIT 1`.
2. Hydrates the live `backfilling`/`ready` slot, or attempts a deferred reservation via `SlotReserver::reserveForBackfillWithinTransaction()` — returns `CAPACITY_WAIT` when there is no capacity.
3. Processes one chunk and advances the cursor.
4. On `isFinalChunk`, flips slot `backfilling → ready`, marks the checkpoint `completed`, and bumps `stardust_schema_version` **in the same tx**.

Post-commit it emits per-row `coercion_null`, `chunk_complete`, and on promotion `promote_to_ready` plus two one-shot advisory samples: `CardinalitySampler::sampleSlot()` (ADR 0019, `trigger=post_backfill`) and `SpreadSampler::sampleModel()` (ADR 0031, `trigger=post_relocation`).

The spread one-shot fires **at promotion, not at initiation**. A retype vacates one slot and claims another, so it can land the model on a page it did not previously occupy — but the relocation is only real once the new slot reaches `ready`, and publishing a spread delta while the field is still `backfilling` would report a move that no query can yet see. `RetypeInitiator`'s registry-only path (a non-filterable target, or a demotion) deliberately does **not** sample: it tombstones without replacing, and the next periodic sample covers it. ADR 0031 accepts that latency explicitly.

**There is no DLQ path here.** Coercion failures are silent-NULL with audit events; the JSON payload remains authoritative per ADR 0013.

**Lock failures are retried, then deferred.** `tickOne()` wraps `attemptOne()` in a budget of `Config::$reconcilerLockRetryBudget` on `Support\RetryableLockFailure`; exhaustion emits `lock_wait` and returns `TickOutcome::LOCK_WAIT` instead of letting the `PDOException` reach `PollLoop`, which does not catch. Safe because the checkpoint cursor only advances on commit, so a rolled-back chunk is retried identically next tick. Shared rationale: `src/Reconciler/CLAUDE.md`.

## ADR 0036: the rename cross-guard lives in `runTuple()`

`runTuple()` now rejects any shape — retype, promotion, demotion, or relocation — against a field with a running `rename_field_{id}` checkpoint, throwing `RenameInProgressException`.

**Placing it here rather than on the `StarDust` facade is the whole point.** `initiateRelocation()` shares `runTuple()`, so `compactModel()` inherits the guard; a facade-level check on `retypeField()` would have left compaction as an unguarded back door.

It is a correctness guard, not hygiene. `RetypeBackfillExecutor` locates values with `array_key_exists($fieldName, $fields)` against the *current* name, so mid-rename every row behind the rename cursor reads as "value absent" → `NotAttempted` → the slot is written NULL. Silently: the `isNullCoerced()` guard means no `coercion_null` event fires, because no coercion was attempted. That is permanent data loss into the index with nothing in the log to show for it.

Every initiator checks the same repositories in the same order — since ADR 0037, **rename → retype → delete** — so two concurrent initiators cannot each see the other's row as absent. `ux_backfill_job_name` remains the real backstop.

`RetypeInitiator`'s constructor gained a `RenameCheckpointRepository` parameter, which ripples to `StarDust::retypeInitiator()` and `Phase6bTestCase::makeRetypeInitiator()`.

**The ADR 0037 delete guard is in `loadField()`, not `runTuple()`'s guard block.** It keys on `stardust_fields.deleted_at` rather than a checkpoint row, so it rides a SELECT this class already runs and still fires for a field whose purge checkpoint was manually failed. It reaches every shape — retype, promotion, demotion, relocation — because `loadField()` is `runTuple()`'s first call, so `compactModel()` inherits it for free.

## A field is not a one-way door: `insertOrReset()`

`ux_backfill_job_name` is UNIQUE on `job_name` and nothing removes a *retype* checkpoint — ADR 0037's `src/Delete/` clears terminal sibling rows, but only when the field itself is being deleted. So while this repository used a plain INSERT, the **second** filterable-target lifecycle for a field died on a raw `PDOException` (errno 1062) once the first had completed, and `existsRunningForField()` offered no protection because it reports `false` for a terminal row. Every shape in `runTuple()` with a filterable target lands there, so a field got one retype, promotion or relocation each, *ever*. Reachable from three plain facade calls — `promoteFieldToFilterable` → `demoteFieldFromFilterable` → `promoteFieldToFilterable` — with no compaction involved; that is also why `compactModel()`'s documented "safe to re-run" did not hold for an already-relocated field.

It is now an upsert, matching `RenameCheckpointRepository::insertOrReset()` and both delete repositories — this was the last of the four `backfill_checkpoints` namespaces still doing a plain INSERT.

**Two things about it that are not in the siblings:**

- **`source_declared_type` is reset with the rest of the row.** No sibling repository has that column, so porting their `ON DUPLICATE KEY UPDATE` verbatim leaves the second lifecycle draining against the *first* one's source type — the wrong ADR 0024 matrix cell, with no event and no exception. Pinned by `RetypeInitiatorTest::testResetCheckpointCarriesTheNewSourceDeclaredType`, validated by neutering.
- **Only terminal rows are relaxed.** A genuinely `running` checkpoint still raises `RetypeInProgressException` from the caller's pre-check, which is why that pre-check now runs under the `FOR UPDATE OF f` lock above. The upsert is what makes that lock load-bearing: without it two initiators could each read `declared_type` from their own snapshot and the loser would silently reset the winner's live checkpoint.

**Why the concurrency test asserts on an incompatible retype.** The obvious version — hold the field row from a sibling session, run a normal retype, expect a lock-wait timeout — cannot fail. Measured on 8.0.13: the reservation's `UPDATE stardust_slot_assignments SET field_id = ?` takes an FK shared lock on the parent `stardust_fields` row via `fk_slot_assignments_field`, so a sibling's `FOR UPDATE` blocks any filterable-target initiation at step 3 whether or not `loadField()` locks anything. An ADR 0024 categorical rejection is the one shape that resolves *between* the two points — it throws straight after the read and never reaches the reservation — so the two cases surface as different exception types. `RetypeInitiatorConcurrencyTest` documents this at length; do not "simplify" it back to a timeout assertion.
