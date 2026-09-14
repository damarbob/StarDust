# Schema changes

Everything that changes a model's shape after entries exist — retype, filterability promotion and demotion, rename, field deletion and model deletion — and which parts are immediate versus which need a running Reconciler.

## Changing a field's type or filterability

```php
// Change a field's declared type. Atomic registry transaction:
//   - stardust_fields.declared_type updates;
//   - the field's current live slot tombstones (Liberator reclaims it);
//   - a new slot of the target type flips free → backfilling (or the
//     reservation defers until capacity is restored);
//   - stardust_schema_version bumps;
//   - a backfill_checkpoints row inserts as `running`.
// Reads fall back to JSON_EXTRACT throughout the backfill window;
// filter queries against the field throw FieldNotIndexedException
// until the slot promotes to `ready`. Uncoercible values store NULL
// (with a per-row `coercion_null` audit event); the JSON payload
// remains authoritative.
$engine->retypeField(
    tenantId:        42,
    fieldId:         $fieldId,
    newDeclaredType: 'int',
);

// Promote an existing unfiltered field to filterable. A fresh
// indexed slot is reserved and backfilled from the JSON payload;
// declared_type stays the same so no coercion is attempted. There
// is normally no old slot to tombstone — the field held none while
// it was non-filterable.
$engine->promoteFieldToFilterable(
    tenantId: 42,
    fieldId:  $fieldId,
);

// Turn indexing back off. Registry-only and effective on return:
// the slot tombstones for the Liberator to reclaim, no backfill
// window, and reads fall straight back to the JSON payload. From
// here on, filters against the field raise
// FieldNotFilterableException.
$engine->demoteFieldFromFilterable(
    tenantId: 42,
    fieldId:  $fieldId,
);
```

Only filterable fields occupy slots, so only a filterable field has anything to backfill. Retyping a field that is not filterable — or demoting one back to non-filterable — is a registry-only change: the metadata updates, any slot the field held is released, and the operation is complete when the call returns. There is no backfill window and nothing for the Reconciler to do, because the JSON payload was already the authoritative copy. A demoted field keeps reading correctly and immediately stops being a valid filter target.

Retypes between numeric / int and datetime are categorically rejected at registry-write time (`IncompatibleRetypeException`) — epoch interpretation is a caller policy, not engine behaviour; bridge through a `string` intermediate field if you need it. Initiating a second retype for the same field while one is already running throws `RetypeInProgressException`. The Reconciler picks up `running` retype checkpoints on every tick (alongside `stardust_sync_queue` and `stardust_import_jobs`); when the partition is exhausted it promotes the slot to `ready`, bumps `stardust_schema_version`, emits `promote_to_ready`, and triggers two one-shot advisory samples — `cardinality_sampled` for the new slot, and `spread_sampled` for the model, since a retype can move a field onto a page its model did not previously occupy.

## Renaming a field or model

```php
// Rename a model. Immediate and complete when it returns — a model
// name is a label, not an identity, so entries, slots, filters and
// exports all keep working untouched and there is no background
// catch-up to wait for. Throws ModelNameConflictException on a
// collision with another model in the same tenant.
$engine->renameModel(tenantId: 42, modelId: $modelId, newName: 'organization');

// Rename a field. Returns as soon as the registry is updated; the
// stored data catches up in the background and NEEDS A RUNNING
// RECONCILER. Because entry_data.fields is keyed by field name, this
// rewrites every entry in the model — not a registry-only change like
// promote/demote above.
$engine->renameField(tenantId: 42, fieldId: $fieldId, newName: 'company_size');
```

Nothing breaks while a field rename runs: reads return the value under the new name for every entry, migrated or not; a client still sending the old name keeps working, because inbound writes are rewritten to the new name before they are stored; and filters on the new name work from the moment the call returns, since a rename never disturbs the index. Filters using the *old* name are rejected outright (`UnknownFieldException`) rather than silently returning nothing, and the new name is not available for reuse elsewhere until the rewrite finishes (`FieldNameConflictException`). A field being renamed cannot be retyped, promoted, demoted, deleted, or compacted until the rewrite finishes.

## Removing a field

```php
// Delete a field and its stored values. Returns as soon as the
// registry is updated; clearing the values out of already-stored
// entries happens in the background and NEEDS A RUNNING RECONCILER.
//
// Returns false — rather than throwing — when there is nothing to
// do: the field doesn't exist for this tenant, or a deletion is
// already in flight. A repeated delete has already achieved what
// you asked for.
$deleted = $engine->deleteField(tenantId: 42, fieldId: $fieldId);
```

The field disappears from everything you can observe the moment the call returns: `read()`, `search()`, `get()` and `describeModel()` stop reporting it, filters against it raise `UnknownFieldException`, new CSV exports drop its column, writes still sending its name have the value dropped, and any index slot it held is released for the Liberator to reclaim.

What lags is the stored data. Each entry's JSON payload is keyed by field name, so removing a field rewrites every entry in the model. Until the Reconciler finishes that pass the values are still physically in `entry_data` — unreachable through the API, but visible in a raw table dump and in the JSON artifact of an export that happens to run during the window. The field's registry row is removed last, as the final step of that pass; that is the signal the deletion is complete.

**The name is not reusable until then.** Registering a new field with the same name on the same model raises `FieldDeletionInProgressException` instead of silently handing you back the field being deleted. A field cannot be deleted while it is being renamed or retyped, and once deletion starts it cannot be renamed, retyped, promoted, demoted or compacted. There is no undelete.

## Deleting a model

```php
// Removes a model, every field it owns, and every entry belonging to
// it. Returns as soon as the registry is updated — the data itself is
// destroyed in the background, so this needs a running Reconciler.
//
// Returns false — rather than throwing — when there is nothing to do:
// the model doesn't exist for this tenant, or a deletion is already in
// flight.
$deleted = $engine->deleteModel(tenantId: 42, modelId: $modelId);
```

**This is the only call in the library that physically deletes entry rows, and there is no undelete.** Entries, their indexed values, their queued writes, the field definitions and the model itself are all destroyed, and nothing keeps a copy. Export first if you might want the data back.

The model disappears from everything you can observe the moment the call returns: `listModels()` and `describeModel()` stop reporting it, and `read()`, `search()` and `get()` go dark — an empty page and `null`, exactly as if the model had never been registered.

**Writes are refused rather than ignored**, which is the one place this differs from deleting a field. `write()`, `updateEntry()`, `bulkWrite()` and `submitBulkWrite()` raise `ModelDeletionInProgressException`; `deleteEntry()` returns `false`. A field deletion quietly drops the deleted key from an incoming write because the rest of the entry is still worth storing — but an entry written to a model being erased has nowhere to live, so accepting it would either be a lie or leave a row stranded. `compactModel()` and `submitExport()` are refused for the same reason.

What lags is the data itself. The Reconciler deletes the entries in bounded chunks and drops the model's registry row as the final step; that is the signal the deletion is complete. Until then the rows are still physically in `entry_data` — unreachable through the API, but visible in a raw table dump, and an export already claimed by the Chronicler when you called this will produce an empty artifact rather than failing.

**The model's name is not reusable until then.** `createModel()` / `defineModel()` raise `ModelDeletionInProgressException` instead of silently handing you back the model being deleted — worth knowing if a seed script re-runs during the window. A model cannot be deleted while any of its fields is being renamed, retyped or deleted; conversely, once model deletion starts, none of those can be started on its fields.
