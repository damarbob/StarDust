<?php

declare(strict_types=1);

namespace StarDust\Search\PreFlight;

use StarDust\Filter\Ast\FilterNode;
use StarDust\Read\SnapshotEntry;
use StarDust\Search\EntrySearchInterface;

/**
 * Phase 8 pre-flight orchestrator.
 *
 * Runs the three visitors in the fixed order pinned by the QueryFilter
 * wire-format blueprint §4.7 pre-flight sequence:
 *
 *   1. {@see FieldRefResolver} — resolve every leaf's field reference
 *      against the schema-version-cached {@see SnapshotEntry}.
 *   2. {@see CapabilityChecker} — check operator and field against the
 *      active driver's capability surface.
 *   3. {@see ValueTypeValidator} — verify every typed value matches
 *      the resolved field's `declared_type` and the bounded limits.
 *
 * Returns a new AST root carrying the resolved field references so
 * downstream collaborators (compiler, executor) can skip the lookup.
 */
final class PreFlightPipeline
{
    public function __construct(
        private readonly FieldRefResolver $fieldRefResolver,
        private readonly CapabilityChecker $capabilityChecker,
        private readonly ValueTypeValidator $valueTypeValidator,
    ) {
    }

    public function validate(
        FilterNode $node,
        SnapshotEntry $snapshot,
        EntrySearchInterface $driver,
        int $tenantId,
        string $correlationId,
    ): FilterNode {
        $resolved = $this->fieldRefResolver->resolveAll($node, $snapshot, $tenantId, $correlationId);
        $this->capabilityChecker->check($resolved, $driver, $tenantId, $correlationId);
        $this->valueTypeValidator->validate($resolved, $tenantId, $correlationId);
        return $resolved;
    }
}
