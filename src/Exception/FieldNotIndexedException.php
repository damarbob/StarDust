<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Phase 4 pre-flight rejection per ADR 0004: the request targets a
 * field whose current slot status is `backfilling`, `tombstoned`, or
 * absent (unmapped). In every one of these states the `(tenant_id,
 * slot_column)` composite index is either unavailable, mid-rebuild,
 * or stale, so the engine refuses filter/sort traffic on the field
 * until it advances to `assigned` or `ready`.
 *
 * The built-in engine does not throw this. Since the search driver
 * seam (ADR 0022) the pre-flight asks the driver one yes/no question,
 * `supportsFilterOn()`, and a "no" for any of the states above surfaces
 * as {@see FieldNotFilterableException}, whose message says when the
 * field is declared filterable but not indexed yet. The class stays so
 * a custom driver or caller can tell the two apart if it wants to.
 */
final class FieldNotIndexedException extends RuntimeException
{
}
