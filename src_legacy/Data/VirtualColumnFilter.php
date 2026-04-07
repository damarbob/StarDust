<?php

namespace StarDust\Data;

/**
 * Represents a filter condition for a virtual column.
 *
 * Enforces an operator whitelist to prevent SQL injection,
 * since CI4's where() interpolates the operator into the query string.
 */
class VirtualColumnFilter
{
    /** @var string[] Operators safe to interpolate into WHERE clauses. */
    private const ALLOWED_OPERATORS = ['=', '!=', '>', '>=', '<', '<=', 'LIKE'];

    public function __construct(
        public readonly string $field,
        public readonly mixed  $value,
        public readonly string $operator = '=',
    ) {
        if (!in_array($this->operator, self::ALLOWED_OPERATORS, true)) {
            throw new \InvalidArgumentException(
                lang('StarDust.unsupportedFilterOperator', ['operator' => $this->operator])
            );
        }
    }
}
