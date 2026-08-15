# Filter AST + JSON decoder

Phase 8 input language. Execution lives in `src/Search/`; this package is pure input handling and holds no database dependency.

## AST

`Ast/FilterNode` is the marker interface. Concrete classes: `AndNode`, `OrNode`, `NotNode` (composites with distinct shapes), and `LeafNode` — a **single** class tagged by its `string $operator` field, so ADR 0022 capability extensions can be added without subclassing.

`FieldRef` carries the wire form (`modelName`, `fieldName`) plus the optionally-resolved `(modelId, fieldId, FieldDescriptor)`; `withResolved()` returns a new immutable instance.

`Operator` declares the closed-v1 leaf set (12 operators: `eq`, `neq`, `lt`, `lte`, `gt`, `gte`, `in`, `nin`, `prefix`, `between`, `is_null`, `is_not_null`) and the composite vocabulary.

`FilterLimits` is the bounds DTO: max depth 8, max nodes 256, max args 64, max in-elements 1024, max string 4096 chars, max payload 64 KiB.

## `Json/JsonFilterDecoder`

A pure transformer (`string → ?FilterNode`) using `JSON_THROW_ON_ERROR` and a recursive `decodeNode()` that threads a `JsonPointer` plus a `DecodeContext` counter through every step. Fail-fast on the first violation.

**The decoder does NOT consult the registry** — it is reusable in offline tooling. Keep it that way.

Set operators (`in`/`nin`) deduplicate at decode time, per blueprint §4.3 AC#9.

## The 13-code error taxonomy

Rejections raise `QueryFilterValidationException` (final, RuntimeException) carrying `$errorCode` (one of the 13 closed `ValidationErrorCode` values), `$jsonPointer` (RFC 6901), and `$details`.

Note the discriminator field is **`$errorCode`, not `$code`** — `Exception::$code` is already declared non-readonly on the base class and PHP 8.4 forbids redeclaring it readonly.

The decoder owns nine codes: `envelope_malformed`, `node_malformed`, `operator_unknown`, `value_count_mismatch`, `value_unexpected`, `value_out_of_bounds`, `nesting_too_deep`, `node_count_exceeded`, `version_unsupported`.

Pre-flight (`src/Search/PreFlight/`) owns the remaining four: `field_unknown`, `capability_unsupported`, `field_not_filterable`, `value_type_mismatch`. Three of those reuse pre-existing Phase 4 exception classes rather than the discriminator — see `src/Exception/CLAUDE.md`.

## Schema conformance

The normative wire-format JSON Schema (draft 2020-12) ships in-package at `schemas/queryfilter.schema.json`, copied from `SDDPG/schemas/` — **the design repo is the source of truth; change it there first.**

`tests/Smoke/Filter/QueryFilterSchemaConformanceTest` is the DB-free cross-check: it runs a labelled payload corpus through BOTH the schema (via the `opis/json-schema` dev-dependency) and `JsonFilterDecoder`, and fails on any accept/reject divergence — locking the two together so they cannot silently drift.

The corpus is curated to the rules they share. Two documented strictness asymmetries are asserted separately, not in the shared corpus:

- The schema's `additionalProperties:false` rejects unknown node keys the decoder ignores.
- The decoder's `in`/`nin` dedup and its depth / node-count / payload-byte caps have no schema equivalent.
