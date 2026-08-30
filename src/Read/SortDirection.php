<?php

declare(strict_types=1);

namespace StarDust\Read;

/**
 * Direction of a {@see SortSpec}.
 *
 * An enum rather than a pair of string constants so there is no
 * direction-validation exception to define and no invalid value to
 * defend against — the type system does it. Joins `TickOutcome`,
 * `JobOutcome` and `ClaimKind` as the fourth enum under `src/`;
 * `FinalClassGuardTest` exempts enums explicitly.
 *
 * The backing values are the strings that appear in a cursor payload,
 * so they are part of the opaque token's internal format and must not
 * be renamed without a cursor-version bump.
 */
enum SortDirection: string
{
    case Asc  = 'asc';
    case Desc = 'desc';

    /**
     * The SQL keyword for this direction. Interpolated into the
     * `ORDER BY` clause, which is why it comes from a closed enum
     * rather than from caller-supplied text.
     */
    public function sql(): string
    {
        return $this === self::Asc ? 'ASC' : 'DESC';
    }

    /**
     * The strict comparison operator that walks the result set in this
     * direction — the `>` / `<` of the keyset predicate.
     */
    public function keysetOperator(): string
    {
        return $this === self::Asc ? '>' : '<';
    }
}
