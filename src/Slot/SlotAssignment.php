<?php

declare(strict_types=1);

namespace StarDust\Slot;

/**
 * Result of a successful slot reservation. Identifies the physical page,
 * the slot column on that page, and the registry row that records the
 * mapping. Future phases (write path, Reconciler) consume these to route
 * payload values to the correct page.slot_column.
 *
 * `$slotType` is one of `str | int | num | dt` per the
 * `stardust_slot_assignments.slot_type` ENUM (schema reference §4.4).
 */
final class SlotAssignment
{
    public function __construct(
        public readonly int $pageId,
        public readonly string $slotColumn,
        public readonly int $slotAssignmentId,
        public readonly string $slotType,
    ) {
    }
}
