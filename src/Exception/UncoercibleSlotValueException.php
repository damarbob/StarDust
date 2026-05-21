<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Thrown by the write path when a `entry_data.fields` value cannot be
 * coerced to the matching slot's `declared_type`.
 *
 * Phase 3 first-write policy is fail-fast: an uncoercible value rolls
 * the whole entry write back rather than storing NULL in the slot.
 * ADR 0024's "store NULL + emit `coercion_null` event" policy applies
 * to Reconciler retype-backfill (Phase 6b), not to the first-write
 * write path — the consumer's submitted payload is canonical and any
 * mismatch is a programming or upstream-validation error worth
 * surfacing immediately.
 */
final class UncoercibleSlotValueException extends RuntimeException
{
}
