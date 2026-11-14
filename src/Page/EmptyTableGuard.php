<?php

declare(strict_types=1);

namespace StarDust\Page;

use InvalidArgumentException;
use PDO;
use StarDust\Support\PdoQuery;
use StarDust\Support\TableNames;

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
     * Page table names follow `{prefix}entry_slots_page_{N}` where N is a
     * positive integer (the matching `stardust_pages.id`). MySQL has no
     * parameterised identifier substitution, so `TableNames::isPageTable()`
     * whitelists the shape before the name is interpolated into the probe
     * query.
     */
    public static function assertEmpty(PDO $pdo, string $tableName, TableNames $tables = new TableNames()): void
    {
        if (!$tables->isPageTable($tableName)) {
            throw new InvalidArgumentException(
                "EmptyTableGuard: '{$tableName}' is not a recognised entry_slots_page_X identifier."
            );
        }

        $populated = PdoQuery::run($pdo, "SELECT 1 FROM {$tableName} LIMIT 1")->fetchColumn();
        if ($populated !== false) {
            throw new PopulatedPageDDLException(
                "ADR 0012: DDL is forbidden on populated extension page '{$tableName}'."
            );
        }
    }
}
