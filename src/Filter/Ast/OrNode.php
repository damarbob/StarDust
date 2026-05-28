<?php

declare(strict_types=1);

namespace StarDust\Filter\Ast;

/**
 * Logical OR composite. The expression matches an entry iff at least
 * one `$args` child matches.
 */
final class OrNode implements FilterNode
{
    /**
     * @param non-empty-list<FilterNode> $args
     */
    public function __construct(
        public readonly array $args,
    ) {
    }
}
