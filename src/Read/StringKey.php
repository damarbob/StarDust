<?php

declare(strict_types=1);

namespace StarDust\Read;

use StarDust\Support\StringKeyLayout;

/**
 * How one string slot is looked up through its generated key (ADR 0057), as
 * the data dictionary showed it for one page.
 *
 * The compiler builds a key lookup from exactly these four facts, and never
 * from a constant, because a page keeps the shape it was built with:
 *
 *   - `$keyColumn`, the stored generated column beside the slot.
 *   - `$indexName`, the index over `(tenant_id, key column)` that the
 *     dictionary reported, which a `FORCE INDEX` hint may name.
 *   - `$headBytes`, how many bytes of the weight string the key keeps. It is
 *     the key column's width minus the digest, so a page built with another
 *     head is served as it is.
 *   - `$collation`, the slot column's collation. The search value is cast to
 *     it before its weight string is taken.
 */
final class StringKey
{
    public function __construct(
        public readonly string $keyColumn,
        public readonly string $indexName,
        public readonly int $headBytes,
        public readonly string $collation,
    ) {
    }

    /**
     * The key for a slot, or null when any of what the dictionary reported
     * cannot be trusted. A null here means the slot compiles to the SQL it
     * had before keys, which is always correct, only slower.
     *
     * A collation name that is not a plain identifier is refused because it
     * ends up inside SQL, and a key no wider than its digest has no head.
     */
    public static function fromDictionary(
        string $keyColumn,
        ?string $indexName,
        int $keyWidth,
        ?string $collation,
    ): ?self {
        if ($indexName === null || $collation === null || ! StringKeyLayout::isSafeCollation($collation)) {
            return null;
        }
        if ($keyWidth <= StringKeyLayout::DIGEST_BYTES) {
            return null;
        }

        return new self($keyColumn, $indexName, StringKeyLayout::headBytes($keyWidth), $collation);
    }
}
