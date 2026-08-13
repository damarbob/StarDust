<?php

declare(strict_types=1);

namespace StarDust\Watcher;

use PDO;
use StarDust\Slot\IndexedSlotPredicate;

/**
 * Reads `stardust_slot_assignments` and computes the per-family /
 * global slot capacity snapshot the Watcher's tick uses to decide
 * whether to provision.
 *
 * Reports facts only; the policy lives in {@see ProvisioningPlanner}.
 * That split is why this class stays demand-agnostic — it counts
 * indexed inventory for all four families, and narrowing to the
 * families anyone is actually waiting on is pure arithmetic the
 * planner does.
 *
 * Two queries per poll. The first is a single aggregate covering every
 * family, every status, and indexed-vs-not; the derived table is
 * load-bearing, evaluating {@see IndexedSlotPredicate} once per slot
 * rather than once per indexed column it feeds. The second counts
 * distinct pages, which is a different grain (the sum of per-family
 * distincts is not the global distinct).
 *
 * The `JOIN stardust_pages` cannot change the global totals: `page_id`
 * is `NOT NULL` with an FK to `stardust_pages`, so the join is total.
 */
final class CapacityReporter
{
    private const FAMILIES = ['str', 'int', 'num', 'dt'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function report(): CapacitySnapshot
    {
        $stmt = $this->pdo->query(
            'SELECT slot_type,'
            . ' COUNT(*) AS total_cnt,'
            . ' SUM(is_free) AS free_cnt,'
            . ' SUM(is_indexed) AS indexed_total_cnt,'
            . ' SUM(is_indexed * is_free) AS indexed_free_cnt'
            . ' FROM ('
            . '   SELECT a.slot_type AS slot_type,'
            . "     (a.status = 'free') AS is_free,"
            . '     (CASE WHEN ' . IndexedSlotPredicate::existsSql('a', 'p') . ' THEN 1 ELSE 0 END) AS is_indexed'
            . '   FROM stardust_slot_assignments a'
            . '   JOIN stardust_pages p ON p.id = a.page_id'
            . ' ) t'
            . ' GROUP BY slot_type'
        );

        $freeByFamily         = array_fill_keys(self::FAMILIES, 0);
        $totalByFamily        = array_fill_keys(self::FAMILIES, 0);
        $indexedFreeByFamily  = array_fill_keys(self::FAMILIES, 0);
        $indexedTotalByFamily = array_fill_keys(self::FAMILIES, 0);
        $totalFree = 0;
        $totalSlots = 0;

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $family = (string) $row['slot_type'];

            $total = (int) $row['total_cnt'];
            $free  = (int) $row['free_cnt'];

            $totalByFamily[$family]        = ($totalByFamily[$family] ?? 0) + $total;
            $freeByFamily[$family]         = ($freeByFamily[$family] ?? 0) + $free;
            $indexedTotalByFamily[$family] = ($indexedTotalByFamily[$family] ?? 0) + (int) $row['indexed_total_cnt'];
            $indexedFreeByFamily[$family]  = ($indexedFreeByFamily[$family] ?? 0) + (int) $row['indexed_free_cnt'];

            $totalSlots += $total;
            $totalFree  += $free;
        }

        $pagesInspected = (int) $this->pdo
            ->query('SELECT COUNT(DISTINCT page_id) FROM stardust_slot_assignments')
            ->fetchColumn();

        return new CapacitySnapshot(
            freeByFamily: $freeByFamily,
            totalByFamily: $totalByFamily,
            totalFree: $totalFree,
            totalSlots: $totalSlots,
            pagesInspected: $pagesInspected,
            indexedFreeByFamily: $indexedFreeByFamily,
            indexedTotalByFamily: $indexedTotalByFamily,
        );
    }
}
