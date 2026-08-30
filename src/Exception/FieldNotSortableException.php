<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Pre-flight rejection of a sort target the active driver will not
 * order by — the sort half of ADR 0004's "reject filters and sorts on
 * fields that are not explicitly provisioned as queryable".
 *
 * For the MySQL native driver that means the field is not declared
 * filterable, or its slot is `backfilling` / `tombstoned` / unmapped, so
 * no `(tenant_id, slot_column)` index exists to order by. Another driver
 * may answer differently; the question is asked through
 * `EntrySearchInterface::supportsSortOn()` per ADR 0022, never by
 * reading the registry from the shared pipeline.
 *
 * A sort naming a field that is not registered at all raises
 * {@see UnknownFieldException} instead — the same fact a filter reports,
 * and not worth a second name.
 *
 * Operator response: promote the field to filterable and let the
 * backfill land, or drop the sort.
 *
 * > Distinct from {@see FieldNotFilterableException} because the two
 * > name different requests against the same field: that one rejects a
 * > *filter*, this one a *sort*. They coincide on the MySQL driver and
 * > need not on any other, which is the reason for a separate class
 * > rather than a reuse.
 */
final class FieldNotSortableException extends RuntimeException
{
}
