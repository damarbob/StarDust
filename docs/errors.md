# Errors

All typed errors extend `RuntimeException`. They live under `StarDust\Exception\`, except `QueryFilterValidationException`, which is under `StarDust\Filter\`.

| Exception | Thrown when |
| :--- | :--- |
| `InvalidTenantIdException` | `tenantId` is `<= 0` (checked before any SQL at every entry point). |
| `PayloadTooLargeException` | A synchronous `bulkWrite()` exceeds 1 000 entities — use `submitBulkWrite()` instead. |
| `UncoercibleSlotValueException` | A first-write payload value cannot be coerced to its slot's declared type (the write path is fail-fast). |
| `MalformedEntryPayloadException` | An array/JSON entry envelope passed to `EntryPayload::fromArray()` / `fromJson()` / `listFrom*()` is structurally invalid — missing or mistyped `tenantId`/`modelId`/`fields`, a non-map `fields`, unparseable JSON, or a wrong root. Carries the offending `$key`. |
| `UnknownFieldException` | A filter or sort references a field absent from `stardust_fields`. |
| `FieldNotFilterableException` | A filter targets a field the active driver reports as non-filterable (for the default MySQL driver, `is_filterable = false`). |
| `FieldNotIndexedException` | A filter targets a field whose slot is `backfilling`, `tombstoned`, or unmapped. |
| `FieldNotSortableException` | A `SortSpec` names a field that isn't currently indexed — the same requirement filtering has. `describeModel()`'s `indexedFields()` reports which fields qualify right now. |
| `PageSizeOutOfRangeException` | `pageSize` is outside `[1, 1000]`. |
| `InvalidCursorException` | An opaque cursor fails its structural decode, or is reused after its sort key or direction changed. |
| `QueryFilterValidationException` | A JSON wire-format filter fails decode or pre-flight (see below). |
| `IncompatibleRetypeException` | A retype crosses a categorically rejected pair (`int ↔ datetime`, `numeric ↔ datetime`). |
| `RetypeInProgressException` | A retype is initiated for a field that already has one running — or a model is compacted while any of its fields is still being retyped, promoted, demoted or relocated. Wait for the Reconciler and retry. |
| `FieldNotFoundException` | `retypeField()` / `promoteFieldToFilterable()` / `demoteFieldFromFilterable()` / `renameField()` receive a field id that doesn't exist for the tenant. Note `deleteField()` returns `false` instead. |
| `NonFilterableFieldSlotException` | A slot reservation was attempted for a non-filterable field. Such fields live in the JSON payload only and never occupy a slot, so this signals a caller bug rather than a capacity problem — distinct from `FieldNotFilterableException`, which rejects a *query* that filters on one. |
| `EntryNotFoundException` | `updateEntry()` targets an entry that doesn't exist, belongs to another tenant, or is already deleted — silently discarding the update would lose data the caller believed it had written. |
| `RenameInProgressException` | Something targeted a field whose rename has started but whose background rewrite has not finished — a retype, deletion, or (for the model it belongs to) a model deletion. Wait for the Reconciler. |
| `FieldNameConflictException` | `renameField()` would collide with another field's name on the same model. |
| `CompactionCapacityException` | `compactModel()` (or a `retypeField()` reservation it triggers) cannot find enough free capacity on the target page set to complete a relocation. |
| `ExportJobActiveCapExceededException` | A tenant is already at its active-export cap (carries `$tenantId`, `$activeCount`, `$cap`). |
| `ExportFilterNotSupportedException` | `submitExport()` was given a non-empty `filter`; exports cover the whole model (carries `$tenantId`, `$modelId`, `$filterKeys`). |
| `ModelNotFoundException` | A model-level call named a `modelId` that does not exist for the caller's tenant (missing and cross-tenant are indistinguishable by design). |
| `ModelNameConflictException` | `renameModel()` would collide with another model's name in the same tenant. |
| `ModelDeletionInProgressException` | Something targeted a model whose deletion has started but whose background pass has not finished — a write, update, bulk submission, compaction or export submission against it, or an attempt to register a model or field reusing its name. Wait for the Reconciler. |
| `FieldDeletionInProgressException` | Something targeted a field whose deletion has started but whose background pass has not finished — a rename, retype, promotion, demotion or compaction of it, or an attempt to register a new field reusing its name. Wait for the Reconciler. |

## Handling wire-format rejections

`QueryFilterValidationException` is deliberately discriminator-style: a single `catch` handles every wire-format and pre-flight failure, because all of them share one caller response — fix the filter JSON and retry. It carries enough context to render a precise HTTP 4xx without a per-code handler:

- `$errorCode` — one of the closed `StarDust\Filter\ValidationErrorCode` constants.
- `$jsonPointer` — an RFC 6901 pointer to the offending node (e.g. `/filter/args/1/value`).
- `$details` — discriminator-specific context (e.g. `['expected' => 'int', 'received' => 'string']`).

```php
use StarDust\Filter\Json\JsonFilterDecoder;
use StarDust\Filter\QueryFilterValidationException;
use StarDust\Exception\UnknownFieldException;
use StarDust\Exception\FieldNotFilterableException;
use StarDust\Search\SearchRequest;

try {
    $filter = (new JsonFilterDecoder($engine->config()->queryFilterLimits))->decode($body);
    $result = $engine->search(new SearchRequest(
        tenantId: 42,
        modelId:  $modelId,
        filter:   $filter,
    ));
} catch (QueryFilterValidationException $e) {
    http_response_code(400);
    echo json_encode([
        'error'   => $e->errorCode,    // e.g. 'value_type_mismatch'
        'pointer' => $e->jsonPointer,  // e.g. '/filter/args/1/value'
        'details' => $e->details,
    ]);
} catch (UnknownFieldException | FieldNotFilterableException $e) {
    // The field_unknown and field_not_filterable cases reuse these
    // pre-existing exceptions rather than QueryFilterValidationException.
    http_response_code(400);
}
```
