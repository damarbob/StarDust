<?php

declare(strict_types=1);

namespace StarDust\Watcher;

use PDO;

/**
 * Reads `stardust_slot_assignments` and computes the per-family /
 * global slot capacity snapshot the Watcher's tick uses to decide
 * whether to provision.
 *
 * Single SQL aggregate covers every family and every status; the
 * threshold decision lives in {@see Watcher}, not here (SRP — this
 * class only reports).
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
            'SELECT slot_type, status, COUNT(*) AS cnt'
            . ' FROM stardust_slot_assignments'
            . ' GROUP BY slot_type, status'
        );

        $freeByFamily  = array_fill_keys(self::FAMILIES, 0);
        $totalByFamily = array_fill_keys(self::FAMILIES, 0);
        $totalFree = 0;
        $totalSlots = 0;

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $family = (string) $row['slot_type'];
            $count  = (int) $row['cnt'];
            $totalByFamily[$family] = ($totalByFamily[$family] ?? 0) + $count;
            $totalSlots += $count;
            if ((string) $row['status'] === 'free') {
                $freeByFamily[$family] = ($freeByFamily[$family] ?? 0) + $count;
                $totalFree += $count;
            }
        }

        return new CapacitySnapshot(
            freeByFamily: $freeByFamily,
            totalByFamily: $totalByFamily,
            totalFree: $totalFree,
            totalSlots: $totalSlots,
        );
    }
}
