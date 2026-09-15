# Deployment requirements

StarDust ships two ways to keep its slot machinery healthy: four persistent background daemons (Watcher, Reconciler, Liberator, Chronicler), or — for a host that cannot run persistent processes — one bounded `bin/stardust tick` command run on a schedule. Pick one deployment mode per installation; don't mix them (see below).

## Persistent-process deployment (the reference mode)

A supported persistent-process deployment target MUST provide all of the following.

1. **Persistent background processes or long-running containers** — systemd, supervisor, Docker / Kubernetes / ECS, or equivalent.
2. **MySQL 8.0.13+ or Percona 8.0.13+** (also covered by [Requirements](../README.md#requirements)). MariaDB is not supported at any version — StarDust detects it and refuses to run.
3. **PHP 8.x with CLI access** for the `bin/stardust` entry point.
4. **Local filesystem write access** for the Chronicler's async export artifacts (a mounted volume in container deployments).
5. **PID-file or orchestrator-level singleton enforcement for the Watcher** — the in-database advisory lock is a safety net, not the primary enforcement mechanism. The Liberator is multi-worker (page-table-granularity `GET_LOCK` exclusion, not a process singleton) — run as many `bin/stardust liberator` processes as you want reclaim throughput.

**Supported deployment tiers:**

| Tier | Verdict |
| :--- | :--- |
| Free shared hosting (no shell, no cron, no persistent processes) | Unsupported at any level. |
| Paid shared hosting, cron-only, MySQL 8 | See **Cron-only / shared hosting** below. |
| Paid shared hosting, cron-only, MariaDB | Unsupported — MariaDB is rejected regardless of deployment mode. |
| VPS with systemd / supervisor | Supported — reference deployment. |
| Containerized (Docker Compose, Kubernetes, ECS) | Supported — recommended for production at scale. |

## Cron-only / shared hosting

**The real exclusion is shell-less and cron-less hosting, not "shared hosting" as a category.** A cPanel-style account with a real MySQL 8 database and a crontab is a supported target for everything except async exports; the exclusion above only bites a host with none of those. And **shared hosting commonly means MariaDB**, which StarDust rejects outright at boot regardless of deployment mode — check with your host before anything else here. This section is written for the MySQL-8 slice of shared hosting; if your host only offers MariaDB, none of it applies to you.

`bin/stardust tick` runs the Watcher, Liberator and Reconciler as one bounded pass over a single database connection, stopping when it runs out of work, runs out of its time budget, or is asked to shut down. One cron line replaces the four persistent daemons above — which matters beyond convenience: four permanently-resident daemon processes hold four MySQL connections continuously, and shared hosts commonly cap the account's total connections in the low tens, shared with the site itself.

**A minimal crontab entry:**

```cron
* * * * * flock -n /home/youraccount/stardust.lock /usr/bin/php /home/youraccount/bin/stardust tick --budget=50 >> /home/youraccount/logs/stardust-tick.log 2>&1
```

- `flock -n` stops an overlapping firing from starting a second run if the previous one is still finishing — belt-and-braces alongside `tick`'s own pid-file check, which only guards the Watcher, not the whole run.
- `--budget=50` keeps the run comfortably inside a one-minute cron period; StarDust also clamps this against PHP's own `max_execution_time` when the SAPI reports a nonzero ceiling, so a smaller host-imposed limit is respected automatically.
- Redirect output somewhere the account can actually write — cron's default is to email every line to the account owner, which floods an inbox fast on a per-minute schedule.
- Add a second, once-daily line with `--advisories` to run the cardinality and spread advisories, which `tick` otherwise never fires on its own (each cron invocation is a fresh process with no memory of when it last sampled):

```cron
0 3 * * * flock -n /home/youraccount/stardust.lock /usr/bin/php /home/youraccount/bin/stardust tick --budget=50 --advisories >> /home/youraccount/logs/stardust-tick.log 2>&1
```

**Exports are excluded from this mode.** `bin/stardust tick` never runs the Chronicler — an export job runs to completion once claimed, with no point at which it can hand control back before the budget is spent, so it would make the budget meaningless. If your application needs async exports on a host with no persistent-process capability, you cannot offer them yet; the alternative is a synchronous, in-request export outside StarDust's async path, sized to what a single request can complete.

**Never run `bin/stardust tick` alongside the individual `watcher` / `reconciler` / `liberator` commands on the same installation.** `tick` takes the Watcher's pid-file lock itself, so a persistent `watcher` process already running makes every `tick` invocation report a harmless no-op skip (exit code 0) rather than doing its job — the two modes are not meant to complement each other, only to substitute for each other. (The Liberator is the one exception: since it is multi-worker, a `tick` run and a standalone `liberator` process can coexist without either being skipped — they simply divide the tombstoned-slot batch by whichever pages each claims first. Running both is harmless, just redundant with what `tick` already covers.)

**No shell access at all, but scheduled URL fetches are available** (a common paid-tier alternative to cron): drive `StarDust::tick()` from a small script behind a web-accessible URL instead of the CLI. Gate it with a shared secret checked via `hash_equals()` — a public, unauthenticated URL that triggers real database work is a free amplification handle for anyone who finds it — and keep the same budget and single-schedule discipline as the crontab line above.

**A note on `flock` and network filesystems.** Some hosts mount the account's home directory over NFS, where advisory locking has historically been unreliable. If yours does, the `flock -n` line above is not a guarantee — check with your host, or route the lock file to local (non-NFS) storage if one is available.

**Default `artifactDir` and `pidFileDir` must not resolve anywhere web-servable.** The default (`sys_get_temp_dir()`) is usually fine, but confirm it is outside your account's docroot before going live — some shared-hosting layouts put the obvious writable path inside `public_html`.
