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

`AdvisoryLock::acquire(PDO, string $name, int $timeoutSeconds): self` wraps `GET_LOCK` / `RELEASE_LOCK` and throws `AdvisoryLockTimeoutException` on `0` or `NULL`.

**The Watcher uses the literal `GET_LOCK('stardust_page_provision', 10)` per blueprint AC#2 — that 10 is normative. Do not parameterise it.**

## Who enforces singletons

Process-level singleton enforcement is the **CLI's** job, not the daemon's: `bin/stardust watcher` and `bin/stardust liberator` call `PidFileGuard::acquire()` themselves. The in-DB `GET_LOCK` is the safety net per ADR 0027. The Reconciler and Chronicler are multi-worker by design and take no PID guard at all.
