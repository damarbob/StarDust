# Logging

## `StdoutNdjsonLogger`

Per **ADR 0020**, one NDJSON record per call to stdout; **stderr is reserved for PHP fatals**. Every record carries `ts`, `level`, `event` (closed vocabulary, falling back to `generic_log`), and an optional interpolated `message`. Throwables and `DateTimeInterface` values normalise to structured shapes.

Injecting a custom PSR-3 logger transfers ADR 0020 conformance to the caller.

## The closed event vocabulary

**Adding a new event name requires updating ADR 0020**, or `tests/Smoke/EventVocabularyTest` fails. That test greps `src/Watcher/`, `src/Reconciler/`, `src/Liberator/`, `src/Retype/`, `src/Chronicler/`, `src/Export/`, `src/Search/`, and `src/Filter/` for `'event' => '...'` literals and asserts the union is a subset of the allowlist. Each source's allowlist is enforced independently, which is why the same name can legitimately appear under two sources.

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

### Names deliberately shared across sources

The `source` field is the disambiguator in every case below — do not rename to make them unique.

- **`cache_miss`** — `api` (Phase 4 schema-version cache) and `reconciler`.
- **`lease_lost`** — `chronicler` (Phase 7) and `reconciler` (added 2026-06-18 for the import-job abandoned-claim self-abort, sharing the name per ADR 0025).
- **`deadlock_retry`** — `liberator` and `chronicler`.
- **`cardinality_sampled` / `low_cardinality_index`** — emitted by Watcher-scheduled code but carry `source: 'registry'` per ADR 0020 line 49. The Watcher owns the schedule, not the event identity.
- **`coercion_null`** — emitted by Phase 6b retype code under source `reconciler`.

### Phase 8 notes

`search_request` is emitted once per `StarDust::search()` call with `correlation_id`, `latency_ms`, `rows_returned`, `has_more`, `tree_node_count`, `compile_strategy`.

`capability_unsupported` is deliberately distinct from the generic `pre_flight_rejected` so operators can metric "consumer asked for a feature this driver doesn't service" separately.

`FieldRefResolver` / `CapabilityChecker` / `ValueTypeValidator` otherwise reuse `pre_flight_rejected` with a widened `reason` discriminator covering the new pre-flight codes: `field_unknown`, `field_not_filterable`, `value_type_mismatch`, `value_out_of_bounds`.
