# Page provisioning

## `PageProvisioner`

Phase 2 entry point for adding extension capacity. `provision(array $filterableSlots = []): int` runs the `CREATE TABLE entry_slots_page_N` DDL (which auto-commits) and then opens a registry transaction that inserts the `stardust_pages` row, the full 60-row slot inventory (`status='free'`), and a `stardust_schema_version.version` bump atomically (ADR 0017 §4.6 invariant #4).

Caller names the slot columns to index via `$filterableSlots`; everything else is unindexed (ADR 0003). Since ADR 0035 the Watcher passes a non-empty set in production, derived from registry demand.

`slotColumnsForType(string $type): list<string>` is `public static` (alongside `allSlotColumns()`) so the Watcher's planner reads the family layout — and its length as the family's per-page capacity — from one definition.

Per-page layout is fixed at 25 `i_str_NN` (`TEXT`, 766-char prefix index per ADR 0030), 15 `i_int_NN` (`BIGINT`), 10 `i_num_NN` (`DOUBLE`), 10 `i_dt_NN` (`DATETIME`). Emits one `page_provisioned` NDJSON event on success.

See the root CLAUDE.md "Schema invariants" section for why string slots are `TEXT` with a 766-char prefix index and why `ROW_FORMAT=DYNAMIC` is load-bearing — that one is easy to break and expensive to diagnose.

## `EmptyTableGuard` (ADR 0012)

`EmptyTableGuard::assertEmpty(PDO, string $tableName)` whitelists the `entry_slots_page_{N}` name shape, probes `SELECT 1 FROM <table> LIMIT 1`, and throws `PopulatedPageDDLException` if a row exists.

Phase 2's production path never trips this — it only creates fresh empty pages. The helper is the canonical pre-DDL check later phases must consult **before mutating any extension page**.
