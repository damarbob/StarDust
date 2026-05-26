<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Thrown when a retype request targets one of the four (`int↔datetime`,
 * `numeric↔datetime`) cells the ADR 0024 coercion matrix categorically
 * rejects. Epoch interpretation is a policy call that does not belong
 * in engine semantics — callers requiring epoch-style migration must
 * bridge through a `string` intermediate field.
 *
 * Surfaced at registry-write time inside
 * {@see \StarDust\Retype\RetypeInitiator} before any mutation; the
 * caller sees the exception and no rows in `stardust_fields`,
 * `stardust_slot_assignments`, or `backfill_checkpoints` are touched.
 */
final class IncompatibleRetypeException extends RuntimeException
{
}
