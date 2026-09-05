<?php

declare(strict_types=1);

namespace StarDust\Slot;

use PDO;

/**
 * Free *indexed* slots, per page per family.
 *
 * The one definition of "capacity a filterable field could actually
 * claim on this page", shared by ADR 0033's compaction planner and ADR
 * 0031's spread advisory. Both need the same number for the same
 * reason: `excess_pages → 0` is compaction's stated success criterion,
 * so the metric that says a model needs compacting and the operation
 * that compacts it must be counting one thing.
 *
 * **The index restriction is not optional.** A field holding one of
 * these slots is filterable, so ADR 0016 commitment 1 and ADR 0004
 * require the column to carry an index — the pinned reservation passes
 * `requireIndexed: true`, and counting unindexed free slots would build
 * plans the reservation then refuses, turning a clean up-front
 * `CompactionCapacityException` into a mid-flight failure.
 * {@see IndexedSlotPredicate} is the shared definition of "indexed", the
 * same one the reserver and the Watcher use.
 *
 * **Only `free` rows are counted**, which is what makes double-occupancy
 * correct for free: a slot a compaction is about to vacate becomes
 * `tombstoned`, not `free`, and does not return until the Liberator
 * sweeps it (ADR 0009).
 *
 * Registry-only — it touches no `entry_data` row and no extension page,
 * so both callers keep the "safe against production at any time"
 * property that `--dry-run` and the daily advisory sweep depend on. One
 * aggregate query per call, grouped by page, covering the whole pool;
 * neither caller needs it per model.
 */
final class IndexedFreeCapacityReader
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, int>> pageId ⇒ family ⇒ free indexed slot count
     */
    public function load(): array
    {
        $stmt = $this->pdo->query(
            'SELECT sa.page_id, sa.slot_type, COUNT(*) AS free_slots'
            . ' FROM stardust_slot_assignments sa'
            . ' JOIN stardust_pages p ON p.id = sa.page_id'
            . " WHERE sa.status = 'free'"
            . '   AND ' . IndexedSlotPredicate::existsSql('sa', 'p')
            . ' GROUP BY sa.page_id, sa.slot_type'
        );

        $capacity = [];
        foreach ($stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $capacity[(int) $row['page_id']][(string) $row['slot_type']] = (int) $row['free_slots'];
        }

        return $capacity;
    }
}
