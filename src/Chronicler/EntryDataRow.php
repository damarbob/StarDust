<?php

declare(strict_types=1);

namespace StarDust\Chronicler;

/**
 * One row materialized by {@see EntryDataPager::fetchChunk()}: the
 * `entry_data.id` and the decoded `entry_data.fields` JSON payload.
 *
 * The Chronicler reads from the JSON payload because exports must
 * include every field (not just indexed slots) — ADR 0013's
 * "system of record" guarantee applies directly to this read path
 * and there is no JOIN against `entry_slots_page_X` here.
 */
final class EntryDataRow
{
    /**
     * @param array<string,mixed> $fields Decoded `entry_data.fields`.
     */
    public function __construct(
        public readonly int $id,
        public readonly array $fields,
    ) {
    }
}
