<?php

declare(strict_types=1);

namespace StarDust\Reconciler;

use PDO;
use PDOException;
use StarDust\Slot\SlotReserver;

/**
 * Reserves slots for the filterable fields a sync-queue backfill found
 * still unmapped — the ADR 0007 exhaustion path's missing half.
 *
 * {@see SyncQueueWorkSource} calls this AFTER rolling its chunk back,
 * never inside the chunk transaction. Three reasons, all load-bearing:
 *
 *   1. The chunk transaction's shape stays exactly as it was — same
 *      rollback, same claimable rows — so this change cannot regress
 *      the drain path it hooks into.
 *   2. `SlotReserver` bumps the `stardust_schema_version` singleton on
 *      every successful reservation. That row is a global
 *      serialization point; holding it for the length of a chunk
 *      transaction would make every Reconciler worker contend on it
 *      (ADR 0008).
 *   3. It contains the multi-worker race. Two workers can hit
 *      capacity-wait on the same field from disjoint chunks and both
 *      attempt a reservation, colliding on
 *      `ux_slot_assignments_field_live` (ADR 0017 — at most one live
 *      slot per field). In its own transaction that is a contained,
 *      catchable failure; inside the chunk transaction it would roll
 *      the chunk back and propagate out of `tickOne()`, taking the
 *      daemon down.
 *
 * **The collision has two shapes, both verified against MySQL 8.0.13**,
 * and which one you get depends on whether the rival has committed yet:
 *
 *   - Rival committed ⇒ errno **1062**, `SQLSTATE 23000`, immediately.
 *   - Rival still open ⇒ the loser **blocks on the unique index** and
 *     eventually raises errno **1205**, `SQLSTATE HY000`. That wait is
 *     bounded by `innodb_lock_wait_timeout`, which defaults to **50
 *     seconds** — so a worker really can sit here that long before
 *     giving up. It is a stall, not a hang, and only on the contended
 *     path; the chunk is already rolled back, so nothing is held open
 *     behind it. Operators running many Reconcilers on one starved
 *     field may want a lower session timeout.
 *
 * Both are `PDOException`, so one catch covers them. Losing the race is
 * not an error condition: the field now has the slot it needed, which is
 * the outcome both workers wanted. It is deliberately NOT counted as a
 * reservation, so the tick reports `capacity_wait` and retries — the
 * next tick's {@see \StarDust\Write\LiveSlotMap} load sees the committed
 * slot and drains normally.
 *
 * Under `ERRMODE_SILENT` neither errno raises at all; `SlotReserver`
 * detects the failed write via `rowCount()` and returns `null`, which
 * lands on the same "reserved nothing" branch.
 */
final class UnmappedFieldReserver
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SlotReserver $slotReserver,
    ) {
    }

    /**
     * Attempt one reservation per still-unmapped field name, returning
     * how many slots were actually reserved.
     *
     * `$fieldNames` comes from {@see \StarDust\Write\BackfillResult::$stillUnmapped},
     * which {@see \StarDust\Write\PayloadSplitter} has already scoped to
     * registered filterable fields. Resolving the names against the
     * entry's own model is what turns them back into the field ids the
     * reserver needs.
     *
     * @param list<string> $fieldNames
     */
    public function reserveFor(int $entryId, array $fieldNames): int
    {
        $reserved = 0;

        foreach ($this->resolveFieldIds($entryId, $fieldNames) as $fieldId) {
            try {
                if ($this->slotReserver->reserveForExhaustionBackfill($fieldId) !== null) {
                    $reserved++;
                }
            } catch (PDOException) {
                // Lost the race to a concurrent worker (see the class
                // docblock). The field has its slot; we simply did not
                // put it there, so this tick still reports capacity_wait
                // and the next one drains.
                continue;
            }
        }

        return $reserved;
    }

    /**
     * @param  list<string> $fieldNames
     * @return list<int>
     */
    private function resolveFieldIds(int $entryId, array $fieldNames): array
    {
        // An empty list would render `IN ()` — a MySQL syntax error.
        // The caller only reaches here with a non-empty stillUnmapped,
        // but the guard belongs next to the SQL that depends on it.
        if ($fieldNames === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($fieldNames), '?'));

        // Joining through entry_data scopes the lookup to the entry's
        // own model, so a same-named field on another model can never
        // be reserved by mistake. `is_filterable = 1` is redundant with
        // PayloadSplitter's scoping and with the reserver's own ADR 0034
        // guard, but a predicate that the SQL's correctness depends on
        // belongs in the SQL.
        $stmt = $this->pdo->prepare(
            'SELECT f.id FROM stardust_fields f'
            . ' JOIN entry_data e ON e.model_id = f.model_id'
            . " WHERE e.id = ? AND f.is_filterable = 1 AND f.name IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$entryId], $fieldNames));

        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }
}
