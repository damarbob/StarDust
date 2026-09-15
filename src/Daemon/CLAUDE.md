# Daemon infrastructure

Phase 5 shared scaffolding. Every daemon in the engine composes these.

## Poll loop

`PollLoop::run(Tickable, ShutdownSignal, int $intervalSeconds): void` invokes `$tickable->tick()` then sleeps in 1 s slices polling `$shutdown->isRequested()`, so SIGTERM / flag-file shutdown surfaces within ~1 s regardless of the configured interval.

`Tickable` (single `tick(): void`) and `ShutdownSignal` (single `isRequested(): bool`) are intentionally single-method interfaces (ISP). Keep them that way.

## Shutdown signals

- `SignalShutdownSignal` registers async handlers via `pcntl_signal()` when `extension_loaded('pcntl')`, and otherwise stays inert. Phase 5 deliberately does NOT add `ext-pcntl` to composer.json.
- `FlagFileShutdownSignal` observes `<pidFileDir>/<daemonName>.shutdown`.
- `CompositeShutdownSignal` OR-composes them.

## Locks

`PidFileGuard::acquire(string $pidFileDir, string $daemonName, ?string $exceptionClass = null): self` opens `<dir>/<name>.pid`, takes `flock(LOCK_EX | LOCK_NB)`, writes `getmypid()`, and throws on contention. The OS releases the lock on process death, so even a PHP fatal cannot leak it. `$exceptionClass` defaults to `WatcherSingletonViolationException` to preserve Phase 5 behaviour; the Watcher CLI is its only caller today. (The Liberator used to inject `LiberatorSingletonViolationException::class` here — ADR 0049 replaced that process-level singleton with page-table `GET_LOCK` exclusion, and the exception class was deleted.)

`PidFileGuard::tryAcquire(string $pidFileDir, string $daemonName): ?self` is the same acquisition, except flock contention returns `null` instead of throwing — a caller like `CombinedTick` treats "another instance already holds this" as routine, not an error. An unwritable directory or an unopenable pid file still throws (`WatcherSingletonViolationException`): those are configuration errors, not contention. Both methods share one `openHandle()` / `finish()` pair internally rather than duplicating the open/lock/write sequence — there is exactly one place that opens the file and one that writes the PID into it.

`AdvisoryLock::acquire(PDO, string $name, int $timeoutSeconds): self` wraps `GET_LOCK` / `RELEASE_LOCK` and throws `AdvisoryLockTimeoutException` on `0` or `NULL`.

**The Watcher uses the literal `GET_LOCK('stardust_page_provision', 10)` per blueprint AC#2 — that 10 is normative. Do not parameterise it.**

`AdvisoryLock::tryAcquire(PDO, string $name, int $timeoutSeconds = 0): ?self` (ADR 0049) mirrors `PidFileGuard::tryAcquire()`: contention (`GET_LOCK` returns `0`) returns `null` instead of throwing, while a server-side error (`NULL`) still throws — a skip and a broken server must not look the same. The Liberator's `SweepPageLock` (`src/Liberator/CLAUDE.md`) is the one caller, with a hard-coded `0`-second timeout: a worker that cannot take one page's lock has other pages it could sweep instead, so waiting is never the right move, on the same normative-constant posture as the Watcher's `10` above.

## Who enforces singletons

Process-level singleton enforcement is the **CLI's** job, not the daemon's: `bin/stardust watcher` calls `PidFileGuard::acquire()` itself. The in-DB `GET_LOCK` is the safety net per ADR 0027. The Reconciler and Chronicler are multi-worker by design and take no PID guard at all. **The Liberator moved to this column in ADR 0049** — it was a strict PID-file singleton through Phase 6a and ADR 0048, and is now multi-worker via page-table-granularity `GET_LOCK` exclusion (`SweepPageLock`, `src/Liberator/CLAUDE.md`); `bin/stardust liberator` takes no PID guard at all, the same as `reconciler` and `chronicler`.

**`CombinedTick` is the one deliberate exception to "the CLI enforces singletons."** It composes the Watcher — a strict singleton — inside itself, so `CombinedTick::run()` takes *its* pid-file lock via `PidFileGuard::tryAcquire()` rather than the CLI taking it on the run's behalf. Reasoning: `bin/stardust tick` is not the only intended caller — `StarDust::tick()` is a facade method a consumer can call from their own `cron.php` or a `fastcgi_finish_request()` post-response driver, and that caller cannot be trusted to take the lock itself. Contention is not an error: the run returns `TickStopReason::LOCK_CONTENDED` and touches the database not at all, because an overlapping cron firing (or a persistent `bin/stardust watcher` already running) is expected, routine behaviour. **Since ADR 0049 this list is Watcher-only** — `CombinedTick` composes the Liberator too, but the Liberator no longer holds a pid-file lock for the run to take; its own `SweepPageLock` acquisitions happen inside `Liberator::sweepBatch()` regardless of what invoked it, so a `tick` run and a standalone `bin/stardust liberator` process may now run concurrently, dividing the tombstoned-slot batch by whichever pages each claims first. `tick_skipped.contended_pid_file` can therefore now only ever be `watcher`.

## Bounded combined tick (ADR 0048)

`CombinedTick::run(TickBudget, bool $advisories = false): TickReport` runs the Watcher, Liberator and Reconciler as one bounded, budget-limited pass over a single connection — the shared-hosting deployment mode's answer to "one cron line instead of four persistent daemons." See `StarDust::tick()` for the facade entry point.

**Fixed order, and it is a design decision, observable in the event stream the same way the Reconciler's own work-source order is:**

1. Take the Watcher's pid-file lock (`tryAcquire()`; contention stops the run with `LOCK_CONTENDED` before any daemon is touched). **Since ADR 0049 the Liberator has no pid-file lock to take here** — its own `SweepPageLock` acquisitions happen inside step 4's `Liberator::sweepBatch()` call, so this step composes only the Watcher's singleton.
2. Optionally force both Watcher advisory samplers once (`Watcher::sampleAdvisories()` — see below for why).
3. Run `Watcher::tick()` once, unconditionally, before the round loop — a round's `CAPACITY_WAIT` can only be cleared by the Watcher, so provisioning has to have a chance to run before the Reconciler can possibly need it.
4. Loop: sweep one Liberator batch (`Liberator::sweepBatch()`), then run one Reconciler round (`Reconciler::tickRound()`), re-running the Watcher if the round reported `CAPACITY_WAIT`. Liberator runs before the Reconciler within a round so a slot it reclaims `tombstoned → free` is visible to whatever the Reconciler reserves later in the same round.
5. A round that swept nothing and found the Reconciler fully idle stops the loop (`TickStopReason::IDLE`) — the load-bearing courtesy to the host: without it a quiet minute is ~50 s of continuous idle polling, exactly what this mode exists to avoid. The budget and the injected `ShutdownSignal` are checked only between rounds, never mid-round, so a budget at or under zero still completes one full round rather than erroring — `TickBudget` documents why that has to be the contract.

**One run is one failure domain**, unlike three independently-supervised processes: an exception from any composed daemon propagates out of `run()` uncaught, ending the whole run. Deliberate — it matches the existing "fail loudly on unexpected error" policy every daemon here already has, and cron's own mail-on-stderr is the intended operator signal.

**The Chronicler is deliberately not composed here.** `ExportJobProcessor::process()` runs a claimed job to completion with no yield point, so including it would turn the time budget into a suggestion. Exports stay a persistent-process feature (`bin/stardust chronicler`) until a future cooperative-yield change gives it one; `docs/deployment.md` says so in those words.

**`Reconciler::tickRound()` and `Liberator::sweepBatch()`** are the seams this composes: `tick()` (the `Tickable` contract the standalone daemon CLI commands use) now delegates to them and discards the result. Both keep their existing `tick(): void` signature — `Tickable` is unchanged — so the standalone `bin/stardust reconciler` / `bin/stardust liberator` poll loops behave exactly as before.

**`Watcher::sampleAdvisories(string $correlationId): void`** forces both advisory samplers unconditionally, bypassing `Watcher`'s normal in-memory due-check. That in-memory schedule (`$nextAdvisorySampleAt`) is a process-local field — fine for a persistent daemon, but a fresh `Watcher` on every `CombinedTick` invocation would never get past its first (always-false) `shouldSampleAdvisories()` check, so the ADR 0019 cardinality advisory and the ADR 0031 spread advisory would simply never fire under this mode. The operator schedules `bin/stardust tick --advisories` off its own once-daily crontab line instead. Persisting the schedule so it works unprompted is open as its own change (`ROADMAP.md`), not a silent requirement of this mode.

`TickBudget::resolve(int $configuredSeconds, int $marginSeconds): self` resolves the loop's ceiling: the lower of the configured seconds and `max_execution_time` (when the SAPI reports one — the CLI SAPI's own default is already `0`, verified empirically, not assumed), less the margin. `@set_time_limit(0)` is attempted first and, on success, resets `max_execution_time` to `0` itself — which is exactly why the clamp arithmetic is split into the pure `TickBudget::fromCeiling()`: a test process cannot fake a surviving nonzero ceiling by `ini_set()`-ing one and then calling `resolve()`, since `resolve()`'s own `set_time_limit(0)` call would erase it first.
