<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Thrown when a mutating public API entry point targets an
 * `entry_data.id` that does not resolve for the caller's tenant —
 * because it never existed, belongs to another tenant, or has already
 * been soft-deleted. The three are deliberately indistinguishable to
 * the caller: reporting "wrong tenant" separately from "no such row"
 * would leak the existence of another tenant's data (Architecture
 * Blueprint §1.2).
 *
 * Raised by `StarDust::updateEntry()`. **`StarDust::deleteEntry()`
 * does not throw it** — deletion is idempotent and returns `false`
 * instead, so a double-delete is not an error.
 *
 * Not to be confused with {@see EntryDataMissingException}, which is
 * the Reconciler's DLQ reason for a queued `entry_id` whose backing row
 * has vanished mid-drain — an internal integrity signal, not a caller
 * mistake.
 */
final class EntryNotFoundException extends RuntimeException
{
}
