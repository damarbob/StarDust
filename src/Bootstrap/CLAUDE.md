# src/Bootstrap

`Bootstrapper` is the idempotent migration runner, and this file holds the schema invariants worth knowing before editing any DDL, or any SQL that leans on a table's shape.

It creates the data plane (`entry_data`, `stardust_sync_queue`), the schema registry (`stardust_models`, `stardust_fields`, `stardust_pages`, `stardust_slot_assignments`), and the operational tables (`stardust_schema_version`, `stardust_export_jobs`, `stardust_import_jobs`, `stardust_reconciler_dlq`, `backfill_checkpoints`, `stardust_advisory_schedule`). Safe to re-run, and **no DDL is destructive**: a load-bearing invariant verified by `testBootstrapIsIdempotentAndNonDestructive`.

## Adding schema

- **A new table, index or column is a new private method**, never an edit to an existing method body, and is reviewed as a standalone schema change.
- **Changes to an existing table use the `information_schema` probe pattern**: `COLUMNS` for a column, `STATISTICS` for an index, then `ALTER TABLE` only when missing. MySQL has no `CREATE INDEX IF NOT EXISTS`, so every `ensureXxx()` method works this way.
- **A column added to a table that can hold in-flight work is nullable, and every reader falls back when it is null.** Work already running when the `ALTER` lands has no value. The correlation-id columns below are the precedent.
- **A new table also goes in `SchemaFixture::CORE_TABLES`**, or tests leak state between each other. `Conventions/BootstrapperTableAllowlistTest` fails on drift in either direction. See `tests/Smoke/CLAUDE.md`.
- **Engine-specific DDL text comes from `Support\Dialect`**, never an inline literal (root `CLAUDE.md`, "Support utilities").

## `stardust_slot_assignments`

- **`status` is a closed five-state ENUM**: `free | assigned | tombstoned | backfilling | ready`. Out-of-band values are rejected by the database (default `STRICT_TRANS_TABLES`). The live states are `assigned | backfilling | ready`, and the write path materialises into all three.
- **`ux_slot_assignments_field_live` implements ADR 0017's "at most one live slot per field"** via a `CASE … END` over `status`, under the same index name on both engines. On MySQL it is a functional partial UNIQUE index. MariaDB has no functional-index syntax (errno 1064), so there the same `CASE` lives on a `PERSISTENT` generated column (`live_field_id`, from `ensureSlotAssignmentLiveFieldIdColumn()`) with a plain `UNIQUE KEY`. Nothing downstream branches on which shape is live. Do not collapse this back into the `CREATE TABLE`.
- **`sweep_cursor_id` and `sweep_gap_count` describe one sweep, not one column (ADR 0045).** The cursor is the sweep's high-water mark on `entry_id`. The gap count is incremented by the Liberator's gap path so operators can spot sweeps that skipped rows. **Both are cleared by the UPDATE that flips a slot to `tombstoned`, and both survive the `tombstoned → free` reclaim**, so they describe the sweep that produced the current state. Without the clear, a recycled slot's second sweep resumed from its previous occupant's cursor and returned to `free` still holding that field's values.
  - **`SlotReserver` is deliberately not the reset point.** Tombstone time fails closed (a sweep that starts too early costs one idempotent pass, one that starts too late loses data) and keeps the annotations readable on a reclaimed slot.
  - **There are two tombstone sites and both carry the clause**: `Slot\LiveSlotTombstoner` and `Delete\ModelPurgeWorkSource`'s final-chunk re-assertion. Both are guarded `AND status IN ('assigned','backfilling','ready')`, which stops either from restarting a sweep already in flight.
- **`fk_slot_assignments_field` is `RESTRICT`**, so a live slot blocks `DELETE FROM stardust_fields` with errno 1451. `Slot\LiveSlotTombstoner` clears `field_id = NULL` **before** flipping `status`. That defends the unique index, and it also releases the FK inside the transaction, which ADR 0037 depends on. The orphaned tombstone stays sweepable because the Liberator never joins `stardust_fields` (ADR 0029). Used by `RetypeInitiator` and `DeleteFieldInitiator`; do not re-inline it. `Delete\ModelPurgeWorkSource` is the one place that legitimately repeats the two-step (batched across a model's slots), so a change to either step is a change in two files.
- **Which slot columns are indexed is not persisted.** Consumers derive it at runtime from `information_schema.STATISTICS` through `Slot\IndexedSlotPredicate`, at one data-dictionary lookup per slot per Watcher poll. It is permanent, not transitional, because legacy pages still need it. Persisting it as an `is_indexed` column was considered and rejected in ADR 0043. `IndexedSlotPredicate` is the seam that would keep that a one-file change.

## Extension pages (`entry_slots_page_N`)

- **String slots are `TEXT` with a 766-char prefix index (ADR 0030).** `i_str_NN` holds the full 4096-char QueryFilter string bound. The index is `(tenant_id, i_str_NN(766))`: 766 utf8mb4 chars × 4 bytes + 8 bytes = 3072, exactly the InnoDB DYNAMIC key limit.
  - **`VARCHAR(4096)` is physically impossible.** 25 such columns exceed the 65,535-byte row-definition limit (errno 1118), which counts every VARCHAR in full while TEXT counts about 12 bytes.
  - **`ROW_FORMAT=DYNAMIC` is load-bearing.** COMPACT and REDUNDANT cap index keys at 767 bytes (errno 1071).
  - MySQL rechecks the full value behind every prefix-index access, so all 12 filter operators stay exact. **Never add `ORDER BY` or `GROUP BY` on a string slot without revisiting ADR 0030**, since `max_sort_length` truncates TEXT sorts at 1024 bytes.
  - `PageProvisioner::STRING_INDEX_PREFIX` is the single source of the 766, and `tests/Smoke/Page/StringSlotWidthTest` locks the shape. Existing `VARCHAR(255)` pages are not altered (ADR 0012).
- **A page carries exactly the columns it indexes, and its inventory names the same set (ADR 0043).** `PageProvisioner::buildPageDdl()` and `insertSlotInventory()` take one validated list, so `free` means claimable. `provision()` **requires a non-empty list**: a page with no columns adds no inventory, never clears the low-capacity trigger, and would be provisioned again every tick. **There is no `SLOTS_PER_PAGE`.** A page's capacity is whatever it was provisioned with, and the 25/15/10/10 constants are per-family upper bounds.
- **Two page shapes exist permanently.** Pages provisioned before ADR 0043 keep sixty columns and their unindexed inventory rows. `tests/Smoke/Support/LegacyPage.php` reproduces them; its column layout is frozen and must not track `PageProvisioner`. It picks its collation with a local `match` on `ServerEngine` rather than calling `Dialect`, for the same freezing reason (a hardcoded `utf8mb4_0900_ai_ci` fails on MariaDB with errno 1273).
- **Every page write is `INSERT … ON DUPLICATE KEY UPDATE` keyed on `entry_id`** (Architecture Blueprint §5). Slot column names come from `stardust_slot_assignments.slot_column`, whose universe is `i_{str|int|num|dt}_NN`, so interpolating them into SQL is safe.

## `stardust_fields`

- **`previous_name` (ADR 0036)** bridges a rename window. Payloads are keyed by field name, so a rename flips `name` at once and rewrites payloads asynchronously. Non-null means exactly "a rename is in flight". `SlotResolver` and `LiveSlotMap` pick it up for free because they already select this table without a join. It is cleared in the same transaction as the backfill's completion and version bump, and **those must commit together**, or a reader refreshing between them loses the fallback while unmigrated rows remain.
- **`deleted_at` (ADR 0037)** bridges a deletion window. Non-null means exactly "a deletion is in flight", and every registry reader carries `deleted_at IS NULL`. It is a drain-window marker, **not a soft-delete tier**: there is no undelete, and the purge's final chunk hard-deletes the row. The row outlives the purge only because the work source needs its name, model and tenant. The initiator also clears `is_filterable` in the same UPDATE, which is load-bearing: otherwise `PendingDemandReader` and `UnmappedFieldReserver` read the field as demand and the latter re-takes the FK.

## `stardust_models`

- **`deleted_at` (ADR 0038) is not redundant with the field markers.** A model deletion marks the model and every field it owns. The field half makes every field-severance guard fire with no new predicates. But "no field of this model is live" is also true of a brand-new empty model, which is legal, so the model marker is what the purge's claim query, `listModels()` / `describeModel()` and `SchemaBuilder`'s get-or-create rely on.
- **A missing model row must never read as "deleting".** Every guard tests `deleted_at IS NOT NULL` positively, and `EntryDeleter`'s multi-table UPDATE joins `stardust_models` with a **LEFT** join, because `entry_data.model_id` is a logical-only reference and rows without a registry row are legal (several fixtures depend on it).
- The table has **no `updated_at`**, which is why `ModelRenamer` takes no clock. `BootstrapTest` asserts this negatively.

## `stardust_sync_queue`

- **Claims rely on the PK alone.** `SELECT … ORDER BY id LIMIT N FOR UPDATE SKIP LOCKED` locks rows in PK order.
- **`INDEX (entry_id)` exists for the model purge (ADR 0038)**, which deletes each chunk's queue rows by `entry_id`. Without it, that delete is a full scan holding an exclusive lock on every queue row for the whole chunk transaction. `EntryWriter`'s exhaustion enqueue doesn't skip locked rows, and a blocked enqueue is a blocked `write()`, which ADR 0007 forbids. **Bind the chunk's ids as literals**; written as a subquery, the delete falls back to the full scan.

## `backfill_checkpoints`

- **Four namespaces share this table**: `retype_field_`, `rename_field_`, `delete_field_` and `delete_model_`. All four prefixes are thirteen characters, so every claim query shares `SUBSTRING(job_name, 14)`. The namespaces are disjoint, so no repository's scan can see another's rows.
- **Every claim query escapes its `LIKE` pattern via `Support\LikePattern`.** `job_name` is operator-supplied for Backfill Pump jobs, and `_` is a wildcard, so `deleteXmodelY_rebuild` matched an unescaped `delete_model_%`. Each query also carries an integrity predicate (`previous_name IS NOT NULL` / `deleted_at IS NOT NULL`) that no operator-named job can satisfy, because a stray match in the model purge would be an unrecoverable `DELETE`.
- **All four write with `INSERT … ON DUPLICATE KEY UPDATE`.** Only `src/Delete/` ever deletes rows here, so a plain `INSERT` throws on a field's second lifecycle once the first is `completed`. `RetypeCheckpointRepository`'s upsert additionally resets `source_declared_type` (omitting it would drain the second retype through the wrong ADR 0024 matrix cell, silently), and it is why `RetypeInitiator` locks the field row `FOR UPDATE OF f`. The other initiators need no lock, since a lost race there only resets a cursor. Details: `src/Retype/CLAUDE.md`.
- **`correlation_id`** carries the lifecycle's ADR 0020 operation id across the process boundary, so each `*_complete` event joins its `*_started` half, with the per-tick id beside it as `chunk_correlation_id`. Reset in every upsert, so a second lifecycle never inherits the first one's id. `StdoutNdjsonLogger` synthesises an id for any event that omits one, so a missing thread looks well-formed on the wire; `tests/Smoke/Conventions/EventCorrelationTest` is what catches it.
- **`source_declared_type`** is the retype's source type. `RetypeInitiator` overwrites `stardust_fields.declared_type` in the same transaction, so the source type survives only here. Nullable because Backfill Pump jobs have no retype semantics.

## Correlation ids on the other process boundaries

`stardust_sync_queue.origin_correlation_id`, `stardust_reconciler_dlq.origin_correlation_id`, and `correlation_id` on `stardust_import_jobs` and `stardust_export_jobs` are all `VARCHAR(36) NULL` with the nullable-fallback rule above. **The join target differs per table and is not interchangeable.**

- An event whose operation is the job takes the job's id directly (the Chronicler's `job_claimed` / `job_complete`).
- An event whose operation is a chunk keeps its own id and names the job's as `job_correlation_id` (`ImportJobWorkSource`, which has no per-job completion event).
- **`stardust_sync_queue` is the exception.** One chunk claims rows from many unrelated writes, so no chunk event can carry one write's id. The value is copied onto the dead-letter row instead, beside `chunk_correlation_id`. Successful drains stay silent per row on purpose (ADR 0018's chunk-level design).
- It is a column, not an index, because the enqueue is on ADR 0007's write-availability path. Measured, it doesn't change the enqueue's cost.

## Singletons

- **`stardust_schema_version`** is enforced by `PRIMARY KEY (id)` + `CHECK (id = 1)` + a seed (`INSERT … ON DUPLICATE KEY UPDATE id = id`). The CHECK is silently dropped on MySQL 8.0.13 to 8.0.15, so the PK and seed are the real guarantee.
- **`stardust_advisory_schedule` (ADR 0052)** holds the Watcher's advisory-sample due time, so it survives across combined-tick invocations. It is a separate table, not a column on `stardust_schema_version`, because that row is a serialisation point every registry mutation bumps and its `updated_at` means "when the schema last changed".
  - `next_sample_at IS NULL` means "never scheduled", which preserves ADR 0019's first-sample randomisation.
  - The schedule is **fleet-wide**: one sample per interval across the deployment.
  - The due-check is a conditional UPDATE whose **affected-row count is the claim**. MySQL reports changed rows, not matched rows, so `Watcher::computeNextDue()` floors the next due time at `$from + 1`; a claim that wrote back the stored value would report zero and skip the sample silently.
  - A reused named placeholder throws `SQLSTATE[HY093]` under native prepares, so `AdvisoryScheduleRepository` uses positional `?` with the value repeated, and must keep doing so.

## Other tables

- **`stardust_reconciler_dlq` has no FK to `entry_data`** on purpose. The `missing_entry_data` reason exists so DLQ rows outlive their source row (ADR 0018). Do not "fix" this.
- **`stardust_import_jobs`** enforces ADR 0011 idempotency with `UNIQUE (tenant_id, idempotency_key)`; NULL keys never collide. The `manifest` is written chunk by chunk and doubles as the resume checkpoint. `INDEX (status, heartbeat_at)` backs the abandoned-claim sweep: a `processing` job whose heartbeat lapsed past `Config::$reconcilerImportLeaseTimeoutSeconds` is re-claimed and resumes from `manifest.entries_written`, and the prior worker self-aborts on a `worker_identity` mismatch (the Chronicler's model).
- **`stardust_export_jobs`** has every column and index the Chronicler uses: `(status, created_at)` for claiming, `(tenant_id, status)` for the submission cap, `(status, heartbeat_at)` for the abandoned-claim sweep, `(completed_at)` for GC. The `filter` column stores a `{model_id, filter}` envelope (stamped by `ExportJobSubmitter::submit()`, read by `ExportJobClaimer::extractModelId()`), with the consumer's QueryFilter verbatim under `.filter`.
- **`entry_data`** carries two tenant-scoped composite indexes, `(tenant_id, model_id)` and `(tenant_id, deleted_at, created_at)`, verified by `testEntryDataCompositeIndexesPresent`.
