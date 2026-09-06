# Logging

## `StdoutNdjsonLogger`

Per **ADR 0020**, one NDJSON record per call to stdout; **stderr is reserved for PHP fatals**. Every record carries `ts`, `level`, `event` (closed vocabulary, falling back to `generic_log`), and an optional interpolated `message`. Throwables and `DateTimeInterface` values normalise to structured shapes.

Injecting a custom PSR-3 logger transfers ADR 0020 conformance to the caller.

### The synthesised `correlation_id` hides a real defect class — know this before auditing

`log()` fills in a fresh `UuidV4::generate()` whenever the context omits `correlation_id` or passes it as null. That is deliberate and it stays: ADR 0020 types the field as a required non-nullable string, and a consumer's own PSR-3 logger will not be so careful.

**But it means the wire cannot distinguish a threaded id from an invented one.** Nine `source: registry` sites emitted no id for months; every record they produced was well-formed, passed every shape assertion in the suite, and correlated to nothing. The gap was found by reading a `rename_started` line next to the `rename_complete` that closed it and noticing they would not join — not by any test, because no test *could*.

Two things follow, and neither is optional:

- **A "correlation_id is a UUID" assertion proves nothing.** `PageProvisionerTest`, `SlotReserverTest` and `EntryWriterTest` all contained one throughout. What earns its keep is asserting that two events of one operation carry the *same* id — see `tests/Smoke/LifecycleCorrelationTest`.
- **The enforcement is a source scan, not a runtime check.** `tests/Smoke/Conventions/RegistryCorrelationTest` requires the id be **passed** at every `source: registry` emit site. It is scoped to that source because the daemon sources mint per-cycle or per-chunk ids at the top of the tick and thread them everywhere, so they have never had this failure mode; `registry` is the source with no owning loop.

## The closed event vocabulary

**Adding a new event name requires updating ADR 0020**, or `tests/Smoke/EventVocabularyTest` fails. That test greps `src/Watcher/`, `src/Reconciler/`, `src/Liberator/`, `src/Retype/`, `src/Rename/`, `src/Delete/`, `src/Compaction/`, `src/Write/`, `src/Chronicler/`, `src/Export/`, `src/Search/`, and `src/Filter/` for `'event' => '...'` literals and asserts the union is a subset of the allowlist. Each source's allowlist is enforced independently, which is why the same name can legitimately appear under two sources.

| Phase | Events | Source |
| :-- | :-- | :-- |
| 2 | `page_provisioned`, `slot_reserved` | `registry` |
| 3 | `entry_written`, `exhaustion_fallback` | `api` |
| 3 | `bulk_chunk_committed`, `bulk_chunk_rolled_back`, `bulk_accepted`, `payload_too_large` | `bulk_api` |
| 4 | `request`, `pre_flight_rejected`, `cache_miss` | `api` |
| 5 | `poll_started`, `poll_complete`, `provision_started`, `provision_complete`, `provision_failed`, `lock_contention` | `watcher` |
| 5 | `chunk_claimed`, `chunk_complete`, `chunk_partial`, `dlq_inserted`, `cache_miss`, `capacity_wait`, `coercion_null`, `lease_lost` | `reconciler` |
| 5 | `cardinality_sampled`, `low_cardinality_index` | `registry` |
| 6a | `sweep_started`, `sweep_chunk`, `sweep_complete`, `deadlock_retry`, `sweep_gap_flagged` | `liberator` |
| 6b | `retype_started`, `promote_to_ready` | `registry` |
| 7 | `job_claimed`, `chunk_written`, `deadlock_retry`, `chunk_skipped`, `row_skipped`, `lease_lost`, `low_disk`, `artifact_oversized`, `job_complete`, `job_failed`, `gc_swept` | `chronicler` |
| 7 | `export_accepted` | `export_api` |
| 8 | `search_request`, `capability_unsupported` | `api` |
| — | `rename_started`, `rename_complete` (ADR 0036), `model_renamed` | `registry` |
| — | `delete_started`, `delete_complete` (ADR 0037) | `registry` |

### Names deliberately shared across sources

The `source` field is the disambiguator in every case below — do not rename to make them unique.

- **`cache_miss`** — `api` (Phase 4 schema-version cache) and `reconciler`.
- **`lease_lost`** — `chronicler` (Phase 7) and `reconciler` (added 2026-06-18 for the import-job abandoned-claim self-abort, sharing the name per ADR 0025).
- **`deadlock_retry`** — `liberator`, `chronicler` and `reconciler` (added to the reconciler source on 2026-08-25 for the ADR 0038 model purge; this list said only the first two until 2026-08-27, which is drift the ADR did not have).
- **`cardinality_sampled` / `low_cardinality_index`** — emitted by Watcher-scheduled code but carry `source: 'registry'` per ADR 0020 line 49. The Watcher owns the schedule, not the event identity.
- **`coercion_null`** — emitted by Phase 6b retype code under source `reconciler`.

### `lock_wait` is not `capacity_wait`, and not `deadlock_retry`

Three events describe a chunk that did not commit, and conflating any two of them costs an operator a real distinction.

- **`deadlock_retry`** — an attempt failed and will be retried. Carries `attempt`.
- **`lock_wait`** — the source stopped retrying and handed the chunk back as `TickOutcome::LOCK_WAIT`. Carries `attempts` (the budget, spent) and `errno`. **Not an error**: the chunk rolled back whole with its cursor untouched, so the next tick retries identical work. Alert on its persistence, never its occurrence.
- **`capacity_wait`** — the engine is out of slot inventory and needs the Watcher to provision. A different remedy entirely.

The five work sources that emit `lock_wait` are sync-queue, import-job, retype, rename and field-delete purge. `ModelPurgeWorkSource` deliberately does not: it rethrows on exhaustion, because a soft outcome would make an unrecoverable drain look like ordinary back-pressure, and it is the one drain that destroys rows.

### Phase 8 notes

`search_request` is emitted once per `StarDust::search()` call with `correlation_id`, `latency_ms`, `rows_returned`, `has_more`, `tree_node_count`, `compile_strategy`.

`capability_unsupported` is deliberately distinct from the generic `pre_flight_rejected` so operators can metric "consumer asked for a feature this driver doesn't service" separately.

`FieldRefResolver` / `CapabilityChecker` / `ValueTypeValidator` otherwise reuse `pre_flight_rejected` with a widened `reason` discriminator covering the new pre-flight codes: `field_unknown`, `field_not_filterable`, `value_type_mismatch`, `value_out_of_bounds`.

### `delete_complete` is the only event that reports a registry row removal

The ADR 0037 purge reuses the `reconciler` chunk vocabulary with `queue: 'delete_purge'` and adds exactly two registry names, following the `rename_*` pair because a delete has the same synchronous/asynchronous split — an operator needs to tell "severed, still draining" from "gone". It is not folded into a shared `backfill_complete` precisely because of what it reports: `rename_complete` and `promote_to_ready` describe a field that still exists, and this one describes a `stardust_fields` row that no longer does. Per-chunk counts ride as `rows_scanned` / `rows_purged` fields on `chunk_complete`.

### `rename_complete` is not `promote_to_ready`

The ADR 0036 rename backfill reuses the `reconciler` chunk vocabulary (`chunk_claimed` / `chunk_complete`, with `queue: 'rename_backfill'`) and adds exactly two registry names. `rename_complete` is deliberately distinct from `promote_to_ready`: a rename touches no slot, so there is nothing to promote, and sharing the name would make the two indistinguishable on a dashboard. Per-row skips ride as a `rows_rewritten` / `rows_scanned` field on `chunk_complete` rather than earning a new event — only the `'event' => '...'` literal is constrained, payload keys are free.
