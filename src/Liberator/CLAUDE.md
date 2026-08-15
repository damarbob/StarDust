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

### Two deliberate omissions

**The sweep UPDATE omits a `tenant_id` predicate.** AC#3 is normative on `(page, slot_column) for id > cursor`, and `ux_slot_assignments_page_column UNIQUE (page_id, slot_column)` means at most one tenant ever had data in the column. ADR 0029 covers this.

**The reclaim carries a `WHERE status='tombstoned'` guard** for the rare operator-resurrect race: 0 rows affected → no reclaim → the next batch sees the updated state.

## `Liberator::tick()`

Generates one `correlation_id` (UUID v4), loads the batch, emits `sweep_started`, then sweeps each slot. **Idle ticks emit nothing**, per blueprint AC#13.
