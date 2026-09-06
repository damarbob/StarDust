# Liberator daemon

Phase 6a singleton slot reclamation (ADR 0008, ADR 0009). Three `final` collaborators, SOLID-decomposed.

Process-level singleton enforcement is the CLI's job: `PidFileGuard::acquire(pidFileDir, 'liberator', LiberatorSingletonViolationException::class)` in `bin/stardust liberator`.

## `TombstonedSlotRepository`

`loadBatch()` runs the registry SELECT `… WHERE status='tombstoned' ORDER BY tombstoned_at ASC, page_id, slot_column LIMIT N` — **no `FOR UPDATE`**, because the singleton guarantee makes claim contention impossible — and hydrates `TombstonedSlot` DTOs with the page's `table_name` joined in.

## `SlotSweeper`

`sweep(slot, correlationId)` owns the per-slot loop. Per chunk, in one transaction:

1. `SELECT entry_id FROM <table> WHERE entry_id > :cursor ORDER BY entry_id LIMIT N`
2. `UPDATE <table> SET <slotColumn> = NULL WHERE entry_id IN (...)`
3. A `sweep_cursor_id` advance.

On the final chunk (rows returned `< chunkSize`) the same tx flips `status='tombstoned' → 'free'` (with `field_id = NULL`) AND bumps `stardust_schema_version.version` per ADR 0017 §4.6.

### Deadlock handling and the gap path

On `SQLSTATE 40001` (InnoDB deadlock) it rolls back, emits `deadlock_retry`, sleeps `interChunkDelayMicros`, and retries from the same cursor. After `deadlockRetryBudget` consecutive deadlocks on the same chunk it takes the **gap path**: advances the cursor by `chunkSize`, increments `sweep_gap_count` on the registry row, emits `sweep_gap_flagged`, and continues.

### The cursor's lifecycle spans more than one sweep (ADR 0045)

`sweep()` opens with `$cursor = $slot->sweepCursorId ?? 0`, so where the sweep starts is entirely a question of who last wrote that column — and the sweeper is not that writer on the first chunk of a slot's *second* life.

**The two annotations are per-sweep, not per-column.** `sweep_cursor_id` and `sweep_gap_count` are cleared by the UPDATE that flips a slot to `tombstoned` (`Slot\LiveSlotTombstoner`, and the identical batch step in `Delete\ModelPurgeWorkSource`), and **preserved by the reclaim here** — so a slot sitting at `free`, or already re-reserved by another field, still shows the annotations of the sweep that emptied it. That is what they are for; an operator reads them after the fact.

Until ADR 0045 nothing reset them at all. A slot survives `tombstoned → free → assigned → tombstoned` as one registry row, so a recycled column's second sweep began at the first occupant's final cursor, walked an empty range, and returned to `free` with the previous field's values intact — **`sweep_complete` and every `sweep_chunk` honest for the range they walked, and nothing in the event stream to see.** Measured on 8.0.13 over a promote/demote/promote/demote cycle: twenty of twenty rows survived into `free`. `tests/Smoke/Liberator/RecycledSlotSweepTest` is the regression, driven through the facade because `Phase6aTestCase::tombstoneSlotAssignment()` now mirrors the reset and so cannot reproduce it.

**The reset does not make a reclaimed slot unconditionally empty, and the gap path above is why.** An abandoned chunk is skipped, the sweep still runs on to `isLast`, and the reclaim fires with that range still populated — `LiberatorDeadlockRetryTest::testThreeConsecutiveDeadlocksTriggersSweepGap` asserts `status = 'free'` and ten surviving rows in the same fixture, and has since Phase 6a. ADR 0045 is scoped to a sweep that completed without a gap; the residual route is ROADMAP item 24, and it is a question for ADR 0009's gap policy rather than for this reset. The one thing 0045 does contribute there is attribution: because `sweep_gap_count` now resets per sweep instead of accumulating across occupants, a non-zero value on a live slot reads as "this column's contents may include residue from the sweep before them".

Two things follow for anyone editing this file. **Do not move the reset into the reclaim** — it fires at the moment the annotations are most worth reading, and the older comment proposing `SlotReserver` as the reset point was withdrawn by 0045 for failing open. And **do not make `sweep()` ignore the stored cursor**: idempotent nullification would make that safe, but it deletes the crash-resumption property AC#9 requires and `LiberatorResumeTest` pins.

### Two deliberate omissions

**The sweep UPDATE omits a `tenant_id` predicate.** AC#3 is normative on `(page, slot_column) for id > cursor`, and `ux_slot_assignments_page_column UNIQUE (page_id, slot_column)` means at most one tenant ever had data in the column. ADR 0029 covers this.

**The reclaim carries a `WHERE status='tombstoned'` guard** for the rare operator-resurrect race: 0 rows affected → no reclaim → the next batch sees the updated state.

## `Liberator::tick()`

Generates one `correlation_id` (UUID v4), loads the batch, emits `sweep_started`, then sweeps each slot. **Idle ticks emit nothing**, per blueprint AC#13.
