<?php

declare(strict_types=1);

namespace StarDust\Schema;

/**
 * One field, as reported by {@see SchemaReader::describeModel()}.
 *
 * `$isFilterable` and `$isIndexed` answer **different questions**, and
 * conflating them is the mistake this DTO exists to prevent:
 *
 *   - `$isFilterable` is the registry's `is_filterable` flag — the
 *     declared *intent* that this field should be queryable.
 *   - `$isIndexed` is whether the field currently occupies a live slot
 *     with status `assigned` or `ready` — the *fact* that a filter
 *     against it will work right now.
 *
 * They diverge for the whole of a promotion or retype backfill window,
 * and for a newly-registered filterable field until its first write has
 * been drained by the Reconciler, which is when the field gets a slot.
 * During those windows the field is filterable but not indexed, and a
 * filter targeting it is rejected with `FieldNotFilterableException`
 * (ADR 0004, ADR 0022) while reads fall back to the JSON payload. A UI that offers "filter by this field" should gate on
 * `$isIndexed`, not `$isFilterable`.
 */
final class FieldDescription
{
    public function __construct(
        public readonly int $fieldId,
        public readonly string $name,
        public readonly string $declaredType,
        public readonly bool $isFilterable,
        public readonly bool $isIndexed,
    ) {
    }
}
