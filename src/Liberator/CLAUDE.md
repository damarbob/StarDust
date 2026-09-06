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

On `SQLSTATE 40001` (InnoDB deadlock) it rolls back, emits `deadlock_retry`, sleeps `interChunkDelayMicros`, and retries from the same cursor. After `deadlockRetryBudget` consecutive deadlocks on the same chunk it takes the **gap path**: advances past the chunk, increments `sweep_gap_count`, emits `sweep_gap_flagged`, and continues.

**Two things about that path were wrong until ADR 0046, and both were the code diverging from ADR 0009 rather than 0009 accepting a risk.**

*The gap advanced by a span of ids.* `$cursor + $chunkSize` is arithmetic on `entry_id`, where 0009 says skip ahead by `LIMIT` **rows**; the two coincide only where the page's ids are dense from the cursor up. On a sparse page it under-advanced, re-selected the same poisoned rows, and burned the whole budget again per `chunkSize` of id space crossed. Measured on 8.0.13 across one `AUTO_INCREMENT` jump: **101 gap cycles where two chunks were actually skipped**, and a `sweep_gap_count` of 101 to match. It is now `max($newCursor, $cursor + $this->chunkSize)` — the chunk's own last id. **The `max()` is conservative, not load-bearing**: on a full chunk it always picks `$newCursor`, and it differs only on a partial or empty chunk, which is the last one by definition. It buys "a gap never advances by less than the old behaviour did" and nothing more — in particular it does *not* close a spin on an empty chunk, which is how it was first justified here. An empty chunk is now handled by not taking a gap at all (below), so the question is moot; when the justification was written, persistent contention there escaped through the since-deleted `commitGap()` and killed the daemon rather than looping.

*A gapped sweep reclaimed.* It ran on to `isLast` and performed the ordinary `tombstoned → free` transition, which 0009 step 4 permits only once the slot is *confirmed 100% nullified*. Measured: `free` with ten of the previous field's rows, then `SlotReserver` handing that same slot to the next field. **A sweep that abandoned any chunk now does not reclaim** — the final chunk commits its nullification, rewinds `sweep_cursor_id` to the *first* gap's cursor, and leaves the slot tombstoned for the next cycle to re-walk. **No `sweep_complete` fires**, which is AC#11 as written (one per slot transitioned to `free`), not a hole in the event stream.

**Two further things the first cut of ADR 0046 got wrong, fixed in the same ADR (ROADMAP item 25).** *The no-reclaim decision did not survive a restart.* It lives in a local, and the durable cursor was rewound only on the final chunk — so every intermediate commit after a gap sat past the skipped rows, and a daemon killed in that window restarted beyond them, saw no gap of its own, and reclaimed over residue. The cursor is now **pinned at the first gap on every chunk**, so the registry never points past something unswept. *And the gap annotation was its own unguarded transaction.* `commitGap()` rethrew, from inside the retry `catch`, so a lock failure there escaped `sweep()` and killed the daemon; the count now rides the next chunk's registry UPDATE, which is already inside the budget. An empty chunk no longer takes a gap at all — there is nothing to skip, so the pass returns and waits for the next cycle, which also removes a non-terminating loop.

The explicit trade: a slot under sustained contention keeps its capacity squatted instead of being reclaimed with a hole. Squatting is visible — **tombstone depth and age**, which catch every case — and self-heals when contention subsides; bleeding is silent and unrecoverable. `sweep_gap_count` is a supplementary signal only: it rides the next successful chunk commit, so a sweep that commits nothing at all leaves it at zero, and the worst-stuck slot is the one it cannot find. **Do not "fix" a stuck tombstone by making the gap path reclaim again.**

### The cursor's lifecycle spans more than one sweep (ADR 0045)

`sweep()` opens with `$cursor = $slot->sweepCursorId ?? 0`, so where the sweep starts is entirely a question of who last wrote that column — and the sweeper is not that writer on the first chunk of a slot's *second* life.

**The two annotations are per-sweep, not per-column.** `sweep_cursor_id` and `sweep_gap_count` are cleared by the UPDATE that flips a slot to `tombstoned` (`Slot\LiveSlotTombstoner`, and the identical batch step in `Delete\ModelPurgeWorkSource`), and **preserved by the reclaim here** — so a slot sitting at `free`, or already re-reserved by another field, still shows the annotations of the sweep that emptied it. That is what they are for; an operator reads them after the fact.

Until ADR 0045 nothing reset them at all. A slot survives `tombstoned → free → assigned → tombstoned` as one registry row, so a recycled column's second sweep began at the first occupant's final cursor, walked an empty range, and returned to `free` with the previous field's values intact — **`sweep_complete` and every `sweep_chunk` honest for the range they walked, and nothing in the event stream to see.** Measured on 8.0.13 over a promote/demote/promote/demote cycle: twenty of twenty rows survived into `free`. `tests/Smoke/Liberator/RecycledSlotSweepTest` is the regression, driven through the facade because `Phase6aTestCase::tombstoneSlotAssignment()` now mirrors the reset and so cannot reproduce it.

**ADR 0045 alone did not make a reclaimed slot unconditionally empty — the gap path was the other route, and ADR 0046 closed it.** An abandoned chunk used to be skipped while the sweep ran on to `isLast` and reclaimed with that range still populated; `LiberatorDeadlockRetryTest::testThreeConsecutiveDeadlocksTriggersSweepGap` asserted `status = 'free'` alongside ten surviving rows in the same fixture, and had since Phase 6a. That was never sanctioned: ADR 0009 step 4 permits reclaim only on a slot confirmed 100% nullified. A gapped sweep now rewinds and stays tombstoned (see the gap path above), so the two ADRs together make "reclaimed implies empty" hold with no exception.

Two things follow for anyone editing this file. **Do not move the reset into the reclaim** — it fires at the moment the annotations are most worth reading, and the older comment proposing `SlotReserver` as the reset point was withdrawn by 0045 for failing open. And **do not make `sweep()` ignore the stored cursor**: idempotent nullification would make that safe, but it deletes the crash-resumption property AC#9 requires and `LiberatorResumeTest` pins.

### Two deliberate omissions

**The sweep UPDATE omits a `tenant_id` predicate.** AC#3 is normative on `(page, slot_column) for id > cursor`, and `ux_slot_assignments_page_column UNIQUE (page_id, slot_column)` means at most one tenant ever had data in the column. ADR 0029 covers this.

**The reclaim carries a `WHERE status='tombstoned'` guard** for the rare operator-resurrect race: 0 rows affected → no reclaim → the next batch sees the updated state.

## `Liberator::tick()`

Generates one `correlation_id` (UUID v4), loads the batch, emits `sweep_started`, then sweeps each slot. **Idle ticks emit nothing**, per blueprint AC#13.
