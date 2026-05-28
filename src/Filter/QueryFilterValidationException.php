<?php

declare(strict_types=1);

namespace StarDust\Filter;

use RuntimeException;

/**
 * Phase 8 wire-format / pre-flight rejection per the QueryFilter
 * wire-format blueprint §4.7.
 *
 * Carries one of {@see ValidationErrorCode}'s 13 closed codes plus an
 * RFC 6901 JSON Pointer to the offending node within the validated
 * filter (e.g., `/filter/args/1/value/0`). The discriminator-style
 * shape is intentional: the 13 codes share a single caller response
 * ("fix the filter JSON and retry"), so a 13-class hierarchy would
 * add no caller value — see the §10 plan note for the precedent.
 *
 * Three of the 13 codes have pre-existing Phase 4 exceptions and are
 * NOT carried here:
 *   - `field_unknown`        → {@see \StarDust\Exception\UnknownFieldException}
 *   - `field_not_filterable` → {@see \StarDust\Exception\FieldNotFilterableException}
 * The remaining 11 codes flow through this class.
 */
final class QueryFilterValidationException extends RuntimeException
{
    /**
     * @param array<string, mixed> $details discriminator-specific context
     *                                       (e.g., `['expected' => 'int',
     *                                       'received' => 'number']`)
     *
     * Note: the discriminator field is `$errorCode`, not `$code` —
     * `Exception::$code` is already declared non-readonly on the base
     * class, so PHP 8.4 forbids redeclaring it readonly.
     */
    public function __construct(
        public readonly string $errorCode,
        public readonly string $jsonPointer,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
