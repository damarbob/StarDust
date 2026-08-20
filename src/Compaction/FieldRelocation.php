<?php

declare(strict_types=1);

namespace StarDust\Compaction;

/**
 * One field's move in a {@see CompactionPlan}: which field, which family
 * it needs, where it lives now, and which page it is going to.
 *
 * A relocation only ever appears in a plan when it is a genuine move.
 * Fields whose live slot already sits on a target page are counted as
 * no-ops and never materialise here — that is what makes re-running
 * `compactModel()` convergent and cheap rather than a second full
 * migration.
 */
final class FieldRelocation
{
    public function __construct(
        public readonly int $fieldId,
        public readonly string $fieldName,
        public readonly string $slotType,
        public readonly int $fromPageId,
        public readonly int $toPageId,
    ) {
    }
}
