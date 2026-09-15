# Liberator daemon

Phase 6a slot reclamation (ADR 0008, ADR 0009), multi-worker since ADR 0049. Four `final` collaborators, SOLID-decomposed.

**No process-level singleton enforcement any more.** `bin/stardust liberator` takes no PID guard, the same as `reconciler` and `chronicler` — run N processes for N-way reclaim throughput. Exclusion happens instead at **page-table granularity**, via `SweepPageLock::tryAcquire(pageId)` (`GET_LOCK('stardust_sweep_page_{pageId}', 0)`, hard-coded zero timeout — a worker that cannot take a page's lock has other pages it could sweep instead, so waiting is never the right move). `Liberator::sweepBatch()` tries the lock per slot in its batch: a claimed slot is swept and the lock released (`finally`) whether or not the sweep threw; a contended slot is skipped for this cycle, left `tombstoned` for whichever worker (this one, next cycle, or another worker) claims it next.

**Why page granularity, not slot granularity or a `FOR UPDATE` claim on `stardust_slot_assignments` (ADR 0009's original rejection, and the roadmap item that started this work):**

1. **A row lock cannot span the unit of work.** `SlotSweeper::sweep()` runs many short transactions per slot (`commitChunk()`), each its own `beginTransaction()`/`commit()`. A `FOR UPDATE` claim taken once in `loadBatch()` would die at the first commit — guarding the batch read and nothing else. Spanning it would need lease columns + heartbeats + abandoned-claim recovery on `stardust_slot_assignments`, i.e. the Chronicler's machinery, and new DDL this ADR deliberately avoids.
2. **Slot granularity is the wrong unit and would make things worse.** Two workers on two *different slots of the same page* both issue `UPDATE {$tableName} SET {$slotColumn} = NULL WHERE entry_id IN (…)` against the *same rows* of the same table — exactly ADR 0009's original "multiply InnoDB row-lock contention" objection, and post-ADR-0046 worse than merely slow: contention burns the retry budget, takes a gap, and a gapped sweep **does not reclaim** (see below). Slot-level parallelism would convert reclamations into deferrals.

Page granularity has neither problem: two workers never touch the same `entry_slots_page_N` table concurrently, so no Liberator-vs-Liberator row contention is possible, and the parallelism is genuinely across distinct IO (distinct page tables) rather than within one. The honest throughput bound is the number of distinct pages holding tombstones, not the worker count. See ADR 0049 for the full case against the row-lock and lease-column alternatives, and for the accepted trade: a worker that hangs *while still connected* holds its page indefinitely — `GET_LOCK` is untouched by `COMMIT`/`ROLLBACK` on the sweep's own per-chunk transactions (verified against MySQL 8.0.13; this is exactly why the lock can safely wrap a multi-transaction sweep in the first place), and only releases via `RELEASE_LOCK` or the holding connection's death. Strictly better than the pre-0049 singleton, where a wedged process stalled *all* reclamation, and observable the same way: tombstone depth and age.

**`sweepBatch()` acquires, sweeps and releases ONE slot's page lock at a time — never all of a batch's locks up front.** A batch can span many pages (up to `liberatorBatchSize`, default 50); pre-acquiring every claimable slot's lock before sweeping any of them would let one worker monopolize every page in its batch for the whole cycle, silently starving every other worker for that entire cycle and defeating the reason this ADR exists. Because of this, `sweep_started` fires once at the END of the cycle rather than at the start: it carries the cycle's final `slots_claimed` / `slots_contended` tallies, which are not knowable before the batch has actually been walked slot by slot. `tests/Smoke/Liberator/LiberatorSweepTest::testEmitsExpectedEventSequence` pins the resulting order — `sweep_chunk`(s), `sweep_complete`, `sweep_started` — a change from the pre-0049 shape where it led.

`worker_identity` (`host:pid:uuid`, minted once per process via `Support\WorkerIdentity::mint()`, shared with the Chronicler and the Reconciler's import-job source) rides every one of the five Liberator events, since with N workers the event stream alone can no longer distinguish which process emitted what.

## `TombstonedSlotRepository`

`loadBatch()` runs the registry SELECT `… WHERE status='tombstoned' ORDER BY tombstoned_at ASC, page_id, slot_column LIMIT N` — **no `FOR UPDATE`**. Two workers loading the same batch is fine and costs one extra SELECT per cycle; exclusion happens afterwards, at `SweepPageLock`, not here — and hydrates `TombstonedSlot` DTOs with the page's `table_name` joined in.

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

## `Liberator::tick()` / `Liberator::sweepBatch()`

Generates one `correlation_id` (UUID v4), loads the batch, then walks it one slot at a time: tries `SweepPageLock::tryAcquire()`, and on success sweeps that slot immediately and releases its page lock in a `finally` before moving to the next slot — never acquiring ahead. At the end of the cycle, emits `sweep_started` **only when at least one slot was actually claimed** (with the final `slots_claimed` / `slots_contended` tallies alongside the existing `batch_size`). **Idle ticks — including a fully-contended batch, where every slot's page is already being swept by another worker — emit nothing**, extending blueprint AC#13's no-spam-when-idle rule to "nothing to do because everything is already spoken for." `sweepBatch()` returns the count actually swept, not the batch size — `CombinedTick` (ADR 0048) relies on that to detect a genuinely idle round; returning the batch size would make a fully-contended tick look like progress and defeat the idle-exit.

## `SlotSweeper` is unchanged, deliberately

ADR 0049 added a `string $workerIdentity` parameter to `sweep()` (threaded onto its four events, alongside `sweep_started`'s from `Liberator`) and nothing else. The per-slot chunk loop, the deadlock/gap policy (ADR 0009/0045/0046, ~330 of these 385 lines), and the reclaim transaction are all untouched — multi-worker-ness lives entirely one layer up, at whether a slot's page lock was claimed before `sweep()` is ever called. A slot in progress is swept by exactly one worker at a time (the page lock guarantees it), so nothing about `SlotSweeper`'s own single-writer assumptions — the cursor, the gap-pinning, the reclaim guard — needed to change.
