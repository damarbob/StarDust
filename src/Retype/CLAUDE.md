# Retype pipeline

Phase 6b field retype + filterability promotion (ADR 0016, ADR 0024). Five `final` collaborators, SOLID-decomposed, mirroring Phase 6a's Liberator shape.

## `RetypeInitiator`

`initiate(tenantId, fieldId, ?newDeclaredType, ?newIsFilterable)` runs the atomic registry transaction. Up-front guards, all before any mutation:

- Validates the field exists and belongs to the tenant.
- Rejects ADR 0024 categorical retypes (`int↔datetime`, `numeric↔datetime`) with `IncompatibleRetypeException`.
- Refuses overlapping retypes via `RetypeCheckpointRepository::existsRunningForField()` with `RetypeInProgressException`.

### Two triggers, three shapes

The *trigger* decides what changes on `stardust_fields`. Whether the **target** is filterable decides whether there is any backfill at all — because under ADR 0034 only a filterable field may hold a slot.

**Filterable target** (retype of a filterable field, or a `false → true` promotion) — the full tuple in one tx:

1. Update `stardust_fields`.
2. Tombstone the current live slot if any (`assigned/backfilling/ready → tombstoned`, `field_id = NULL`).
3. Reserve a new `backfilling` slot via `SlotReserver::reserveForBackfillWithinTransaction()` with `requireIndexed: true` — now a literal, since the call site is unreachable for a non-filterable field. Or defer if no matching indexed free slot exists; per ADR 0016 commitment 4 there is no eager DDL.
4. Bump `stardust_schema_version`.
5. Insert a `running` `backfill_checkpoints` row with `job_name = 'retype_field_{id}'` plus `source_declared_type`, so the work source can pick the right matrix cell after the field's `declared_type` has been overwritten.

**Non-filterable target** (retype of a JSON-only field, or a `true → false` demotion) — **registry-only**: update, tombstone a grandfathered legacy slot if one exists, bump, stop. No reservation, no checkpoint, nothing for the Reconciler to claim. The JSON payload is authoritative per ADR 0013, and on demotion reads fall straight back to `JSON_EXTRACT`.

Under ADR 0034 a promotion normally has *no* old slot to tombstone; `tombstoneLiveSlot()` already returned `null` cleanly for that case, so no new code was needed.

### Exactly one schema-version bump on every branch

`SlotReserver::reserveCore()` bumps on its success path only, and the initiator's `if ($newSlot === null)` compensating bump covers both the deferred reservation and the registry-only transition. Check this invariant if you add a fourth shape.

### Events

Emits `retype_started` post-commit carrying `backfill_required` — false means the lifecycle started and finished in that one transaction, so a missing later `promote_to_ready` is not a stall. `deferred_assignment` is guarded on `backfill_required`: a registry-only transition always leaves `$newSlot` null but is *complete*, not deferred, and reporting it as deferred would show operators permanent phantom backlog.

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

Post-commit it emits per-row `coercion_null`, `chunk_complete`, and on promotion `promote_to_ready` plus a `CardinalitySampler::sampleSlot()` call for the one-shot post-backfill cardinality event.

**There is no DLQ path here.** Coercion failures are silent-NULL with audit events; the JSON payload remains authoritative per ADR 0013.
