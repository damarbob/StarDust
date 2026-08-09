<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Thrown when a slot reservation is attempted for a field whose
 * `stardust_fields.is_filterable` is `false`. Per ADR 0034 a
 * non-filterable field is JSON-only: it lives exclusively in the
 * `entry_data.fields` payload and is never assigned an extension-table
 * slot, so every reserved slot is a filter target by construction.
 *
 * Raised before any row is touched — on the own-transaction reserve
 * paths, before the transaction is even opened.
 *
 * Distinct from {@see FieldNotFilterableException}, which is the Phase 4
 * read-path pre-flight rejection for a *filter* that targets such a
 * field. This one means a caller asked the registry for something the
 * architecture forbids; that one means a consumer's query needs fixing.
 */
final class NonFilterableFieldSlotException extends RuntimeException
{
}
