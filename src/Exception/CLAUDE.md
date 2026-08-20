# Exceptions

All typed errors extend `RuntimeException`.

**Default to one class per failure mode.** The single discriminator-style exception (`QueryFilterValidationException`) is a deliberate exception to that rule, justified below — don't generalise from it.

## Phase 3 — write path

- `InvalidTenantIdException` — `tenant_id <= 0`.
- `PayloadTooLargeException` — sync bulk above 1 000 entities.
- `UncoercibleSlotValueException` — first-write payload can't be coerced to its slot's declared_type. Phase 3 policy is fail-fast; ADR 0024's NULL + `coercion_null` policy is reserved for the Reconciler's retype backfill.
- `EntryNotFoundException` — `StarDust::updateEntry()` targeted an `entry_data.id` that does not resolve for the caller's tenant: never existed, belongs to another tenant, or already soft-deleted. The three are deliberately indistinguishable — separating them would leak the existence of another tenant's row (Architecture Blueprint §1.2). **`deleteEntry()` does not throw it**, returning `false` instead, because a repeated delete has already achieved what the caller wanted while a silently-dropped update loses data. Not to be confused with `EntryDataMissingException` below, which is the Reconciler's internal integrity signal for a queued `entry_id` whose row vanished mid-drain.
- `MalformedEntryPayloadException` — the structural-envelope guard for the `EntryPayload::fromArray()` / `fromJson()` / `listFromArray()` / `listFromJson()` factories: missing or mistyped `tenantId` / `modelId` / `fields`, non-map `fields`, unparseable JSON, or a wrong root. Carries the offending `$key` (e.g. `tenantId` or `[3].modelId`). The `tenant_id >= 1` rule and per-field coercion deliberately stay on the write path, not in the factory.

## Phase 4 — read path

- `UnknownFieldException` — filter references a field not in `stardust_fields`.
- `FieldNotFilterableException` — filter target has `is_filterable = false`.
- `FieldNotIndexedException` — filter target's slot is `backfilling` / `tombstoned` / unmapped; three states uniformly rejected per ADR 0004.
- `InvalidCursorException` — opaque cursor failed structural decode.
- `PageSizeOutOfRangeException` — page size outside `[1, 1000]`.

## Phase 5 — daemons

- `WatcherSingletonViolationException` — `PidFileGuard::acquire` contention. CLI exit code 2.
- `AdvisoryLockTimeoutException` — `GET_LOCK` returned 0/NULL. Caught by the Watcher and translated to a `lock_contention` event.
- `DlqReplayNotFoundException` — `reconciler:dlq:replay --id/--reason` matched zero rows. CLI exit code 1.
- `ImportJobArtifactException` — artifact missing / unreadable / malformed JSON. Caught by `ImportJobWorkSource`, which transitions the job to `failed` with `failed_reason='malformed_json'`.
- `EntryDataMissingException` — queued `entry_id` has no backing `entry_data` row. `SyncQueueWorkSource` routes it to DLQ with `reason='missing_entry_data'`.

## Phase 6a

- `LiberatorSingletonViolationException` — Liberator's PID-file contention. CLI exit code 2. `PidFileGuard::acquire()` takes an optional `?string $exceptionClass` defaulting to `WatcherSingletonViolationException` so Phase 5 behaviour is preserved; the Liberator CLI passes this class explicitly.

## Phase 6b — retype

- `IncompatibleRetypeException` — registry-write-time guard against the ADR 0024 categorically-rejected pairs (`int↔datetime`, `numeric↔datetime`).
- `RetypeInProgressException` — a `running` `retype_field_{id}` checkpoint already exists for this field.
- `FieldNotFoundException` — the public retype/promote API received a field id that doesn't exist or belongs to a different tenant. Existing internal callers like `SlotReserver` still throw `InvalidArgumentException` for the same situation.

## ADR 0034

- `NonFilterableFieldSlotException` — slot reservation attempted for a field whose `is_filterable` is false. Raised by all three `SlotReserver` entry points before any row is touched.

> **Do not confuse it with `FieldNotFilterableException`.** That one is the Phase 4 read-path pre-flight rejection for a *filter* targeting such a field — "fix your query". This one is a reservation-time invariant violation — "your code asked the registry for something the architecture forbids". The names are one word apart, and that distinction is precisely why a separate class exists rather than a reuse.

## Phase 7 — exports

- `ExportJobActiveCapExceededException` — submission API; the tenant already has `chroniclerPerTenantActiveCap` active jobs in pending/processing. Carries `tenantId` + `activeCount` + `cap`.
- `ChroniclerRowEncodingException` — internal. `ArtifactStream` raises it on per-row encoding failure; the processor catches it, charges `skip_count++`, and emits `row_skipped` with the closed-taxonomy `reason: format_invalid | unrepresentable_codepoint`.
- `ChroniclerArtifactDiskFullException` — internal. Raised on `ENOSPC` / short-write; the processor catches it, marks `failed:disk_full`, and emits `job_failed{reason:disk_full}`.

There is deliberately **no** `ChroniclerSingletonViolationException` — the Chronicler is multi-worker by design (lease/heartbeat), so singleton enforcement would be dead code.

## Phase 8 — the one discriminator exception

`QueryFilterValidationException` lives under `src/Filter/`, not here. It carries:

- `public readonly string $errorCode` — one of the 13 `ValidationErrorCode` constants. **The field is `$errorCode`, not `$code`**, because `Exception::$code` is already declared non-readonly on the base class and PHP 8.4 forbids redeclaring it readonly.
- `string $jsonPointer` — RFC 6901.
- `array $details`.

The pattern was chosen because the 13 wire-format codes share a single caller response — "fix the filter JSON and retry" — so a 13-class hierarchy would force caller catch-clauses to duplicate the same handler 13 times.

Three of the 13 codes have pre-existing Phase 4 equivalents and reuse them instead: `field_unknown` → `UnknownFieldException`, `field_not_filterable` → `FieldNotFilterableException`, `value_out_of_bounds` on page size → `PageSizeOutOfRangeException`.

**Future phases with multiple caller-actionable failures should still prefer one class per failure mode.** This pattern is specifically for closed-taxonomy validation discriminators.

## Elsewhere

`PopulatedPageDDLException` lives under `src/Page/` — it is the ADR 0012 guard.
