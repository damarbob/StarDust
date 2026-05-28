<?php

declare(strict_types=1);

namespace StarDust\Filter\Ast;

/**
 * The right-hand side of a {@see LeafNode}.
 *
 * One scalar for the single-value operators (`eq`, `neq`, `lt`, `lte`,
 * `gt`, `gte`, `prefix`); a list for set / range operators (`in`,
 * `nin`, `between`). Presence operators (`is_null`, `is_not_null`)
 * carry a `null` `TypedValue` in the leaf — the constructor is never
 * called for them.
 *
 * The decoder fills `$value` with the JSON-decoded native PHP primitive
 * (string, int, float, bool, or list thereof). Type compatibility
 * against the field's `declared_type` is verified later by
 * `ValueTypeValidator` once the field is resolved.
 */
final class TypedValue
{
    /**
     * @param scalar|list<scalar> $value
     */
    public function __construct(
        public readonly mixed $value,
    ) {
    }
}
