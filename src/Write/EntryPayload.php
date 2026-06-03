<?php

declare(strict_types=1);

namespace StarDust\Write;

use JsonException;
use StarDust\Exception\MalformedEntryPayloadException;

/**
 * Single-entry write payload.
 *
 * `fields` is the consumer's logical entry data as an associative
 * array, keyed by `stardust_fields.name`. Per ADR 0013 this JSON
 * payload is the system of record — slot columns are materializations
 * — so unmapped field names are persisted in `entry_data.fields`
 * untouched and retrieved later via `JSON_EXTRACT`.
 *
 * The DTO is intentionally minimal. Callers that need to coordinate
 * with upstream identifiers should map their identifiers onto
 * `tenant_id` / `model_id` themselves; this engine does not perform
 * authentication or model resolution.
 *
 * Because an entry *is* JSON (ADR 0013), the same DTO can be built from
 * a raw array or JSON string for callers that receive entries off a
 * wire (CMS / dynamic UI) rather than hand-constructing them:
 *
 *   - {@see self::fromArray()} / {@see self::fromJson()} — one entry
 *     from a `{tenantId, modelId, fields}` envelope.
 *   - {@see self::listFromArray()} / {@see self::listFromJson()} — a
 *     batch (JSON array of envelopes) for {@see BulkIngestor} /
 *     {@see BulkIngestSubmitter}.
 *
 * These are *convergent* factories: they only validate the envelope
 * shape and return an ordinary `EntryPayload`, so every value flows
 * through the identical write path as the typed constructor (same
 * `TenantId::assertValid()` boundary, same {@see PayloadSplitter}
 * coercion / {@see \StarDust\Exception\UncoercibleSlotValueException}).
 * Structural-shape failures raise
 * {@see MalformedEntryPayloadException}; the `tenant_id >= 1` rule is
 * intentionally NOT checked here — it stays at the write boundary so a
 * factory-built payload behaves identically to `new EntryPayload(...)`.
 */
final class EntryPayload
{
    /**
     * @param array<string, mixed> $fields Field name → value map.
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $modelId,
        public readonly array $fields,
    ) {
    }

    /**
     * Builds one payload from a decoded `{tenantId, modelId, fields}`
     * envelope (camelCase keys). Unknown top-level keys are ignored
     * (forward-compatible, mirroring the wire-format decoder's runtime
     * leniency).
     *
     * @param array<array-key, mixed> $data
     *
     * @throws MalformedEntryPayloadException when a required key is
     *     missing or mistyped, or `fields` is not a name→value map.
     */
    public static function fromArray(array $data): self
    {
        return self::build($data, null);
    }

    /**
     * Builds one payload from a raw JSON object string. Delegates the
     * shape checks to {@see self::fromArray()}.
     *
     * No byte cap is imposed: a single entry can be legitimately large,
     * and the sync/async ingest split is by entity *count*, not bytes.
     *
     * @throws MalformedEntryPayloadException on unparseable JSON, a
     *     non-object root, or any {@see self::fromArray()} violation.
     */
    public static function fromJson(string $json): self
    {
        return self::fromArray(self::decodeObject($json));
    }

    /**
     * Builds a batch from a list of decoded envelopes. Per-element
     * violations are surfaced with an index-qualified key (e.g.
     * `[3].modelId`).
     *
     * @param array<array-key, mixed> $data
     *
     * @return list<self>
     *
     * @throws MalformedEntryPayloadException when `$data` is not a list,
     *     an element is not an object, or any element fails its shape
     *     check.
     */
    public static function listFromArray(array $data): array
    {
        if ($data !== [] && !array_is_list($data)) {
            throw new MalformedEntryPayloadException('entry payload list must be a JSON array');
        }

        $payloads = [];
        foreach ($data as $i => $element) {
            if (!is_array($element)) {
                throw new MalformedEntryPayloadException(
                    "entry payload [{$i}] must be a JSON object",
                    "[{$i}]",
                );
            }
            /** @var array<array-key, mixed> $element */
            $payloads[] = self::build($element, "[{$i}]");
        }

        return $payloads;
    }

    /**
     * Builds a batch from a raw JSON array string of envelopes.
     *
     * @return list<self>
     *
     * @throws MalformedEntryPayloadException on unparseable JSON, a
     *     non-array root, or any {@see self::listFromArray()} violation.
     */
    public static function listFromJson(string $json): array
    {
        return self::listFromArray(self::decodeArray($json));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function build(array $data, ?string $keyPrefix): self
    {
        $tenantId = self::requireInt($data, 'tenantId', $keyPrefix);
        $modelId  = self::requireInt($data, 'modelId', $keyPrefix);
        $fields   = self::requireFields($data, $keyPrefix);

        return new self($tenantId, $modelId, $fields);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private static function requireInt(array $data, string $key, ?string $keyPrefix): int
    {
        if (!array_key_exists($key, $data)) {
            throw self::malformed("entry payload is missing required \"{$key}\"", $key, $keyPrefix);
        }
        $value = $data[$key];
        if (!is_int($value)) {
            throw self::malformed("entry payload \"{$key}\" must be an integer", $key, $keyPrefix);
        }
        return $value;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function requireFields(array $data, ?string $keyPrefix): array
    {
        if (!array_key_exists('fields', $data)) {
            throw self::malformed('entry payload is missing required "fields"', 'fields', $keyPrefix);
        }
        $fields = $data['fields'];
        // A field set is a name→value map. An empty object (`{}` →
        // `[]`) is allowed; a non-empty JSON array (list) is not a map.
        if (!is_array($fields) || ($fields !== [] && array_is_list($fields))) {
            throw self::malformed(
                'entry payload "fields" must be a JSON object (field name → value map)',
                'fields',
                $keyPrefix,
            );
        }
        /** @var array<string, mixed> $fields */
        return $fields;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decodeObject(string $json): array
    {
        // `json_decode(..., true)` collapses JSON `{}` and `[]` to the
        // same PHP `[]`, so a first-char probe is the only way to
        // reject an array / scalar root distinctly.
        $trimmed = ltrim($json);
        if ($trimmed === '' || $trimmed[0] !== '{') {
            throw new MalformedEntryPayloadException('entry payload must be a JSON object');
        }
        $decoded = self::decode($json);
        if (!is_array($decoded)) {
            throw new MalformedEntryPayloadException('entry payload must be a JSON object');
        }
        return $decoded;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decodeArray(string $json): array
    {
        $trimmed = ltrim($json);
        if ($trimmed === '' || $trimmed[0] !== '[') {
            throw new MalformedEntryPayloadException('entry payload list must be a JSON array');
        }
        $decoded = self::decode($json);
        if (!is_array($decoded)) {
            throw new MalformedEntryPayloadException('entry payload list must be a JSON array');
        }
        return $decoded;
    }

    private static function decode(string $json): mixed
    {
        try {
            return json_decode($json, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new MalformedEntryPayloadException('entry payload is not valid JSON: ' . $e->getMessage());
        }
    }

    private static function malformed(string $message, string $key, ?string $keyPrefix): MalformedEntryPayloadException
    {
        $qualified = $keyPrefix === null ? $key : "{$keyPrefix}.{$key}";
        return new MalformedEntryPayloadException($message, $qualified);
    }
}
