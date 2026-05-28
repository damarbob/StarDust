<?php

declare(strict_types=1);

namespace StarDust\Filter\Json;

use JsonException;
use StarDust\Filter\Ast\AndNode;
use StarDust\Filter\Ast\FieldRef;
use StarDust\Filter\Ast\FilterNode;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Filter\Ast\NotNode;
use StarDust\Filter\Ast\OrNode;
use StarDust\Filter\Ast\TypedValue;
use StarDust\Filter\Limits\FilterLimits;
use StarDust\Filter\Operator;
use StarDust\Filter\QueryFilterValidationException;
use StarDust\Filter\ValidationErrorCode;

/**
 * Phase 8 wire-format decoder per QueryFilter wire-format blueprint §4.
 *
 * Pure transformer: `string $rawJson` + bounds → `?FilterNode`. Stateless
 * apart from the injected {@see FilterLimits}. Throws
 * {@see QueryFilterValidationException} on the structural error codes
 * (the decoder never consults the schema registry — `field_unknown`,
 * `field_not_filterable`, `value_type_mismatch` are pre-flight concerns).
 *
 * The decoder enforces:
 *   - payload size cap (`value_out_of_bounds`)
 *   - JSON well-formedness (`envelope_malformed`)
 *   - envelope shape & version (`envelope_malformed`, `version_unsupported`,
 *     `node_malformed`)
 *   - per-node required keys (`node_malformed`)
 *   - operator vocabulary (`operator_unknown`)
 *   - composite `args` / `arg` arity (`value_count_mismatch`)
 *   - leaf value shape per operator class (`value_count_mismatch`,
 *     `value_unexpected`, `value_out_of_bounds`)
 *   - tree depth & total node count (`nesting_too_deep`,
 *     `node_count_exceeded`)
 *
 * Validation is fail-fast: only the first violation is surfaced.
 */
final class JsonFilterDecoder
{
    public function __construct(
        private readonly FilterLimits $limits = new FilterLimits(),
    ) {
    }

    /**
     * Parses the consumer's request envelope and returns the AST root.
     * `null` is the normative match-all signal — the envelope's `filter`
     * key was absent (per blueprint §4.1 AC#2).
     */
    public function decode(string $rawJson): ?FilterNode
    {
        if (strlen($rawJson) > $this->limits->maxPayloadBytes) {
            throw $this->raise(
                ValidationErrorCode::VALUE_OUT_OF_BOUNDS,
                JsonPointer::root(),
                "filter payload {$this->byteLen($rawJson)} bytes exceeds {$this->limits->maxPayloadBytes} bytes",
                ['observed' => strlen($rawJson), 'limit' => $this->limits->maxPayloadBytes],
            );
        }

        // `json_decode(..., true)` collapses JSON `{}` and `[]` to the
        // same PHP `[]`, so we cannot distinguish them after decoding.
        // Reject any envelope whose first non-whitespace character is
        // not `{` — this catches array roots, JSON literals, numbers,
        // etc., before they reach the recursive walk.
        $trimmed = ltrim($rawJson);
        if ($trimmed === '' || $trimmed[0] !== '{') {
            throw $this->raise(
                ValidationErrorCode::ENVELOPE_MALFORMED,
                JsonPointer::root(),
                'envelope must be a JSON object',
            );
        }

        try {
            $envelope = json_decode($rawJson, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw $this->raise(
                ValidationErrorCode::ENVELOPE_MALFORMED,
                JsonPointer::root(),
                'envelope is not valid JSON: ' . $e->getMessage(),
            );
        }

        if (!is_array($envelope)) {
            throw $this->raise(
                ValidationErrorCode::ENVELOPE_MALFORMED,
                JsonPointer::root(),
                'envelope must be a JSON object',
            );
        }

        if (array_key_exists('version', $envelope)) {
            $version = $envelope['version'];
            if ($version !== '1') {
                throw $this->raise(
                    ValidationErrorCode::VERSION_UNSUPPORTED,
                    JsonPointer::root()->child('version'),
                    'unknown filter version; expected "1"',
                    ['received' => $version],
                );
            }
        }

        if (!array_key_exists('filter', $envelope)) {
            return null;
        }

        $filter = $envelope['filter'];
        $filterPointer = JsonPointer::root()->child('filter');

        if ($filter === null) {
            throw $this->raise(
                ValidationErrorCode::NODE_MALFORMED,
                $filterPointer,
                'filter must be an object — use omit-the-key for match-all',
            );
        }
        if (!is_array($filter) || !$this->isAssocArray($filter)) {
            throw $this->raise(
                ValidationErrorCode::ENVELOPE_MALFORMED,
                $filterPointer,
                'filter must be a JSON object',
            );
        }

        return $this->decodeNode($filter, $filterPointer, 1, new DecodeContext());
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function decodeNode(array $raw, JsonPointer $pointer, int $depth, DecodeContext $ctx): FilterNode
    {
        ++$ctx->nodeCount;
        if ($ctx->nodeCount > $this->limits->maxNodes) {
            throw $this->raise(
                ValidationErrorCode::NODE_COUNT_EXCEEDED,
                $pointer,
                "filter tree node count exceeds {$this->limits->maxNodes}",
                ['limit' => $this->limits->maxNodes],
            );
        }
        if ($depth > $this->limits->maxDepth) {
            throw $this->raise(
                ValidationErrorCode::NESTING_TOO_DEEP,
                $pointer,
                "filter tree depth exceeds {$this->limits->maxDepth}",
                ['limit' => $this->limits->maxDepth],
            );
        }

        if (!array_key_exists('op', $raw) || !is_string($raw['op'])) {
            throw $this->raise(
                ValidationErrorCode::NODE_MALFORMED,
                $pointer,
                'node is missing a string "op" key',
            );
        }
        $op = $raw['op'];

        return match (true) {
            $op === Operator::COMPOSITE_AND => $this->decodeAnd($raw, $pointer, $depth, $ctx),
            $op === Operator::COMPOSITE_OR  => $this->decodeOr($raw, $pointer, $depth, $ctx),
            $op === Operator::COMPOSITE_NOT => $this->decodeNot($raw, $pointer, $depth, $ctx),
            Operator::isClosedV1Leaf($op)   => $this->decodeLeaf($op, $raw, $pointer),
            default => throw $this->raise(
                ValidationErrorCode::OPERATOR_UNKNOWN,
                $pointer->child('op'),
                "operator \"{$op}\" is not in the v1 closed set",
                ['received' => $op],
            ),
        };
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function decodeAnd(array $raw, JsonPointer $pointer, int $depth, DecodeContext $ctx): AndNode
    {
        $args = $this->requireCompositeArgs($raw, $pointer);
        $children = [];
        foreach ($args as $i => $child) {
            $childPointer = $pointer->child('args')->child($i);
            $this->requireObjectChild($child, $childPointer);
            /** @var array<string, mixed> $child */
            $children[] = $this->decodeNode($child, $childPointer, $depth + 1, $ctx);
        }
        return new AndNode($children);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function decodeOr(array $raw, JsonPointer $pointer, int $depth, DecodeContext $ctx): OrNode
    {
        $args = $this->requireCompositeArgs($raw, $pointer);
        $children = [];
        foreach ($args as $i => $child) {
            $childPointer = $pointer->child('args')->child($i);
            $this->requireObjectChild($child, $childPointer);
            /** @var array<string, mixed> $child */
            $children[] = $this->decodeNode($child, $childPointer, $depth + 1, $ctx);
        }
        return new OrNode($children);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function decodeNot(array $raw, JsonPointer $pointer, int $depth, DecodeContext $ctx): NotNode
    {
        if (!array_key_exists('arg', $raw)) {
            throw $this->raise(
                ValidationErrorCode::NODE_MALFORMED,
                $pointer,
                '"not" node requires singular "arg" child',
            );
        }
        $child = $raw['arg'];
        $childPointer = $pointer->child('arg');
        $this->requireObjectChild($child, $childPointer);
        /** @var array<string, mixed> $child */
        return new NotNode($this->decodeNode($child, $childPointer, $depth + 1, $ctx));
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function decodeLeaf(string $op, array $raw, JsonPointer $pointer): LeafNode
    {
        $field = $this->decodeFieldRef($raw, $pointer);
        $valuePresent = array_key_exists('value', $raw);

        if (in_array($op, Operator::PRESENCE, true)) {
            if ($valuePresent) {
                throw $this->raise(
                    ValidationErrorCode::VALUE_UNEXPECTED,
                    $pointer->child('value'),
                    "\"{$op}\" must not carry a value key",
                );
            }
            return new LeafNode($op, $field, null);
        }

        if (!$valuePresent) {
            throw $this->raise(
                ValidationErrorCode::NODE_MALFORMED,
                $pointer,
                "\"{$op}\" leaf requires a \"value\" key",
            );
        }

        $value = $raw['value'];
        $valuePointer = $pointer->child('value');
        $typed = match (true) {
            in_array($op, Operator::SINGLE_VALUE, true) => $this->decodeSingleValue($op, $value, $valuePointer),
            in_array($op, Operator::SET, true)          => $this->decodeSetValue($op, $value, $valuePointer),
            in_array($op, Operator::RANGE, true)        => $this->decodeRangeValue($op, $value, $valuePointer),
            default => throw $this->unreachable($pointer, $op),
        };
        return new LeafNode($op, $field, $typed);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function decodeFieldRef(array $raw, JsonPointer $pointer): FieldRef
    {
        if (!array_key_exists('field', $raw)) {
            throw $this->raise(
                ValidationErrorCode::NODE_MALFORMED,
                $pointer,
                'leaf node is missing required "field" key',
            );
        }
        $field = $raw['field'];
        $fieldPointer = $pointer->child('field');
        if (!is_array($field) || !$this->isAssocArray($field)) {
            throw $this->raise(
                ValidationErrorCode::NODE_MALFORMED,
                $fieldPointer,
                '"field" must be a JSON object with "model" and "name" keys',
            );
        }
        if (!array_key_exists('model', $field) || !is_string($field['model']) || $field['model'] === '') {
            throw $this->raise(
                ValidationErrorCode::NODE_MALFORMED,
                $fieldPointer->child('model'),
                '"field.model" must be a non-empty string',
            );
        }
        if (!array_key_exists('name', $field) || !is_string($field['name']) || $field['name'] === '') {
            throw $this->raise(
                ValidationErrorCode::NODE_MALFORMED,
                $fieldPointer->child('name'),
                '"field.name" must be a non-empty string',
            );
        }
        return new FieldRef(modelName: $field['model'], fieldName: $field['name']);
    }

    private function decodeSingleValue(string $op, mixed $value, JsonPointer $pointer): TypedValue
    {
        if (is_array($value)) {
            throw $this->raise(
                ValidationErrorCode::NODE_MALFORMED,
                $pointer,
                "\"{$op}\" requires a single scalar value, got an array",
            );
        }
        if (!is_scalar($value)) {
            throw $this->raise(
                ValidationErrorCode::NODE_MALFORMED,
                $pointer,
                "\"{$op}\" requires a scalar value (string, number, or boolean)",
            );
        }
        if ($op === Operator::PREFIX) {
            if (!is_string($value)) {
                throw $this->raise(
                    ValidationErrorCode::NODE_MALFORMED,
                    $pointer,
                    '"prefix" value must be a string',
                );
            }
            if ($value === '') {
                throw $this->raise(
                    ValidationErrorCode::VALUE_OUT_OF_BOUNDS,
                    $pointer,
                    '"prefix" value must not be empty — omit the filter for match-all',
                );
            }
            $this->guardStringLength($value, $pointer);
        } elseif (is_string($value)) {
            $this->guardStringLength($value, $pointer);
        }
        return new TypedValue($value);
    }

    private function decodeSetValue(string $op, mixed $value, JsonPointer $pointer): TypedValue
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->raise(
                ValidationErrorCode::NODE_MALFORMED,
                $pointer,
                "\"{$op}\" requires a JSON array of scalars",
            );
        }
        $count = count($value);
        if ($count === 0) {
            throw $this->raise(
                ValidationErrorCode::VALUE_COUNT_MISMATCH,
                $pointer,
                "\"{$op}\" array must contain at least one element",
            );
        }
        if ($count > $this->limits->maxInElements) {
            throw $this->raise(
                ValidationErrorCode::VALUE_OUT_OF_BOUNDS,
                $pointer,
                "\"{$op}\" array length {$count} exceeds maximum {$this->limits->maxInElements}",
                ['observed' => $count, 'limit' => $this->limits->maxInElements],
            );
        }
        $deduped = [];
        $seen = [];
        foreach ($value as $i => $element) {
            if (!is_scalar($element)) {
                throw $this->raise(
                    ValidationErrorCode::NODE_MALFORMED,
                    $pointer->child($i),
                    "\"{$op}\" array elements must be scalars",
                );
            }
            if (is_string($element)) {
                $this->guardStringLength($element, $pointer->child($i));
            }
            $key = $this->scalarSeenKey($element);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $element;
        }
        return new TypedValue($deduped);
    }

    private function decodeRangeValue(string $op, mixed $value, JsonPointer $pointer): TypedValue
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw $this->raise(
                ValidationErrorCode::NODE_MALFORMED,
                $pointer,
                '"between" requires a 2-element JSON array',
            );
        }
        if (count($value) !== 2) {
            throw $this->raise(
                ValidationErrorCode::VALUE_COUNT_MISMATCH,
                $pointer,
                '"between" requires exactly two elements',
                ['observed' => count($value)],
            );
        }
        foreach ($value as $i => $element) {
            if (!is_scalar($element)) {
                throw $this->raise(
                    ValidationErrorCode::NODE_MALFORMED,
                    $pointer->child($i),
                    '"between" elements must be scalars',
                );
            }
            if (is_string($element)) {
                $this->guardStringLength($element, $pointer->child($i));
            }
        }
        return new TypedValue($value);
    }

    /**
     * @param array<string, mixed> $raw
     * @return list<mixed>
     */
    private function requireCompositeArgs(array $raw, JsonPointer $pointer): array
    {
        if (!array_key_exists('args', $raw)) {
            throw $this->raise(
                ValidationErrorCode::NODE_MALFORMED,
                $pointer,
                'composite node requires "args" array',
            );
        }
        $args = $raw['args'];
        $argsPointer = $pointer->child('args');
        if (!is_array($args) || !array_is_list($args)) {
            throw $this->raise(
                ValidationErrorCode::NODE_MALFORMED,
                $argsPointer,
                '"args" must be a JSON array',
            );
        }
        $count = count($args);
        if ($count < 1) {
            throw $this->raise(
                ValidationErrorCode::VALUE_COUNT_MISMATCH,
                $argsPointer,
                '"args" must contain at least one child',
            );
        }
        if ($count > $this->limits->maxArgs) {
            throw $this->raise(
                ValidationErrorCode::VALUE_OUT_OF_BOUNDS,
                $argsPointer,
                '"args" length ' . $count . " exceeds maximum {$this->limits->maxArgs}",
                ['observed' => $count, 'limit' => $this->limits->maxArgs],
            );
        }
        return $args;
    }

    private function requireObjectChild(mixed $child, JsonPointer $pointer): void
    {
        if (!is_array($child) || !$this->isAssocArray($child)) {
            throw $this->raise(
                ValidationErrorCode::NODE_MALFORMED,
                $pointer,
                'child must be a JSON object',
            );
        }
    }

    private function guardStringLength(string $value, JsonPointer $pointer): void
    {
        $length = mb_strlen($value);
        if ($length > $this->limits->maxStringLength) {
            throw $this->raise(
                ValidationErrorCode::VALUE_OUT_OF_BOUNDS,
                $pointer,
                "string value of length {$length} exceeds maximum {$this->limits->maxStringLength}",
                ['observed' => $length, 'limit' => $this->limits->maxStringLength],
            );
        }
    }

    private function isAssocArray(array $value): bool
    {
        // PHP's json_decode(..., true) of a JSON object always yields an
        // assoc array. JSON arrays yield list arrays. We treat anything
        // list-shaped (or empty) as non-object for safety — '{}' decodes
        // to []  which we accept as an empty associative array because
        // array_is_list([]) === true would otherwise mistakenly classify
        // empty objects as arrays.
        if ($value === []) {
            return true;
        }
        return !array_is_list($value);
    }

    private function scalarSeenKey(string|int|float|bool $value): string
    {
        // gettype prefix prevents collapsing `1` (int) and `"1"` (string)
        // into the same dedup bucket.
        return gettype($value) . ':' . (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
    }

    private function byteLen(string $raw): int
    {
        return strlen($raw);
    }

    /**
     * @param array<string, mixed> $details
     */
    private function raise(string $code, JsonPointer $pointer, string $message, array $details = []): QueryFilterValidationException
    {
        return new QueryFilterValidationException(
            errorCode:   $code,
            jsonPointer: $pointer->toString(),
            message:     $message,
            details:     $details,
        );
    }

    private function unreachable(JsonPointer $pointer, string $op): QueryFilterValidationException
    {
        return $this->raise(
            ValidationErrorCode::OPERATOR_UNKNOWN,
            $pointer->child('op'),
            "operator \"{$op}\" has no decode path",
            ['received' => $op],
        );
    }
}
