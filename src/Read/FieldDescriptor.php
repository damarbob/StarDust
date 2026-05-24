<?php

declare(strict_types=1);

namespace StarDust\Read;

/**
 * One field's registry metadata as captured in a {@see SnapshotEntry}.
 *
 * Carries everything the read path needs to decide:
 *   - whether the field is acceptable as a filter / sort target (ADR 0004
 *     pre-flight rejection — see {@see QueryValidator}); and
 *   - how to source its value at assembly time (slot column vs.
 *     `JSON_EXTRACT` fallback — see {@see ResultAssembler}).
 *
 * `slotColumn`, `slotStatus`, `pageId` are populated only when the field
 * has a slot assignment in any status (`assigned`, `backfilling`,
 * `ready`, `tombstoned`). They are NULL when the field has no slot row
 * at all (unmapped). The combination `slotStatus IN ('assigned','ready')`
 * is the sole "filterable now" precondition; every other value falls
 * back to JSON_EXTRACT.
 */
final class FieldDescriptor
{
    public function __construct(
        public readonly int $fieldId,
        public readonly string $fieldName,
        public readonly string $declaredType,
        public readonly bool $isFilterable,
        public readonly ?string $slotColumn,
        public readonly ?string $slotStatus,
        public readonly ?int $pageId,
    ) {
    }

    /**
     * True when the field can serve as a filter/sort target: it is
     * declared filterable AND its current slot is in a state that
     * exposes a `(tenant_id, slot_column)` index (`assigned` or
     * `ready`). All other states — including `backfilling` and
     * `tombstoned` — must fall back to JSON_EXTRACT and so cannot be
     * filter targets.
     */
    public function isIndexedNow(): bool
    {
        return $this->isFilterable
            && $this->slotColumn !== null
            && ($this->slotStatus === 'assigned' || $this->slotStatus === 'ready');
    }
}
