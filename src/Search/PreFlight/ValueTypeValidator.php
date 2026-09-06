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
use StarDust\Filter\Ast\TypedValue;
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
 *
 * It also **normalises** as it goes, which is why it returns a new AST
 * root rather than `void`. The wire-format blueprint §4.5 criterion 20
 * requires every `datetime` value to reach the driver in UTC, and the
 * offset the validator insists on is otherwise discarded downstream:
 * MySQL truncates an RFC 3339 literal at the zone designator, so
 * `…T10:00:00+07:00` matched the row holding `10:00`, not the row
 * holding `03:00`, with only a warning 1292 nothing reads.
 *
 * The normalised form is canonical UTC RFC 3339 (`…T03:00:00Z`) —
 * deliberately *not* a MySQL `DATETIME` literal. The AST is
 * driver-neutral per ADR 0022, so a custom driver has to receive an
 * unambiguous instant; rendering it as a MySQL literal is
 * {@see \StarDust\Search\Mysql\SqlFilterCompiler}'s job, at bind time.
 *
 * Fractional seconds are preserved rather than truncated. A slot column
 * is `DATETIME` (second precision), but MySQL compares a fractional
 * constant against it exactly — verified on 8.0.13 that
 * `'…10:00:00' < '…10:00:00.5'` is true — so flooring the bound would
 * make `lt` / `gt` wrong at the boundary.
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

    /**
     * Validates every leaf and returns a new AST root carrying the
     * normalised values. Composite nodes are rebuilt rather than
     * mutated, the same shape {@see FieldRefResolver::resolveAll()}
     * uses — the AST is immutable throughout pre-flight.
     */
    public function validate(FilterNode $node, int $tenantId, string $correlationId): FilterNode
    {
        if ($node instanceof LeafNode) {
            return $this->validateLeaf($node, $tenantId, $correlationId);
        }
        if ($node instanceof AndNode) {
            $children = [];
            foreach ($node->args as $child) {
                $children[] = $this->validate($child, $tenantId, $correlationId);
            }
            return new AndNode($children);
        }
        if ($node instanceof OrNode) {
            $children = [];
            foreach ($node->args as $child) {
                $children[] = $this->validate($child, $tenantId, $correlationId);
            }
            return new OrNode($children);
        }
        if ($node instanceof NotNode) {
            return new NotNode($this->validate($node->arg, $tenantId, $correlationId));
        }
        throw new LogicException('ValueTypeValidator: unknown FilterNode ' . $node::class);
    }

    private function validateLeaf(LeafNode $leaf, int $tenantId, string $correlationId): LeafNode
    {
        // Presence operators carry no value — nothing to check.
        if (in_array($leaf->operator, Operator::PRESENCE, true)) {
            return $leaf;
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
            return $this->normaliseLeaf($leaf, $declaredType);
        }
        $this->validateElement($value, $declaredType, $leaf, $tenantId, $correlationId);
        return $this->normaliseLeaf($leaf, $declaredType);
    }

    /**
     * Rewrites a `datetime` leaf's bound(s) into canonical UTC. Every
     * other declared type is returned untouched, so this is a no-op for
     * the overwhelming majority of leaves.
     *
     * Runs only after {@see validateElement()} has accepted the value,
     * which is what lets it treat a non-string here as a bug rather
     * than as input — the same posture the missing-value branch above
     * already takes.
     */
    private function normaliseLeaf(LeafNode $leaf, string $declaredType): LeafNode
    {
        if ($declaredType !== 'datetime' || $leaf->value === null) {
            return $leaf;
        }
        $value = $leaf->value->value;

        if (is_array($value)) {
            $normalised = [];
            foreach ($value as $element) {
                if (!is_string($element)) {
                    throw new LogicException(
                        'datetime element reached normalisation as ' . get_debug_type($element)
                    );
                }
                $normalised[] = self::toCanonicalUtc($element);
            }
            return $leaf->withNormalisedValue(new TypedValue($normalised));
        }
        if (!is_string($value)) {
            throw new LogicException(
                'datetime value reached normalisation as ' . get_debug_type($value)
            );
        }
        return $leaf->withNormalisedValue(new TypedValue(self::toCanonicalUtc($value)));
    }

    /**
     * RFC 3339 with an explicit offset → the same instant in UTC, `Z`
     * form. Fractional seconds are carried through when the input had
     * them and omitted when it did not, so the common case stays the
     * shape a reader expects.
     *
     * The value has already round-tripped through `DateTimeImmutable`
     * in {@see isRfc3339WithOffset()}, so the constructor cannot throw
     * here. Same `setTimezone(UTC)` idiom as
     * {@see \StarDust\Write\PayloadSplitter::coerceDatetime()} and
     * {@see \StarDust\Retype\RetypeCoercionEngine} — do not add a
     * fourth spelling of it.
     */
    private static function toCanonicalUtc(string $value): string
    {
        $utc = (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        $micros = $utc->format('u');
        return $utc->format('Y-m-d\TH:i:s')
            . ($micros === '000000' ? '' : '.' . $micros)
            . 'Z';
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
