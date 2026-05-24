<?php

declare(strict_types=1);

namespace StarDust\Read;

use InvalidArgumentException;

/**
 * One leaf filter clause in an {@see EntryQuery}.
 *
 * The operator set matches the Phase 8 QueryFilter wire-format
 * vocabulary 1:1 so the future JSON parser maps straight to this DTO
 * without translation. AND-only composition is implicit in Phase 4 —
 * `or`/`not` are deferred to Phase 8.
 *
 * The `$value` shape depends on the operator:
 *   - `eq, neq, lt, lte, gt, gte, prefix`: a single scalar
 *     (string/int/float/datetime-string).
 *   - `in, nin`:                            a `list<scalar>`.
 *   - `between`:                            a 2-element `list<scalar>`
 *                                           ordered low → high.
 *   - `is_null, is_not_null`:               value MUST be `null` (the
 *                                           operator is the entire clause).
 *
 * Per ADR 0014 / 0004 the engine performs **no runtime range checks**
 * on the value — type-correctness against the field's declared_type
 * is the consumer's contract (and on the wire, Phase 8's schema
 * validator). The PDO parameter binding handles SQL injection safety
 * regardless of value content.
 */
final class QueryFilter
{
    public const OPERATORS = [
        'eq', 'neq', 'lt', 'lte', 'gt', 'gte',
        'in', 'nin',
        'prefix',
        'between',
        'is_null', 'is_not_null',
    ];

    public function __construct(
        public readonly string $fieldName,
        public readonly string $operator,
        public readonly mixed $value,
    ) {
        if (! in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException(
                "QueryFilter: unsupported operator '{$operator}'. "
                . 'Allowed: ' . implode(', ', self::OPERATORS) . '.'
            );
        }
        if (($operator === 'is_null' || $operator === 'is_not_null') && $value !== null) {
            throw new InvalidArgumentException(
                "QueryFilter: operator '{$operator}' takes no value."
            );
        }
        if ($operator === 'between') {
            if (! is_array($value) || count($value) !== 2 || ! array_is_list($value)) {
                throw new InvalidArgumentException(
                    "QueryFilter: operator 'between' requires a 2-element list [low, high]."
                );
            }
        }
        if (($operator === 'in' || $operator === 'nin')
            && (! is_array($value) || ! array_is_list($value) || $value === [])
        ) {
            throw new InvalidArgumentException(
                "QueryFilter: operator '{$operator}' requires a non-empty list."
            );
        }
    }
}
