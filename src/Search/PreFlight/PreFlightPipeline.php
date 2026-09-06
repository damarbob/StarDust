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
 *      the resolved field's `declared_type` and the bounded limits,
 *      and normalise `datetime` bounds to UTC per the wire-format
 *      blueprint §4.5 criterion 20.
 *
 * Returns a new AST root carrying the resolved field references and the
 * normalised values, so downstream collaborators (compiler, executor)
 * can skip the lookup and never see a consumer-local UTC offset.
 *
 * {@see validateSort()} is a fourth stage on a separate entry point
 * rather than a fourth call inside {@see validate()}, because a sort is
 * not part of the filter tree and must be checked even when there is no
 * filter at all — a match-all read can still carry a sort and a cursor.
 */
final class PreFlightPipeline
{
    public function __construct(
        private readonly FieldRefResolver $fieldRefResolver,
        private readonly CapabilityChecker $capabilityChecker,
        private readonly ValueTypeValidator $valueTypeValidator,
        private readonly SortValidator $sortValidator,
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
        return $this->valueTypeValidator->validate($resolved, $tenantId, $correlationId);
    }

    /**
     * Validates the request's sort key and its cursor's agreement with
     * that key. Safe to call with a `null` sort — the cursor check still
     * runs, which is the point.
     */
    public function validateSort(
        ?\StarDust\Read\SortSpec $sort,
        ?\StarDust\Read\Cursor $cursor,
        SnapshotEntry $snapshot,
        EntrySearchInterface $driver,
        int $tenantId,
        string $correlationId,
    ): void {
        $this->sortValidator->validate(
            $sort,
            $cursor,
            $snapshot,
            $driver,
            $tenantId,
            $correlationId,
        );
    }
}
