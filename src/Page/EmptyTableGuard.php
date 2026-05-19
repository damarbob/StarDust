<?php

declare(strict_types=1);

namespace StarDust\Page;

use InvalidArgumentException;
use PDO;

/**
 * Engine-level guard implementing ADR 0012's Empty-Table-Only DDL rule:
 * any caller about to issue DDL against an `entry_slots_page_X` table must
 * first prove the table is empty. The probe rejects populated pages before
 * MySQL is asked to perform the alteration, so the failure mode is a typed
 * exception rather than a metadata-lock wait.
 *
 * Phase 2's production path provisions only new (empty) pages and therefore
 * never trips the guard itself; the helper is published so that later
 * phases that need to revisit page DDL have a single canonical check.
 */
final class EmptyTableGuard
{
    /**
     * Page table names follow `entry_slots_page_{N}` where N is a positive
     * integer (the matching `stardust_pages.id`). MySQL has no parameterised
     * identifier substitution, so we whitelist the shape before interpolating
     * the name into the probe query.
     */
    private const PAGE_TABLE_PATTERN = '/^entry_slots_page_[1-9]\d*$/';

    public static function assertEmpty(PDO $pdo, string $tableName): void
    {
        if (preg_match(self::PAGE_TABLE_PATTERN, $tableName) !== 1) {
            throw new InvalidArgumentException(
                "EmptyTableGuard: '{$tableName}' is not a recognised entry_slots_page_X identifier."
            );
        }

        $populated = $pdo->query("SELECT 1 FROM {$tableName} LIMIT 1")->fetchColumn();
        if ($populated !== false) {
            throw new PopulatedPageDDLException(
                "ADR 0012: DDL is forbidden on populated extension page '{$tableName}'."
            );
        }
    }
}
