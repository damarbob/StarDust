<?php

declare(strict_types=1);

namespace StarDust\Filter\Ast;

/**
 * Logical AND composite. The expression matches an entry iff every
 * `$args` child matches.
 *
 * @phpstan-type FilterArgs non-empty-list<FilterNode>
 */
final class AndNode implements FilterNode
{
    /**
     * @param non-empty-list<FilterNode> $args
     */
    public function __construct(
        public readonly array $args,
    ) {
    }
}
