<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Support;

use PDO;

/**
 * Builds a **pre-ADR-0043 extension page**: all sixty slot columns, none
 * of them indexed, and a sixty-row `stardust_slot_assignments` inventory
 * in which forty-four to fifty-nine rows describe capacity no production
 * reservation path can claim.
 *
 * ADR 0043 makes a page carry exactly the columns it indexes, so
 * `PageProvisioner` can no longer produce this shape. It is not a
 * transitional concern: pages provisioned before that decision keep
 * their sixty columns and their phantom inventory rows forever (ADR 0012
 * is forward-only), so `IndexedSlotPredicate` and the two-shape
 * reasoning in `SlotReserver` and `CapacityReporter` are permanent. This
 * class is what lets the suite keep proving they work.
 *
 * **The DDL here is frozen on purpose.** It restates the column table
 * rather than reading `PageProvisioner`'s, because its job is to
 * reproduce a historical shape — tracking the provisioner would defeat
 * the point the moment the provisioner changes again. Same documented
 * bypass precedent as `Phase6aTestCase::seedSlotValues()` and
 * `SlotAffinityTest`'s direct registry writes: fixtures may construct
 * states the production path refuses to.
 */
final class LegacyPage
{
    /** Frozen pre-0043 layout: family ⇒ [column count, MySQL type]. */
    private const LAYOUT = [
        'str' => [25, 'TEXT'],
        'int' => [15, 'BIGINT'],
        'num' => [10, 'DOUBLE'],
        'dt'  => [10, 'DATETIME'],
    ];

    private function __construct()
    {
    }

    /**
     * Provision one legacy-shaped page and return its `stardust_pages.id`.
     *
     * Mirrors `PageProvisioner::provision([])` as it behaved before ADR
     * 0043 — DDL first (it auto-commits), then the registry row, the
     * full inventory and the schema-version bump in one transaction.
     */
    public static function provision(PDO $pdo, string $provisionerIdentity = 'phpunit/legacy'): int
    {
        $pageNumber = (int) $pdo
            ->query('SELECT COALESCE(MAX(id), 0) + 1 FROM stardust_pages')
            ->fetchColumn();
        $tableName = "entry_slots_page_{$pageNumber}";

        $pdo->exec(self::ddl($tableName));

        $now = gmdate('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            $insertPage = $pdo->prepare(
                'INSERT INTO stardust_pages (id, table_name, provisioned_at, provisioned_by)'
                . ' VALUES (?, ?, ?, ?)'
            );
            $insertPage->execute([$pageNumber, $tableName, $now, $provisionerIdentity]);

            $placeholders = [];
            $params = [];
            foreach (self::LAYOUT as $family => [$count]) {
                for ($i = 1; $i <= $count; $i++) {
                    $placeholders[] = '(?, ?, ?, ?, ?)';
                    array_push($params, $pageNumber, sprintf('i_%s_%02d', $family, $i), $family, 'free', $now);
                }
            }

            $insertInventory = $pdo->prepare(
                'INSERT INTO stardust_slot_assignments'
                . ' (page_id, slot_column, slot_type, status, updated_at)'
                . ' VALUES ' . implode(',', $placeholders)
            );
            $insertInventory->execute($params);

            $bumpVersion = $pdo->prepare(
                'UPDATE stardust_schema_version'
                . ' SET version = version + 1, updated_at = ?'
                . ' WHERE id = 1'
            );
            $bumpVersion->execute([$now]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $pageNumber;
    }

    private static function ddl(string $tableName): string
    {
        $lines = [
            "CREATE TABLE IF NOT EXISTS {$tableName} (",
            '    entry_id  BIGINT NOT NULL,',
            '    tenant_id BIGINT NOT NULL,',
        ];

        foreach (self::LAYOUT as $family => [$count, $sqlType]) {
            for ($i = 1; $i <= $count; $i++) {
                $lines[] = sprintf('    i_%s_%02d %s NULL DEFAULT NULL,', $family, $i, $sqlType);
            }
        }

        $lines[] = '    PRIMARY KEY (entry_id),';
        $lines[] = sprintf('    KEY ix_%s_tenant (tenant_id),', $tableName);
        $lines[] = sprintf(
            '    CONSTRAINT fk_%s_entry FOREIGN KEY (entry_id) REFERENCES entry_data (id) ON DELETE CASCADE',
            $tableName
        );
        // ROW_FORMAT=DYNAMIC for the same reason the production DDL pins
        // it: COMPACT/REDUNDANT cap index keys at 767 bytes, and a test
        // that later indexes a string slot on this page would hit errno
        // 1071 on a server whose innodb_default_row_format differs.
        $lines[] = ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci ROW_FORMAT=DYNAMIC';

        return implode("\n", $lines);
    }
}
