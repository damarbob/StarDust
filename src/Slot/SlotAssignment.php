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
 *
 * `$affinity` reports whether ADR 0032 model-affinity found the slot on
 * a page already hosting a live slot of the same model, or spilled to
 * the global-oldest-free ordering. It is **diagnostic only** — nothing
 * branches on it; it exists so `slot_reserved` can carry the outcome and
 * so operators can see affinity working (or not) without inferring it
 * from spread trends. It defaults to `fallback` so a hand-constructed
 * assignment in a test or a consumer stays valid.
 */
final class SlotAssignment
{
    public const AFFINITY_CO_LOCATED = 'co_located';
    public const AFFINITY_FALLBACK   = 'fallback';

    public function __construct(
        public readonly int $pageId,
        public readonly string $slotColumn,
        public readonly int $slotAssignmentId,
        public readonly string $slotType,
        public readonly string $affinity = self::AFFINITY_FALLBACK,
    ) {
    }
}
