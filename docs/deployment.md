# Deployment requirements

StarDust v0.3.0 ships with four background daemons (Watcher, Reconciler, Liberator, Chronicler), all implemented and runnable today via `bin/stardust`. A supported deployment target MUST provide all of the following.

1. **Persistent background processes or long-running containers** — systemd, supervisor, Docker / Kubernetes / ECS, or equivalent. Cron-only invocation is not supported in v1; a future `--once` mode is under consideration but not committed.
2. **MySQL 8.0.13+ or Percona 8.0.13+** (also covered by [Requirements](../README.md#requirements)).
3. **PHP 8.x with CLI access** for the `bin/stardust` entry point.
4. **Local filesystem write access** for the Chronicler's async export artifacts (a mounted volume in container deployments).
5. **PID-file or orchestrator-level singleton enforcement for the Watcher and Liberator** — for the Watcher the in-database advisory lock is a safety net, not the primary enforcement mechanism. The Liberator relies on the PID file alone (it issues DML only, never DDL).

**Supported deployment tiers:**

| Tier | Verdict |
| :--- | :--- |
| Free shared hosting (no shell, no cron, no persistent processes) | Unsupported. |
| Paid shared hosting (cron only) | Unsupported in v1; a future `--once` mode is under consideration. |
| VPS with systemd / supervisor | Supported — reference deployment. |
| Containerized (Docker Compose, Kubernetes, ECS) | Supported — recommended for production at scale. |
