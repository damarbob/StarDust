<?php

declare(strict_types=1);

namespace StarDust\Search\PreFlight;

use LogicException;
use Psr\Log\LoggerInterface;
use StarDust\Exception\UnknownFieldException;
use StarDust\Filter\Ast\AndNode;
use StarDust\Filter\Ast\FilterNode;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Filter\Ast\NotNode;
use StarDust\Filter\Ast\OrNode;
use StarDust\Read\SnapshotEntry;

/**
 * Pre-flight visitor: replaces every {@see LeafNode}'s
 * {@see \StarDust\Filter\Ast\FieldRef} with its registry-resolved form
 * (descriptor populated from the {@see SnapshotEntry}).
 *
 * Throws {@see UnknownFieldException} when a leaf names a field absent
 * from the snapshot (`field_unknown` per the wire-format blueprint §4.4
 * AC#15). Every rejection emits an `api: pre_flight_rejected` event.
 *
 * The visitor produces a new AST root — input nodes are immutable;
 * resolution allocates new instances. Composite nodes recurse into
 * their children.
 */
final class FieldRefResolver
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function resolveAll(
        FilterNode $node,
        SnapshotEntry $snapshot,
        int $tenantId,
        string $correlationId,
    ): FilterNode {
        if ($node instanceof LeafNode) {
            return $this->resolveLeaf($node, $snapshot, $tenantId, $correlationId);
        }
        if ($node instanceof AndNode) {
            $children = [];
            foreach ($node->args as $child) {
                $children[] = $this->resolveAll($child, $snapshot, $tenantId, $correlationId);
            }
            return new AndNode($children);
        }
        if ($node instanceof OrNode) {
            $children = [];
            foreach ($node->args as $child) {
                $children[] = $this->resolveAll($child, $snapshot, $tenantId, $correlationId);
            }
            return new OrNode($children);
        }
        if ($node instanceof NotNode) {
            return new NotNode($this->resolveAll($node->arg, $snapshot, $tenantId, $correlationId));
        }
        throw new LogicException('FieldRefResolver: unknown FilterNode ' . $node::class);
    }

    private function resolveLeaf(
        LeafNode $leaf,
        SnapshotEntry $snapshot,
        int $tenantId,
        string $correlationId,
    ): LeafNode {
        $descriptor = $snapshot->field($leaf->field->fieldName);
        if ($descriptor === null) {
            $this->logger->warning('search pre-flight rejected', [
                'event'          => 'pre_flight_rejected',
                'source'         => 'api',
                'correlation_id' => $correlationId,
                'tenant_id'      => $tenantId,
                'reason'         => 'field_unknown',
                'field_name'     => $leaf->field->fieldName,
            ]);
            throw new UnknownFieldException(
                "filter references unknown field '{$leaf->field->fieldName}' "
                . "for model {$snapshot->modelId}."
            );
        }
        return $leaf->withResolvedField($leaf->field->withResolved($snapshot->modelId, $descriptor));
    }
}
