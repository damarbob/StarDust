# Page provisioning

## `PageProvisioner`

Phase 2 entry point for adding extension capacity. `provision(array $filterableSlots): int` runs the `CREATE TABLE entry_slots_page_N` DDL (which auto-commits) and then opens a registry transaction that inserts the `stardust_pages` row, the slot inventory (`status='free'`), and a `stardust_schema_version.version` bump atomically (ADR 0017 §4.6 invariant #4).

**A page is created with exactly the columns it indexes (ADR 0043)** — the DDL and the inventory take the same list, so every `free` row describes a slot a reservation can take. It used to create all sixty columns and index a handful, which was coherent only while ADR 0003 let a non-filterable field hold a slot "for typed retrieval"; ADR 0034 withdrew that, ADR 0004 requires a filterable field's slot to be indexed, and between them an unindexed column stopped being occupiable by anything. `CapacityReporter` counted those rows regardless, so `usable_free_slots` was wrong by the entire unindexed population.

**`$filterableSlots` is required and must be non-empty.** A page with no columns has no inventory rows, so it adds nothing to the capacity totals — the Watcher's low-capacity trigger would never clear and it would provision one every tick. `ProvisioningPlanner` refuses to plan such a page and this class rejects one, so the invariant holds from both ends. Column order does not matter: `orderColumns()` re-sorts into layout order, so DDL, inventory and `slot_type` all come from one traversal.

**Two page shapes now exist, permanently.** Pages provisioned before ADR 0043 keep their sixty columns and their unindexed inventory rows — ADR 0012 is forward-only and nothing rewrites them — which is why `IndexedSlotPredicate` stays and why `tests/Smoke/Support/LegacyPage.php` exists to keep building the old shape in fixtures. That helper's DDL is frozen and must not be updated to track this class.

`slotColumnsForType(string $type): list<string>` is `public static` (alongside `allSlotColumns()` and `slotFamilies()`) so the Watcher's planner reads the family layout from one definition. **Its length is the family's per-page *upper bound*, not a page's capacity** — since ADR 0043 that is whatever the page was provisioned with, readable only from its own inventory rows. `allSlotColumns()` survives as the validation vocabulary. There is deliberately no `SLOTS_PER_PAGE`.

Per-family layout bounds are 25 `i_str_NN` (`TEXT`, 766-char prefix index per ADR 0030), 15 `i_int_NN` (`BIGINT`), 10 `i_num_NN` (`DOUBLE`), 10 `i_dt_NN` (`DATETIME`). Emits one `page_provisioned` NDJSON event on success.

See the root CLAUDE.md "Schema invariants" section for why string slots are `TEXT` with a 766-char prefix index and why `ROW_FORMAT=DYNAMIC` is load-bearing — that one is easy to break and expensive to diagnose.

## `EmptyTableGuard` (ADR 0012)

`EmptyTableGuard::assertEmpty(PDO, string $tableName)` whitelists the `entry_slots_page_{N}` name shape, probes `SELECT 1 FROM <table> LIMIT 1`, and throws `PopulatedPageDDLException` if a row exists.

Phase 2's production path never trips this — it only creates fresh empty pages. The helper is the canonical pre-DDL check later phases must consult **before mutating any extension page**.
