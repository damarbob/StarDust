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
 * Operator response: wait for the active retype to finish (Phase 6b
 * lifecycle), or — for unmapped fields — reserve a slot via the
 * Phase 2 slot reserver and rerun the query.
 */
final class FieldNotIndexedException extends RuntimeException
{
}
