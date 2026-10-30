<?php

declare(strict_types=1);

namespace StarDust\Search\Mysql;

/**
 * Immutable carrier for an SQL fragment plus its positional bindings.
 *
 * Produced by {@see SqlFilterCompiler}; consumed by {@see \StarDust\Read\PaginatedProbe}
 * which appends pagination + LIMIT params before executing.
 *
 * **Bind typed, never through an untyped `execute($bindings)`.** The
 * bindings end with the `LIMIT ?` page size, and under
 * `PDO::ATTR_EMULATE_PREPARES => true` an untyped execute inlines it as a
 * quoted string (`LIMIT '51'`), which the server rejects with errno 1064.
 * {@see \StarDust\Support\TypedBinding::bind()} is the one way to do it.
 */
final class SqlFragment
{
    /**
     * @param list<mixed> $bindings positional binding values in declaration order
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $bindings,
    ) {
    }
}
