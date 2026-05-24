<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Phase 4 pre-flight rejection per ADR 0004: the request references a
 * field name that is not present in `stardust_fields` for the model
 * being queried.
 *
 * Unknown payload keys on the *write* side are silently dropped (per
 * ADR 0013 — the value persists in `entry_data.fields`). On the read
 * side the same input is a programming error: the caller named a
 * field that does not exist, so refusing the call is strictly more
 * informative than silently returning rows with NULL where the field
 * should be.
 */
final class UnknownFieldException extends RuntimeException
{
}
