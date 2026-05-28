<?php

declare(strict_types=1);

namespace StarDust\Search\PreFlight;

use LogicException;
use Psr\Log\LoggerInterface;
use StarDust\Filter\Ast\AndNode;
use StarDust\Filter\Ast\FilterNode;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Filter\Ast\NotNode;
use StarDust\Filter\Ast\OrNode;
use StarDust\Filter\Limits\FilterLimits;
use StarDust\Filter\Operator;
use StarDust\Filter\QueryFilterValidationException;
use StarDust\Filter\ValidationErrorCode;

/**
 * Pre-flight visitor: enforces the typed-value rules from the
 * wire-format blueprint §4.5.
 *
 * For each {@see LeafNode}, validates that every value (or every
 * element of an array-valued operator) matches the field's
 * `declared_type`:
 *
 *   - `string`   → JSON string, length ≤ `maxStringLength`
 *   - `int`      → integer in signed 64-bit range; fractional rejected
 *   - `numeric`  → int or float
 *   - `datetime` → RFC 3339 string with explicit UTC offset
 *
 * Rejections raise {@see QueryFilterValidationException} carrying
 * `value_type_mismatch` or `value_out_of_bounds`. Runs after the
 * {@see FieldRefResolver} so every leaf has a resolved descriptor.
 */
final class ValueTypeValidator
{
    private const INT64_MIN = -9_223_372_036_854_775_808;
    private const INT64_MAX =  9_223_372_036_854_775_807;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly FilterLimits $limits = new FilterLimits(),
    ) {
    }

    public function validate(FilterNode $node, int $tenantId, string $correlationId): void
    {
        if ($node instanceof LeafNode) {
            $this->validateLeaf($node, $tenantId, $correlationId);
            return;
        }
        if ($node instanceof AndNode || $node instanceof OrNode) {
            foreach ($node->args as $child) {
                $this->validate($child, $tenantId, $correlationId);
            }
            return;
        }
        if ($node instanceof NotNode) {
            $this->validate($node->arg, $tenantId, $correlationId);
            return;
        }
        throw new LogicException('ValueTypeValidator: unknown FilterNode ' . $node::class);
    }

    private function validateLeaf(LeafNode $leaf, int $tenantId, string $correlationId): void
    {
        // Presence operators carry no value — nothing to check.
        if (in_array($leaf->operator, Operator::PRESENCE, true)) {
            return;
        }
        if ($leaf->value === null) {
            throw new LogicException(
                "operator '{$leaf->operator}' is missing its value (decoder bug?)"
            );
        }
        $descriptor = $leaf->field->descriptor;
        if ($descriptor === null) {
            throw new LogicException(
                "ValueTypeValidator reached leaf for '{$leaf->field->fieldName}' before resolution"
            );
        }
        $declaredType = $descriptor->declaredType;
        $value = $leaf->value->value;

        if (in_array($leaf->operator, Operator::SET, true) || in_array($leaf->operator, Operator::RANGE, true)) {
            if (!is_array($value)) {
                throw new LogicException("operator '{$leaf->operator}' value must be list");
            }
            foreach ($value as $element) {
                $this->validateElement($element, $declaredType, $leaf, $tenantId, $correlationId);
            }
            return;
        }
        $this->validateElement($value, $declaredType, $leaf, $tenantId, $correlationId);
    }

    private function validateElement(
        mixed $element,
        string $declaredType,
        LeafNode $leaf,
        int $tenantId,
        string $correlationId,
    ): void {
        match ($declaredType) {
            'string'   => $this->validateString($element, $leaf, $tenantId, $correlationId),
            'int'      => $this->validateInt($element, $leaf, $tenantId, $correlationId),
            'numeric'  => $this->validateNumeric($element, $leaf, $tenantId, $correlationId),
            'datetime' => $this->validateDatetime($element, $leaf, $tenantId, $correlationId),
            default    => throw new LogicException(
                "ValueTypeValidator: unknown declared_type '{$declaredType}' for field '{$leaf->field->fieldName}'"
            ),
        };
    }

    private function validateString(mixed $v, LeafNode $leaf, int $tenantId, string $correlationId): void
    {
        if (!is_string($v)) {
            $this->throwTypeMismatch($leaf, 'string', $v, $tenantId, $correlationId);
        }
        $len = mb_strlen($v);
        if ($len > $this->limits->maxStringLength) {
            $this->emitRejection($tenantId, $correlationId, ValidationErrorCode::VALUE_OUT_OF_BOUNDS, $leaf);
            throw new QueryFilterValidationException(
                errorCode:   ValidationErrorCode::VALUE_OUT_OF_BOUNDS,
                jsonPointer: '',
                message:     "string value length {$len} exceeds maximum {$this->limits->maxStringLength}",
                details:     ['observed' => $len, 'limit' => $this->limits->maxStringLength],
            );
        }
    }

    private function validateInt(mixed $v, LeafNode $leaf, int $tenantId, string $correlationId): void
    {
        if (is_int($v)) {
            return; // PHP ints are 64-bit on modern builds; in-range by construction.
        }
        if (is_float($v)) {
            if (!is_finite($v) || floor($v) !== $v) {
                $this->throwTypeMismatch($leaf, 'int', $v, $tenantId, $correlationId);
            }
            if ($v < self::INT64_MIN || $v > self::INT64_MAX) {
                $this->emitRejection($tenantId, $correlationId, ValidationErrorCode::VALUE_OUT_OF_BOUNDS, $leaf);
                throw new QueryFilterValidationException(
                    errorCode:   ValidationErrorCode::VALUE_OUT_OF_BOUNDS,
                    jsonPointer: '',
                    message:     "int value out of signed 64-bit range",
                    details:     ['observed' => $v],
                );
            }
            return;
        }
        $this->throwTypeMismatch($leaf, 'int', $v, $tenantId, $correlationId);
    }

    private function validateNumeric(mixed $v, LeafNode $leaf, int $tenantId, string $correlationId): void
    {
        if (!is_int($v) && !is_float($v)) {
            $this->throwTypeMismatch($leaf, 'numeric', $v, $tenantId, $correlationId);
        }
        if (is_float($v) && !is_finite($v)) {
            $this->throwTypeMismatch($leaf, 'numeric', $v, $tenantId, $correlationId);
        }
    }

    private function validateDatetime(mixed $v, LeafNode $leaf, int $tenantId, string $correlationId): void
    {
        if (!is_string($v)) {
            $this->throwTypeMismatch($leaf, 'datetime', $v, $tenantId, $correlationId);
        }
        // RFC 3339 with explicit UTC offset: either trailing Z or ±HH:MM.
        // We use DateTimeImmutable::createFromFormat for the two accepted
        // shapes; naive datetimes (no offset) are rejected.
        if (!$this->isRfc3339WithOffset($v)) {
            $this->throwTypeMismatch($leaf, 'datetime', $v, $tenantId, $correlationId);
        }
    }

    private function isRfc3339WithOffset(string $v): bool
    {
        // Strict syntactic check: RFC 3339 date-time with explicit offset.
        // YYYY-MM-DDTHH:MM:SS[.frac](Z|±HH:MM)
        $pattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+\-]\d{2}:\d{2})$/';
        if (preg_match($pattern, $v) !== 1) {
            return false;
        }
        // Syntactic match isn't enough — verify it's a real calendar
        // instant by round-tripping through DateTimeImmutable.
        try {
            new \DateTimeImmutable($v);
        } catch (\Exception) {
            return false;
        }
        return true;
    }

    private function throwTypeMismatch(
        LeafNode $leaf,
        string $expected,
        mixed $received,
        int $tenantId,
        string $correlationId,
    ): never {
        $receivedType = get_debug_type($received);
        $this->emitRejection($tenantId, $correlationId, ValidationErrorCode::VALUE_TYPE_MISMATCH, $leaf);
        throw new QueryFilterValidationException(
            errorCode:   ValidationErrorCode::VALUE_TYPE_MISMATCH,
            jsonPointer: '',
            message:     "field '{$leaf->field->fieldName}' expects {$expected}, received {$receivedType}",
            details:     ['expected' => $expected, 'received' => $receivedType],
        );
    }

    private function emitRejection(int $tenantId, string $correlationId, string $reason, LeafNode $leaf): void
    {
        $this->logger->warning('search pre-flight rejected', [
            'event'          => 'pre_flight_rejected',
            'source'         => 'api',
            'correlation_id' => $correlationId,
            'tenant_id'      => $tenantId,
            'reason'         => $reason,
            'field_name'     => $leaf->field->fieldName,
        ]);
    }
}
