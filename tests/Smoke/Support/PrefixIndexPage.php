<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Support;

use InvalidArgumentException;
use PDO;
use StarDust\Support\ServerEngine;

/**
 * Builds a prefix-index extension page, the shape `PageProvisioner` produced
 * until a string slot moved to a generated key.
 *
 * Such a page carries exactly the columns it indexes. A string slot is a
 * `TEXT` column indexed on its first 766 characters, and every other family
 * is indexed in full. Its inventory names the same columns, all `free`.
 *
 * `PageProvisioner` no longer produces this shape, but pages built before
 * that change stay in the field for good (pages are never altered once
 * populated), so the compiler, the reserver and the capacity readers must keep
 * serving them. This class is what lets the suite keep proving it. It is also
 * where a test goes when its subject is the 766 character prefix itself.
 *
 * **The DDL here is frozen on purpose.** It restates the column table instead
 * of reading the provisioner's, because its job is to reproduce a historical
 * shape, and tracking the provisioner would defeat that the moment the
 * provisioner changes again. For the same reason it calls neither
 * `PageProvisioner` nor `Support\Dialect`, and the collation is branched on
 * `$engine` inline. The MariaDB literal is the collation `Dialect` returns for
 * MariaDB, copied and not called.
 *
 * {@see LegacyPage} is the sibling for the sixty column pages from before
 * pages carried only their indexed columns.
 */
final class PrefixIndexPage
{
    /** Frozen layout, family => [column count, MySQL type]. */
    private const LAYOUT = [
        'str' => [25, 'TEXT'],
        'int' => [15, 'BIGINT'],
        'num' => [10, 'DOUBLE'],
        'dt'  => [10, 'DATETIME'],
    ];

    /** 766 utf8mb4 characters * 4 bytes + the 8 byte tenant_id = 3072, the DYNAMIC key limit. */
    private const STRING_INDEX_PREFIX = 766;

    private function __construct()
    {
    }

    /**
     * Provision one prefix-index page indexing exactly `$filterableSlots` and
     * return its `stardust_pages.id`.
     *
     * Mirrors the provisioner as it behaved before string slots moved to a
     * key. The DDL goes first because it auto-commits, then the registry row,
     * the inventory and the schema-version bump share one transaction.
     *
     * @param list<string> $filterableSlots slot column names such as `i_str_01`, at least one
     */
    public static function provision(
        PDO $pdo,
        ServerEngine $engine,
        array $filterableSlots,
        string $provisionerIdentity = 'phpunit/prefix',
    ): int {
        $columns = self::orderedColumns($filterableSlots);

        $pageNumber = (int) $pdo
            ->query('SELECT COALESCE(MAX(id), 0) + 1 FROM stardust_pages')
            ->fetchColumn();
        $tableName = "entry_slots_page_{$pageNumber}";

        $pdo->exec(self::ddl($tableName, $columns, $engine));

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
            foreach ($columns as $column) {
                $placeholders[] = '(?, ?, ?, ?, ?)';
                array_push($params, $pageNumber, $column, self::family($column), 'free', $now);
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

    /**
     * The requested columns in layout order, each checked against the frozen
     * layout.
     *
     * @param  list<string> $requested
     * @return list<string>
     */
    private static function orderedColumns(array $requested): array
    {
        $wanted = array_flip($requested);

        $ordered = [];
        foreach (self::LAYOUT as $family => [$count]) {
            for ($i = 1; $i <= $count; $i++) {
                $column = sprintf('i_%s_%02d', $family, $i);
                if (isset($wanted[$column])) {
                    $ordered[] = $column;
                    unset($wanted[$column]);
                }
            }
        }

        if ($wanted !== []) {
            throw new InvalidArgumentException(
                'PrefixIndexPage: not a slot column: ' . implode(', ', array_map('strval', array_keys($wanted))) . '.'
            );
        }
        if ($ordered === []) {
            throw new InvalidArgumentException('PrefixIndexPage: a page must carry at least one slot column.');
        }

        return $ordered;
    }

    private static function family(string $column): string
    {
        return explode('_', $column)[1];
    }

    /** @param list<string> $columns */
    private static function ddl(string $tableName, array $columns, ServerEngine $engine): string
    {
        $lines = [
            "CREATE TABLE IF NOT EXISTS {$tableName} (",
            '    entry_id  BIGINT NOT NULL,',
            '    tenant_id BIGINT NOT NULL,',
        ];

        foreach ($columns as $column) {
            $lines[] = sprintf('    %s %s NULL DEFAULT NULL,', $column, self::LAYOUT[self::family($column)][1]);
        }

        $lines[] = '    PRIMARY KEY (entry_id),';
        $lines[] = sprintf('    KEY ix_%s_tenant (tenant_id),', $tableName);

        foreach ($columns as $column) {
            $indexed = self::family($column) === 'str'
                ? sprintf('%s(%d)', $column, self::STRING_INDEX_PREFIX)
                : $column;
            $lines[] = sprintf('    KEY ix_%s_%s (tenant_id, %s),', $tableName, $column, $indexed);
        }

        $lines[] = sprintf(
            '    CONSTRAINT fk_%s_entry FOREIGN KEY (entry_id) REFERENCES entry_data (id) ON DELETE CASCADE',
            $tableName
        );
        // ROW_FORMAT=DYNAMIC is what allows the 766 character prefix. The
        // COMPACT and REDUNDANT formats cap an index key at 767 bytes, and
        // CREATE TABLE would fail with errno 1071 on a server whose
        // innodb_default_row_format is not dynamic.
        $collation = match ($engine) {
            ServerEngine::MYSQL   => 'utf8mb4_0900_ai_ci',
            ServerEngine::MARIADB => 'utf8mb4_unicode_520_nopad_ci',
        };
        $lines[] = ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={$collation} ROW_FORMAT=DYNAMIC";

        return implode("\n", $lines);
    }
}
