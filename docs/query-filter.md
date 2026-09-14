# Searching with the JSON wire format

Consumers (HTTP gateways, RPC layers) typically receive filters as JSON. Decode them with `JsonFilterDecoder`, then call `search()` with the resulting AST:

```php
use StarDust\Filter\Json\JsonFilterDecoder;
use StarDust\Search\SearchRequest;

$decoder = new JsonFilterDecoder($engine->config()->queryFilterLimits);
$filter  = $decoder->decode($requestBody);
$result  = $engine->search(new SearchRequest(
    tenantId: 42,
    modelId:  $modelId,
    filter:   $filter,
    pageSize: 100,
));
```

A typical wire payload:

```json
{
  "version": "1",
  "filter": {
    "op": "and",
    "args": [
      { "op": "eq",    "field": { "model": "invoice", "name": "status" }, "value": "paid" },
      { "op": "gt",    "field": { "model": "invoice", "name": "amount" }, "value": 100   },
      { "op": "is_not_null", "field": { "model": "invoice", "name": "due_date" } }
    ]
  }
}
```

The decoder enforces a closed 13-code error taxonomy (`envelope_malformed`, `node_malformed`, `operator_unknown`, `value_count_mismatch`, `value_unexpected`, `value_out_of_bounds`, `nesting_too_deep`, `node_count_exceeded`, `version_unsupported`, plus pre-flight `field_unknown`, `field_not_filterable`, `capability_unsupported`, `value_type_mismatch`). Every rejection carries an RFC 6901 JSON Pointer to the offending node.

**A `datetime` value must carry an explicit UTC offset** — either a trailing `Z` or `±HH:MM`. A naive `2026-01-01T10:00:00` is rejected with `value_type_mismatch`, because a stored datetime is always UTC and guessing what zone the caller meant is not the engine's to do. The offset you send is then *applied*: the engine converts the bound to the instant it names before matching, so filtering `2026-01-01T10:00:00+07:00` finds the entry you wrote as `2026-01-01T10:00:00+07:00`, whatever offset either was expressed in. Fractional seconds are accepted and honoured at comparison time, though slot columns store whole seconds.

The wire format also ships as a normative JSON Schema (draft 2020-12) at [`schemas/queryfilter.schema.json`](../schemas/queryfilter.schema.json), for consumer-side validation in any language and for CI cross-checks. A smoke test (`QueryFilterSchemaConformanceTest`) runs a payload corpus through both the schema and `JsonFilterDecoder` and fails if their accept/reject verdicts ever diverge, keeping the two in lockstep.
