<?php

declare(strict_types=1);

namespace StarDust\Filter;

/**
 * The closed v1 leaf-operator vocabulary per ADR 0021 plus the three
 * composite operators per the wire-format blueprint §4.2.
 *
 * Drivers MAY declare additional operators via
 * `EntrySearchInterface::supportedOperators()` (ADR 0022 capability
 * extensions); the runtime `CapabilityChecker` accepts those at
 * dispatch time even though they are absent from this list. The
 * decoder's `operator_unknown` rejection only fires for operators
 * not in the union of `CLOSED_V1` ∪ composites — the capability
 * upgrade happens in pre-flight.
 */
final class Operator
{
    public const EQ          = 'eq';
    public const NEQ         = 'neq';
    public const LT          = 'lt';
    public const LTE         = 'lte';
    public const GT          = 'gt';
    public const GTE         = 'gte';
    public const IN          = 'in';
    public const NIN         = 'nin';
    public const PREFIX      = 'prefix';
    public const BETWEEN     = 'between';
    public const IS_NULL     = 'is_null';
    public const IS_NOT_NULL = 'is_not_null';

    public const COMPOSITE_AND = 'and';
    public const COMPOSITE_OR  = 'or';
    public const COMPOSITE_NOT = 'not';

    /** @var list<string> */
    public const CLOSED_V1 = [
        self::EQ, self::NEQ, self::LT, self::LTE, self::GT, self::GTE,
        self::IN, self::NIN, self::PREFIX, self::BETWEEN,
        self::IS_NULL, self::IS_NOT_NULL,
    ];

    /** @var list<string> */
    public const SINGLE_VALUE = [
        self::EQ, self::NEQ, self::LT, self::LTE, self::GT, self::GTE, self::PREFIX,
    ];

    /** @var list<string> */
    public const SET = [self::IN, self::NIN];

    /** @var list<string> */
    public const RANGE = [self::BETWEEN];

    /** @var list<string> */
    public const PRESENCE = [self::IS_NULL, self::IS_NOT_NULL];

    /** @var list<string> */
    public const COMPOSITES = [self::COMPOSITE_AND, self::COMPOSITE_OR, self::COMPOSITE_NOT];

    public static function isClosedV1Leaf(string $op): bool
    {
        return in_array($op, self::CLOSED_V1, true);
    }

    public static function isComposite(string $op): bool
    {
        return in_array($op, self::COMPOSITES, true);
    }
}
