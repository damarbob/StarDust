<?php

declare(strict_types=1);

namespace StarDust\Write;

/**
 * One row in a {@see LiveSlotMap}: a single field's currently-live
 * slot assignment. See LiveSlotMap class docblock for the definition
 * of "live".
 */
final class LiveSlotEntry
{
    public function __construct(
        public readonly int $fieldId,
        public readonly string $fieldName,
        public readonly string $declaredType,
        public readonly string $slotColumn,
        public readonly int $pageId,
        public readonly string $status,
    ) {
    }
}
