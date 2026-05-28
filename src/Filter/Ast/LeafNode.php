<?php

declare(strict_types=1);

namespace StarDust\Filter\Ast;

/**
 * A predicate leaf: `<field> <operator> <value>`.
 *
 * The operator vocabulary is intentionally a `string` rather than an
 * enum so drivers can declare extension operators at runtime per
 * ADR 0022 (the engine's closed v1 set lives in {@see \StarDust\Filter\Operator}).
 *
 * `$value` is `null` only for the presence operators `is_null` and
 * `is_not_null`; every other operator MUST carry a {@see TypedValue}.
 * The decoder enforces this structural invariant.
 */
final class LeafNode implements FilterNode
{
    public function __construct(
        public readonly string $operator,
        public readonly FieldRef $field,
        public readonly ?TypedValue $value,
    ) {
    }

    /**
     * Returns a copy of this leaf with the field reference replaced by
     * its resolved form. Used by `FieldRefResolver` to thread the
     * registry-resolved tree to the driver without mutating the input.
     */
    public function withResolvedField(FieldRef $resolved): self
    {
        return new self($this->operator, $resolved, $this->value);
    }

    /**
     * Convenience builder for internal callers (and tests) that already
     * know the model from the surrounding `EntryQuery` / `SearchRequest`.
     * The emitted leaf's {@see FieldRef} has empty `modelName`, which the
     * pre-flight `FieldRefResolver` treats as "use the request's modelId".
     *
     * For presence operators pass `$value = null`; for set / range
     * operators pass a `list<scalar>`; otherwise a scalar.
     */
    public static function local(string $fieldName, string $operator, mixed $value): self
    {
        return new self(
            operator: $operator,
            field:    FieldRef::local($fieldName),
            value:    $value === null ? null : new TypedValue($value),
        );
    }
}
