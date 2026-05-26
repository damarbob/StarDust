<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Thrown when a public API entry point references a `stardust_fields.id`
 * (or a `(tenant_id, field_id)` pair) that does not resolve. Phase 6b's
 * retype + promotion entry points raise this as a typed exception per
 * the Phase 3/4 typed-exception precedent — internal collaborators
 * that look up fields by id may still throw `InvalidArgumentException`.
 */
final class FieldNotFoundException extends RuntimeException
{
}
