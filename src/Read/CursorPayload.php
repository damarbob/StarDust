<?php

declare(strict_types=1);

namespace StarDust\Read;

/**
 * The decoded contents of an opaque {@see Cursor}.
 *
 * A cursor names one row — the last of the previous page — plus the
 * ordering that page was walked in. It deliberately does **not** carry
 * the row's sort *value*: the compiler looks that value up from the
 * anchor row at query time, which keeps a token constant-size no matter
 * how wide the sorted field is. A 4096-character string field would
 * otherwise produce a ~22 KB token, past every practical URL and header
 * limit.
 *
 * `$sortKeyIdentity` is `null` for a v1 token, which predates sorting
 * and therefore means the default `entry_data.id ASC`. That is why v1
 * tokens issued before this change keep working.
 */
final class CursorPayload
{
    private function __construct(
        public readonly int $entryId,
        public readonly ?string $sortKeyIdentity,
        public readonly ?SortDirection $direction,
        public readonly ?NullPlacement $nulls = null,
    ) {
    }

    /**
     * A v1 payload: an entry id under the default `id ASC` ordering.
     */
    public static function forDefaultOrder(int $entryId): self
    {
        return new self($entryId, null, null);
    }

    public static function forSort(SortSpec $sort, int $entryId): self
    {
        return new self($entryId, $sort->keyIdentity(), $sort->direction, $sort->nulls);
    }

    /**
     * Rebuilds a payload from an already-encoded key identity.
     *
     * The decode path holds the identity string verbatim — `$id`,
     * `$created_at`, or `f:<name>` — and must not route it back through
     * {@see SortSpec::byField()}, which would wrap it a second time and
     * make every decoded cursor mismatch its own sort.
     */
    public static function forIdentity(
        string $keyIdentity,
        SortDirection $direction,
        int $entryId,
        ?NullPlacement $nulls = null,
    ): self {
        return new self($entryId, $keyIdentity, $direction, $nulls);
    }

    /**
     * Whether this cursor was issued for the ordering now being requested.
     *
     * ADR 0006 states that a cursor is invalidated when the caller changes
     * sort order, but until sorting existed there was no parameter to
     * compare, so the rule could only be documented. This is the check.
     *
     * A v1 payload carries no ordering, so it matches the default — both
     * `null` and an explicit ascending sort on `entry_data.id`, which
     * name the same ordering and must not be treated as a mismatch.
     *
     * Where empty values land is part of the ordering too, and a token
     * without a placement means the database's default for its direction.
     * Both sides are compared with the default filled in, so spelling the
     * default out is not a mismatch either.
     */
    public function matchesSort(?SortSpec $sort): bool
    {
        $requestedKey   = $sort === null ? '$id' : $sort->keyIdentity();
        $requestedDir   = $sort === null ? SortDirection::Asc : $sort->direction;
        $requestedNulls = $sort === null ? NullPlacement::nativeFor($requestedDir) : $sort->effectiveNulls();

        $issuedDir = $this->direction ?? SortDirection::Asc;

        return ($this->sortKeyIdentity ?? '$id') === $requestedKey
            && $issuedDir === $requestedDir
            && ($this->nulls ?? NullPlacement::nativeFor($issuedDir)) === $requestedNulls;
    }
}
