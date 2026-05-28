<?php

declare(strict_types=1);

namespace StarDust\Filter;

/**
 * The closed 13-code error discriminator set per the QueryFilter
 * wire-format blueprint §4.7 table.
 *
 * Every {@see QueryFilterValidationException} carries exactly one of
 * these as its `$code`. The set is closed; adding a new code requires
 * a blueprint amendment.
 */
final class ValidationErrorCode
{
    public const ENVELOPE_MALFORMED     = 'envelope_malformed';
    public const NODE_MALFORMED         = 'node_malformed';
    public const OPERATOR_UNKNOWN       = 'operator_unknown';
    public const CAPABILITY_UNSUPPORTED = 'capability_unsupported';
    public const FIELD_UNKNOWN          = 'field_unknown';
    public const FIELD_NOT_FILTERABLE   = 'field_not_filterable';
    public const VALUE_TYPE_MISMATCH    = 'value_type_mismatch';
    public const VALUE_COUNT_MISMATCH   = 'value_count_mismatch';
    public const VALUE_UNEXPECTED       = 'value_unexpected';
    public const VALUE_OUT_OF_BOUNDS    = 'value_out_of_bounds';
    public const NESTING_TOO_DEEP       = 'nesting_too_deep';
    public const NODE_COUNT_EXCEEDED    = 'node_count_exceeded';
    public const VERSION_UNSUPPORTED    = 'version_unsupported';

    /** @var list<string> */
    public const ALL = [
        self::ENVELOPE_MALFORMED,
        self::NODE_MALFORMED,
        self::OPERATOR_UNKNOWN,
        self::CAPABILITY_UNSUPPORTED,
        self::FIELD_UNKNOWN,
        self::FIELD_NOT_FILTERABLE,
        self::VALUE_TYPE_MISMATCH,
        self::VALUE_COUNT_MISMATCH,
        self::VALUE_UNEXPECTED,
        self::VALUE_OUT_OF_BOUNDS,
        self::NESTING_TOO_DEEP,
        self::NODE_COUNT_EXCEEDED,
        self::VERSION_UNSUPPORTED,
    ];
}
