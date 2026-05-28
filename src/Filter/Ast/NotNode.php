<?php

declare(strict_types=1);

namespace StarDust\Filter\Ast;

/**
 * Logical NOT composite. Wire format pins the singular `arg` (not
 * `args`) key for this node — multi-child negation has to be expressed
 * as `NOT (AND …)` or `NOT (OR …)`.
 */
final class NotNode implements FilterNode
{
    public function __construct(
        public readonly FilterNode $arg,
    ) {
    }
}
