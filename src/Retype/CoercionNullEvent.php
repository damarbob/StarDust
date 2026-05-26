<?php

declare(strict_types=1);

namespace StarDust\Retype;

/**
 * Carries one `coercion_null` payload from the executor to the work
 * source, which emits it after the chunk transaction commits.
 *
 * Field shape is normative — `blueprints/watcher_reconciler_daemons.md`
 * §7 pins the required keys: `tenant_id`, `field_id`,
 * `slot_assignment_id`, `entry_id`, `source_type`, `target_type`,
 * `reason`. The work source layers on `level`, `source`, `event`,
 * `correlation_id`, and `chunk_id` at emit time.
 *
 * The `reason` field's value must be one of the closed ADR 0024
 * taxonomy: `out_of_range`, `non_integer`, `malformed_datetime`,
 * `malformed_number`, `epoch_coercion_rejected`, `unparseable`.
 */
final class CoercionNullEvent
{
    public function __construct(
        public readonly int $tenantId,
        public readonly int $fieldId,
        public readonly int $slotAssignmentId,
        public readonly int $entryId,
        public readonly string $sourceType,
        public readonly string $targetType,
        public readonly string $reason,
    ) {
    }
}
