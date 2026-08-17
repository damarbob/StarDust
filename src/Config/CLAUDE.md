# Config

Single construction-time DTO per **ADR 0026**. Required: `PDO`. Everything else is optional with a default.

Fields are `readonly`. **New phases append constructor params, never repurpose existing ones.** Note the asymmetry: the *property declarations* are grouped by subsystem for readability, while the *constructor parameters* are strictly append-ordered. The constructor is the compatibility surface — append there, and place the property declaration wherever it reads best.

## Baseline

- `PDO $pdo` — required.
- `?LoggerInterface $logger` — PSR-3, defaults to `StdoutNdjsonLogger`.
- `?ClockInterface $clock` — `psr/clock`, defaults to `SystemClock`.
- `?string $artifactDir` — defaults to `sys_get_temp_dir() . '/stardust'`.

## Phase 5 daemon tuning (eleven fields, appended after `$artifactDir`)

- `$watcherPollIntervalSeconds` (60)
- `$watcherCapacityThreshold` (0.20)
- `$watcherProvisionLockTimeoutSeconds` (10) — blueprint AC#2 pins this in production; the field exists so tests can use a shorter value through the same code path.
- `$cardinalityIntervalSeconds` (86_400)
- `$cardinalitySelectivityThreshold` (0.01)
- `$cardinalityRowFloor` (10_000)
- `$cardinalityDistinctFloor` (10)
- `$reconcilerChunkSize` (500)
- `$reconcilerInterChunkDelayMicros` (0) — wired through both `Reconciler::tick()` after each WORK_DONE outcome AND `ImportJobWorkSource::processEntries()` between chunk windows; default `0` means no pacing, matching Phase 3's `BulkIngestOptions::$interChunkDelayMicros` semantics.
- `$reconcilerCapacityWaitMillis` (5_000)
- `$pidFileDir` — defaults to `sys_get_temp_dir() . '/stardust'`.

## Phase 6a Liberator tuning (five fields, appended after `$pidFileDir`)

- `$liberatorIdleIntervalSeconds` (10) — per blueprint AC#13.
- `$liberatorBatchSize` (50) — max tombstoned slots a single tick will sweep.
- `$liberatorChunkSize` (500) — ADR 0009 normative; parameterised for test ergonomics.
- `$liberatorInterChunkDelayMicros` (0) — paces sweep throughput between chunks.
- `$liberatorDeadlockRetryBudget` (3) — ADR 0009 normative.

## Phase 7 Chronicler tuning (twelve fields, appended after `$liberatorDeadlockRetryBudget`)

- `$chroniclerIdleIntervalSeconds` (10)
- `$chroniclerLeaseTimeoutSeconds` (30) — heartbeat-lapse threshold for the abandoned-claim sweep; ADR 0025 normative.
- `$chroniclerPageSize` (500) — cursor-pagination chunk size, `LIMIT pageSize+1` per ADR 0005.
- `$chroniclerInterChunkDelayMicros` (0)
- `$chroniclerDeadlockRetryBudget` (3) — ADR 0025 normative.
- `$chroniclerSkipCountCap` (1_000) — combined per-row + per-chunk skip cap before `failed:excessive_skips`.
- `$chroniclerArtifactSizeCapBytes` (5 GB) — trips `artifact_oversized`, an event distinct from `job_failed`.
- `$chroniclerArtifactTtlSeconds` (86_400) — completed-job artifact GC.
- `$chroniclerOrphanedPartialTtlSeconds` (3_600) — failed-job partial-artifact GC.
- `$chroniclerLowDiskThresholdPct` (0.10) — pre-claim disk gate.
- `$chroniclerPerTenantActiveCap` (3) — submission cap on `pending+processing`.
- `$chroniclerDbDisconnectBackoffSeconds` (`[1, 4, 16]`) — ADR 0025 fixed schedule; the field exists for test injection.

## Phase 8 (two fields, appended after `$chroniclerDbDisconnectBackoffSeconds`)

- `?EntrySearchInterface $searchDriver` (default `null`) — `StarDust::search()` lazily instantiates a `MysqlNativeDriver` when this is unset. This field is the ADR 0026 construction-time injection seam.
- `FilterLimits $queryFilterLimits` — defaults to `FilterLimits::defaults()`, which encodes the blueprint §4.6 normative bounds: max depth 8, max nodes 256, max args 64, max in-elements 1024, max string 4096 chars, max payload 64 KiB. Injectable so operators can tighten or relax any of the six independently.

## Later single-field appends

Each of these was added after Phase 8 as a discrete fix, and each sits at the end of the constructor in the order shown.

- `?PdoConnector $pdoConnector` (default `null`) — **2026-06-03 resilience fix.** The construction-time reconnect seam the Chronicler's `ExportJobProcessor` uses to rebuild a dropped connection mid-export per ADR 0025 Commitment 6. When `null`, the Chronicler cannot reconnect and degrades to `failed:query_failure` with `last_cursor` preserved. `bin/stardust chronicler` injects a `DsnPdoConnector` (the default impl) built from the same env vars as `$pdo`.
- `$reconcilerImportLeaseTimeoutSeconds` (30) — **2026-06-18 Gap 5 resolution.** Heartbeat-lapse threshold for the import-job abandoned-claim sweep, mirroring `$chroniclerLeaseTimeoutSeconds`; injectable so tests can shorten the lease through the same code path.
- `$cardinalityJitterSeconds` (defaults to 10% of `$cardinalityIntervalSeconds` = 8_640) — **2026-06-20 Gap 7 resolution.** The randomized ± window the Watcher draws a fresh offset from on each periodic cardinality sample, and the full-interval span it phase-randomizes the FIRST sample across, so a fleet of singleton Watchers started in lockstep does not stampede on the same daily schedule. The RNG itself is an injectable `Watcher` constructor seam — `random_int` in production. Since ADR 0031 this interval paces the spread advisory too; the field keeps its `cardinality` name because renaming public surface for a comment's worth of clarity would break every consumer's constructor call.
- `$spreadExcessPageThreshold` (2) — **ADR 0031.** How many *avoidable* pages a model must occupy before `high_spread_model` fires (the other half of the gate, `pages_occupied >= 2`, is not tunable). The default deliberately ignores `excess_pages == 1`: one extra bounded index scan is rarely worth a compaction's cost. Tighten to `1` for latency-critical fleets, raise it if spread is cosmetic for your workload.
