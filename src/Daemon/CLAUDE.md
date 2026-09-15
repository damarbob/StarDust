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

`PidFileGuard::acquire(string $pidFileDir, string $daemonName, ?string $exceptionClass = null): self` opens `<dir>/<name>.pid`, takes `flock(LOCK_EX | LOCK_NB)`, writes `getmypid()`, and throws on contention. The OS releases the lock on process death, so even a PHP fatal cannot leak it. `$exceptionClass` defaults to `WatcherSingletonViolationException` to preserve Phase 5 behaviour; the Liberator CLI passes `LiberatorSingletonViolationException::class`.

`PidFileGuard::tryAcquire(string $pidFileDir, string $daemonName): ?self` is the same acquisition, except flock contention returns `null` instead of throwing — a caller like `CombinedTick` treats "another instance already holds this" as routine, not an error. An unwritable directory or an unopenable pid file still throws (`WatcherSingletonViolationException`): those are configuration errors, not contention. Both methods share one `openHandle()` / `finish()` pair internally rather than duplicating the open/lock/write sequence — there is exactly one place that opens the file and one that writes the PID into it.

`AdvisoryLock::acquire(PDO, string $name, int $timeoutSeconds): self` wraps `GET_LOCK` / `RELEASE_LOCK` and throws `AdvisoryLockTimeoutException` on `0` or `NULL`.

**The Watcher uses the literal `GET_LOCK('stardust_page_provision', 10)` per blueprint AC#2 — that 10 is normative. Do not parameterise it.**

## Who enforces singletons

Process-level singleton enforcement is the **CLI's** job, not the daemon's: `bin/stardust watcher` and `bin/stardust liberator` call `PidFileGuard::acquire()` themselves. The in-DB `GET_LOCK` is the safety net per ADR 0027. The Reconciler and Chronicler are multi-worker by design and take no PID guard at all.

**`CombinedTick` is the one deliberate exception to "the CLI enforces singletons."** It composes the Watcher and the Liberator — both strict singletons — inside itself, so `CombinedTick::run()` takes their pid-file locks itself via `PidFileGuard::tryAcquire()` (watcher's, then liberator's) rather than the CLI taking them on its behalf. Reasoning: `bin/stardust tick` is not the only intended caller — `StarDust::tick()` is a facade method a consumer can call from their own `cron.php` or a `fastcgi_finish_request()` post-response driver, and that caller cannot be trusted to take the lock itself. Contention is not an error: the run returns `TickStopReason::LOCK_CONTENDED` and touches the database not at all, because an overlapping cron firing (or a persistent `bin/stardust watcher`/`liberator` already running) is expected, routine behaviour.

## Bounded combined tick (ADR 0048)

`CombinedTick::run(TickBudget, bool $advisories = false): TickReport` runs the Watcher, Liberator and Reconciler as one bounded, budget-limited pass over a single connection — the shared-hosting deployment mode's answer to "one cron line instead of four persistent daemons." See `StarDust::tick()` for the facade entry point.

**Fixed order, and it is a design decision, observable in the event stream the same way the Reconciler's own work-source order is:**

1. Take the Watcher's pid-file lock, then the Liberator's (`tryAcquire()`; contention on either stops the run with `LOCK_CONTENDED` before either daemon is touched).
2. Optionally force both Watcher advisory samplers once (`Watcher::sampleAdvisories()` — see below for why).
3. Run `Watcher::tick()` once, unconditionally, before the round loop — a round's `CAPACITY_WAIT` can only be cleared by the Watcher, so provisioning has to have a chance to run before the Reconciler can possibly need it.
4. Loop: sweep one Liberator batch (`Liberator::sweepBatch()`), then run one Reconciler round (`Reconciler::tickRound()`), re-running the Watcher if the round reported `CAPACITY_WAIT`. Liberator runs before the Reconciler within a round so a slot it reclaims `tombstoned → free` is visible to whatever the Reconciler reserves later in the same round.
5. A round that swept nothing and found the Reconciler fully idle stops the loop (`TickStopReason::IDLE`) — the load-bearing courtesy to the host: without it a quiet minute is ~50 s of continuous idle polling, exactly what this mode exists to avoid. The budget and the injected `ShutdownSignal` are checked only between rounds, never mid-round, so a budget at or under zero still completes one full round rather than erroring — `TickBudget` documents why that has to be the contract.

**One run is one failure domain**, unlike three independently-supervised processes: an exception from any composed daemon propagates out of `run()` uncaught, ending the whole run. Deliberate — it matches the existing "fail loudly on unexpected error" policy every daemon here already has, and cron's own mail-on-stderr is the intended operator signal.

**The Chronicler is deliberately not composed here.** `ExportJobProcessor::process()` runs a claimed job to completion with no yield point, so including it would turn the time budget into a suggestion. Exports stay a persistent-process feature (`bin/stardust chronicler`) until a future cooperative-yield change gives it one; `docs/deployment.md` says so in those words.

**`Reconciler::tickRound()` and `Liberator::sweepBatch()`** are the seams this composes: `tick()` (the `Tickable` contract the standalone daemon CLI commands use) now delegates to them and discards the result. Both keep their existing `tick(): void` signature — `Tickable` is unchanged — so the standalone `bin/stardust reconciler` / `bin/stardust liberator` poll loops behave exactly as before.

**`Watcher::sampleAdvisories(string $correlationId): void`** forces both advisory samplers unconditionally, bypassing `Watcher`'s normal in-memory due-check. That in-memory schedule (`$nextAdvisorySampleAt`) is a process-local field — fine for a persistent daemon, but a fresh `Watcher` on every `CombinedTick` invocation would never get past its first (always-false) `shouldSampleAdvisories()` check, so the ADR 0019 cardinality advisory and the ADR 0031 spread advisory would simply never fire under this mode. The operator schedules `bin/stardust tick --advisories` off its own once-daily crontab line instead. Persisting the schedule so it works unprompted is open as its own change (`ROADMAP.md`), not a silent requirement of this mode.

`TickBudget::resolve(int $configuredSeconds, int $marginSeconds): self` resolves the loop's ceiling: the lower of the configured seconds and `max_execution_time` (when the SAPI reports one — the CLI SAPI's own default is already `0`, verified empirically, not assumed), less the margin. `@set_time_limit(0)` is attempted first and, on success, resets `max_execution_time` to `0` itself — which is exactly why the clamp arithmetic is split into the pure `TickBudget::fromCeiling()`: a test process cannot fake a surviving nonzero ceiling by `ini_set()`-ing one and then calling `resolve()`, since `resolve()`'s own `set_time_limit(0)` call would erase it first.
