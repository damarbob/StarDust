<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Thrown when a field lifecycle is initiated against a field that
 * already has a `backfill_checkpoints` row in `status='running'` keyed
 * `rename_field_{field_id}`.
 *
 * Raised by both the rename initiator (a second rename) and the retype
 * initiator (a retype, promotion, demotion, or ADR 0033 relocation
 * landing on a field mid-rename). The retype direction is a correctness
 * guard rather than hygiene: the retype backfill locates values by field
 * name, so during a rename window every row behind the backfill cursor
 * reads as "value absent" and the slot is written NULL with no
 * `coercion_null` event to show for it.
 */
final class RenameInProgressException extends RuntimeException
{
}
