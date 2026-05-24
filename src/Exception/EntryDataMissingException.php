<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Thrown by {@see \StarDust\Write\BackfillExecutor} when the
 * `entry_data` row referenced by a queued `entry_id` has been deleted
 * or carries a malformed `fields` JSON payload.
 *
 * The Reconciler catches this and routes the queue row to
 * `stardust_reconciler_dlq` with `reason='missing_entry_data'` per
 * ADR 0018.
 */
final class EntryDataMissingException extends RuntimeException
{
}
