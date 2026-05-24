<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Phase 4 pre-flight rejection per ADR 0004 (fail-fast on unindexed
 * filters): the request targets a field whose `is_filterable` column
 * is `false`, so no `(tenant_id, slot_column)` composite index exists
 * — running the query would force a full-table scan, which the engine
 * refuses by design.
 *
 * Operator response: either flip the field's `is_filterable` flag and
 * accept the resulting backfill (Phase 6b lifecycle) or rewrite the
 * caller to stop filtering on the field.
 */
final class FieldNotFilterableException extends RuntimeException
{
}
