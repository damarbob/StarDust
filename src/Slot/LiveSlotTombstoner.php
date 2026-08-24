<?php

declare(strict_types=1);

namespace StarDust\Slot;

use PDO;

/**
 * Flips a field's live slot to `tombstoned`, handing it to the Phase 6a
 * Liberator for reclamation (ADR 0009).
 *
 * **The caller owns the transaction.** Every lifecycle that severs a
 * slot does so as one step of a larger atomic tuple — the registry
 * mutation, the tombstone and the schema-version bump must commit
 * together or a reader observes a slot that is still `assigned` but no
 * longer mapped (ADR 0017 §4.6 invariant 1).
 *
 * ## Why the two-step order is load-bearing
 *
 * `field_id` is cleared **before** `status` flips, never after and never
 * in one UPDATE. Two separate things depend on it:
 *
 * 1. `ux_slot_assignments_field_live` is a functional partial unique
 *    index over `CASE WHEN status IN ('assigned','backfilling','ready')
 *    THEN field_id END` (ADR 0017). A later reservation taking the same
 *    `field_id` would collide with a row that still holds it in a live
 *    status.
 * 2. `fk_slot_assignments_field` is `RESTRICT` — it declares no
 *    `ON DELETE` clause. Nulling `field_id` **releases the foreign key
 *    inside the transaction**, which is what lets ADR 0037's
 *    `deleteField()` hard-delete the field row in the same tuple instead
 *    of waiting on a Liberator sweep. Verified against MySQL 8.0.13: the
 *    same DELETE fails with errno 1451 before this runs and succeeds
 *    immediately after.
 *
 * The orphaned tombstone stays fully sweepable with the field row gone,
 * because `TombstonedSlotRepository::loadBatch()` keys on `status` +
 * `page_id` + `slot_column` and never joins `stardust_fields` — ADR
 * 0029's no-tenant-predicate design is what makes that safe.
 *
 * Extracted from `RetypeInitiator`, which held it privately until
 * `DeleteFieldInitiator` needed the identical sequence. Duplicating
 * twenty lines of index- and FK-defending SQL is exactly the thing that
 * drifts.
 */
final class LiveSlotTombstoner
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Returns the tombstoned slot's assignment id, or `null` when the
     * field held no live slot.
     *
     * `null` is the normal case, not an anomaly: under ADR 0034 a
     * non-filterable field holds no slot at all, and a `false → true`
     * promotion has nothing to tombstone unless the field is a
     * grandfathered pre-0034 holdover.
     */
    public function tombstone(int $fieldId, string $now): ?int
    {
        $select = $this->pdo->prepare(
            'SELECT id FROM stardust_slot_assignments'
            . " WHERE field_id = ? AND status IN ('assigned','backfilling','ready')"
            . ' LIMIT 1 FOR UPDATE'
        );
        $select->execute([$fieldId]);
        $id = $select->fetchColumn();
        if ($id === false) {
            return null;
        }
        $slotId = (int) $id;

        // Step 1 — release field_id. See the class docblock: this is
        // what frees both the partial unique index and the RESTRICT
        // foreign key, and it must happen before the status flip.
        $clearField = $this->pdo->prepare(
            'UPDATE stardust_slot_assignments SET field_id = NULL, updated_at = ? WHERE id = ?'
        );
        $clearField->execute([$now, $slotId]);

        // Step 2 — hand the slot to the Liberator.
        $tombstone = $this->pdo->prepare(
            'UPDATE stardust_slot_assignments'
            . " SET status = 'tombstoned', tombstoned_at = ?, updated_at = ?"
            . ' WHERE id = ?'
        );
        $tombstone->execute([$now, $now, $slotId]);

        return $slotId;
    }
}
