<?php

declare(strict_types=1);

namespace StarDust\Search\Mysql;

/**
 * Immutable carrier for an SQL fragment plus its positional bindings.
 *
 * Produced by {@see SqlFilterCompiler}; consumed by {@see \StarDust\Read\PaginatedProbe}
 * which appends pagination + LIMIT params before executing.
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
