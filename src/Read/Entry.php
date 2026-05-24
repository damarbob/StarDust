<?php

declare(strict_types=1);

namespace StarDust\Read;

use DateTimeImmutable;

/**
 * Phase 4 single-entry result DTO.
 *
 * `$fields` is keyed by `stardust_fields.name` and merges values from
 * the slot columns (where the slot is `assigned`/`ready` and the
 * caller selected the field) with `JSON_EXTRACT` lookups against
 * `entry_data.fields` (for `backfilling`, `tombstoned`, or unmapped
 * fields). The assembly contract is owned by {@see ResultAssembler};
 * downstream code MUST NOT depend on whether a given field came from
 * a slot or from the JSON payload — that's the whole point of the
 * ADR 0007 exhaustion fallback.
 */
final class Entry
{
    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly int $modelId,
        public readonly array $fields,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $deletedAt,
    ) {
    }
}
