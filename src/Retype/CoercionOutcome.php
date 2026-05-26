<?php

declare(strict_types=1);

namespace StarDust\Retype;

/**
 * Discriminated outcome of one coercion attempt against the ADR 0024
 * matrix. Three states:
 *
 *   - **Coerced** — value was present and coerced successfully; the
 *     coerced value is what the executor writes into the new slot.
 *   - **NullCoerced** — value was present but uncoercible; NULL is
 *     written and a `coercion_null` event is emitted with the closed
 *     ADR 0024 `reason` taxonomy.
 *   - **NotAttempted** — JSON key was absent or its value was JSON
 *     `null`; NULL is written but NO event is emitted (ADR 0024
 *     Commitment 3: only attempted-and-failed coercions are
 *     observable).
 *
 * Three states, not two, because the `coercion_null` rate must be
 * actionable — it signals retype-incompatible data, not merely sparse
 * fields.
 */
final class CoercionOutcome
{
    private const TYPE_COERCED       = 'coerced';
    private const TYPE_NULL_COERCED  = 'null_coerced';
    private const TYPE_NOT_ATTEMPTED = 'not_attempted';

    private function __construct(
        private readonly string $type,
        private readonly mixed $value,
        private readonly ?string $reason,
    ) {
    }

    public static function coerced(mixed $value): self
    {
        return new self(self::TYPE_COERCED, $value, null);
    }

    public static function nullCoerced(string $reason): self
    {
        return new self(self::TYPE_NULL_COERCED, null, $reason);
    }

    public static function notAttempted(): self
    {
        return new self(self::TYPE_NOT_ATTEMPTED, null, null);
    }

    public function isCoerced(): bool
    {
        return $this->type === self::TYPE_COERCED;
    }

    public function isNullCoerced(): bool
    {
        return $this->type === self::TYPE_NULL_COERCED;
    }

    public function isNotAttempted(): bool
    {
        return $this->type === self::TYPE_NOT_ATTEMPTED;
    }

    public function value(): mixed
    {
        return $this->value;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }
}
