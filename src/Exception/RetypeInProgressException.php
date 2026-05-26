<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Thrown when a second retype/promotion is initiated for a field that
 * already has a `backfill_checkpoints` row in `status='running'` keyed
 * `retype_field_{field_id}`. The first retype must complete (or be
 * manually failed) before another can start; concurrent lifecycles on
 * the same field would race on the same registry rows.
 */
final class RetypeInProgressException extends RuntimeException
{
}
