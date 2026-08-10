<?php

declare(strict_types=1);

namespace StarDust\Watcher;

use PDO;
use StarDust\Slot\SlotReserver;
use StarDust\Write\LiveSlotMap;

/**
 * Reads the registry for filterable fields waiting on a slot.
 *
 * The blueprint names two demand sources — filterable fields currently
 * unmapped, and fields waiting in a deferred retype or promotion
 * assignment — but **one query covers both**, and the second is
 * provably a subset of the first:
 *
 *   - `RetypeInitiator` tombstones the field's live slot in the *same
 *     transaction* that inserts the `running` checkpoint, and
 *     `RetypeInProgressException` blocks a second concurrent retype.
 *     So a field with a running `retype_field_%` checkpoint has no live
 *     slot, which is exactly the condition below.
 *   - By then its `declared_type` is already the *target* type, so it
 *     folds into the correct target family with no special casing.
 *
 * Grouping over `stardust_fields` rows also makes double-counting
 * structurally impossible: one row, one waiter, whichever source it
 * came from.
 *
 * A tombstoned-only slot correctly leaves its field in demand
 * (tombstoned is not live). A demoted field correctly drops out, since
 * ADR 0034 makes non-filterable fields JSON-only. A filterable field
 * holding an *unindexed* live slot is deliberately NOT demand —
 * counting it would provision pages forever for a field that already
 * holds a slot.
 *
 * Registry-only and bounded by the field count; never touches
 * `entry_data` or an extension page.
 */
final class PendingDemandReader
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function read(): PendingDemand
    {
        $placeholders = implode(',', array_fill(0, count(LiveSlotMap::LIVE_STATUSES), '?'));

        $stmt = $this->pdo->prepare(
            'SELECT f.declared_type AS declared_type, COUNT(*) AS waiters'
            . ' FROM stardust_fields f'
            . ' WHERE f.is_filterable = 1'
            . '   AND NOT EXISTS ('
            . '     SELECT 1 FROM stardust_slot_assignments a'
            . "      WHERE a.field_id = f.id AND a.status IN ({$placeholders})"
            . '   )'
            . ' GROUP BY f.declared_type'
        );
        $stmt->execute(LiveSlotMap::LIVE_STATUSES);

        $byFamily = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $family = SlotReserver::DECLARED_TYPE_TO_SLOT_TYPE[(string) $row['declared_type']] ?? null;
            if ($family === null) {
                // Unreachable while declared_type is an ENUM. Skip
                // rather than throw: a reporter must never take the
                // daemon down over one unrecognised registry row.
                continue;
            }

            $byFamily[$family] = ($byFamily[$family] ?? 0) + (int) $row['waiters'];
        }

        return new PendingDemand($byFamily);
    }
}
