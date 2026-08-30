<?php

declare(strict_types=1);

namespace StarDust\Read;

/**
 * One sort key for a read, carried by {@see EntryQuery} and
 * {@see \StarDust\Search\SearchRequest}.
 *
 * Three targets, and the distinction is not cosmetic — it decides
 * whether the read stays index-ordered:
 *
 *   - `SortTarget::Id`        — `entry_data.id`. A range scan on the PK, or a
 *                           backward index scan on `(tenant_id, model_id)`
 *                           for `DESC`. No filesort at any depth.
 *   - `SortTarget::CreatedAt` — `entry_data.created_at`, ordered by
 *                           `(tenant_id, deleted_at, created_at)`. Also no
 *                           filesort.
 *   - `SortTarget::Field`     — a registered field's indexed slot column. The
 *                           sort field's page must be joined, and MySQL
 *                           filesorts (and builds a temporary table) over
 *                           the whole filtered set to find each page.
 *
 * **Single key by design.** The engine always appends `entry_data.id` in
 * the same direction as an implicit tiebreak, which is what makes every
 * ordering total and therefore what makes the cursor stable. A second
 * user-supplied key would multiply the keyset predicate's NULL-branch
 * surface for very little gain; it is deferred, not forgotten.
 *
 * `null` sort on a query means `SortTarget::Id` ascending — the ordering
 * every read had before sorting existed.
 */
final class SortSpec
{
    private function __construct(
        public readonly SortTarget $target,
        public readonly ?string $fieldName,
        public readonly SortDirection $direction,
    ) {
    }

    public static function byId(SortDirection $direction = SortDirection::Asc): self
    {
        return new self(SortTarget::Id, null, $direction);
    }

    public static function byCreatedAt(SortDirection $direction = SortDirection::Asc): self
    {
        return new self(SortTarget::CreatedAt, null, $direction);
    }

    public static function byField(string $fieldName, SortDirection $direction = SortDirection::Asc): self
    {
        return new self(SortTarget::Field, $fieldName, $direction);
    }

    /**
     * True when this sort targets a registered field rather than an
     * intrinsic `entry_data` column — i.e. when it needs a driver
     * capability check and a page join.
     */
    public function isFieldSort(): bool
    {
        return $this->target === SortTarget::Field;
    }

    /**
     * The field name this sort targets.
     *
     * @throws \LogicException when the target is intrinsic. Callers reach
     *                         this only behind {@see isFieldSort()}; the
     *                         throw exists so the `?string` property does
     *                         not leak a null into SQL construction.
     */
    public function fieldNameOrFail(): string
    {
        if ($this->fieldName === null) {
            throw new \LogicException(
                'SortSpec::fieldNameOrFail() called on an intrinsic sort target.'
            );
        }
        return $this->fieldName;
    }

    /**
     * Stable identity of the sort key, independent of direction.
     *
     * Stamped into the cursor so a token replayed against a different
     * sort is detected rather than silently paginating a different
     * ordering — the failure ADR 0006 names but could not detect while
     * there was no sort parameter to compare.
     */
    public function keyIdentity(): string
    {
        return match ($this->target) {
            SortTarget::Id        => '$id',
            SortTarget::CreatedAt => '$created_at',
            SortTarget::Field     => 'f:' . $this->fieldName,
        };
    }
}
