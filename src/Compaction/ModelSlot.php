<?php

declare(strict_types=1);

namespace StarDust\Compaction;

/**
 * One live filterable slot belonging to the model being compacted —
 * which field holds it, which family it is, and which page it sits on.
 *
 * The planner's whole input, alongside free-capacity counts. Loaded by
 * {@see CompactionRepository::loadModelSlots()} from exactly the
 * population ADR 0031's spread metric measures, so the operation and the
 * signal that triggers it can never disagree about what "the model's
 * slots" means.
 */
final class ModelSlot
{
    public function __construct(
        public readonly int $fieldId,
        public readonly string $fieldName,
        public readonly string $slotType,
        public readonly int $pageId,
        public readonly string $slotColumn,
    ) {
    }
}
