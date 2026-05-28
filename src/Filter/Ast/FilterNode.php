<?php

declare(strict_types=1);

namespace StarDust\Filter\Ast;

/**
 * Marker interface for every node of a parsed StarDust filter expression.
 *
 * The AST is a four-class closed set per ADR 0021:
 *   - {@see AndNode}, {@see OrNode}, {@see NotNode} — composite nodes;
 *   - {@see LeafNode} — every operator-bearing leaf, tagged by its
 *     `$operator` field rather than by class identity, so the engine
 *     can extend the operator vocabulary at runtime without subclassing
 *     (ADR 0022 capability extensions).
 *
 * Instances are immutable readonly value objects; visitors transform
 * the tree by allocating new instances rather than mutating fields.
 */
interface FilterNode
{
}
