<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Thrown when a requested field name is already taken within the model —
 * either as another field's current `name`, or as another field's
 * `previous_name` while an ADR 0036 rename is still draining.
 *
 * **The `previous_name` half is not redundant with
 * `ux_fields_model_name`.** Renaming `a → b` frees the name `a` as far
 * as that unique index is concerned, because `a` now lives in
 * `previous_name`. Renaming a sibling `y → a` would then satisfy the
 * index while making field `b`'s read-path fallback — new key, else old
 * key — resolve to field `y`'s value on every row the first backfill has
 * not yet reached. The index cannot see that; this guard can.
 */
final class FieldNameConflictException extends RuntimeException
{
}
