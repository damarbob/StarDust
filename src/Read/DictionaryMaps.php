<?php

declare(strict_types=1);

namespace StarDust\Read;

use PDO;
use StarDust\Support\StringKeyLayout;

/**
 * The two data dictionary lookups a schema snapshot carries for the filter
 * compiler: which index returns a slot column in `entry_id` order, and how a
 * string slot is looked up through its generated key (ADR 0057).
 *
 * Each map is read from `information_schema` the first time it is asked for and
 * kept after that, so a caller that never asks pays for nothing. A read that
 * fails is not kept: the exception goes to the caller and the next call tries
 * again, because an empty map would be taken for "this page has no such index".
 */
final class DictionaryMaps
{
    /** @var ?array<int, array<string, string>> */
    private ?array $orderedIndexes = null;

    /** @var ?array<int, array<string, StringKey>> */
    private ?array $stringKeys = null;

    /**
     * @param array<int, string> $pageTableNames pageId → table name
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $pageTableNames,
    ) {
    }

    /**
     * @return array<int, array<string, string>> pageId → slotColumn → index name
     */
    public function orderedIndexes(): array
    {
        return $this->orderedIndexes ??= $this->readOrderedIndexes();
    }

    /**
     * @return array<int, array<string, StringKey>> pageId → slotColumn → key
     */
    public function stringKeys(): array
    {
        return $this->stringKeys ??= $this->readStringKeys($this->orderedIndexes());
    }

    /**
     * For each slot column on the given pages, the name of an index that
     * returns equal values in `entry_id` order.
     *
     * An index qualifies when it has exactly two key parts, `tenant_id` and
     * then one column, both at full width and in a B-tree. InnoDB appends
     * the primary key (`entry_id`) to every secondary index, so with
     * `tenant_id` and the column both pinned by equality the rows come back
     * in `entry_id` order. A third key part would sit between the column
     * and `entry_id` and break that order. Requiring full width also leaves
     * out a string slot's 766 character prefix index, which cannot answer
     * an equality on its own. A string slot's generated key (ADR 0057)
     * qualifies as it stands, but under the key column's name and not the
     * slot's, because the slot itself is in no index on a key page.
     *
     * An index the optimiser has been told to skip (`INVISIBLE` on MySQL,
     * `IGNORED` on MariaDB) is left out as well, since a hint naming it
     * fails the read. When several indexes qualify the smallest name wins,
     * so the choice is the same on every refresh.
     *
     * This is one query per snapshot refresh and nothing on a warm read,
     * because the snapshot is cached per schema version. `SELECT *` is
     * deliberate: the visibility column has a different name on each engine,
     * and the keys come back in upper case on MySQL 8.
     *
     * @return array<int, array<string, string>> pageId → slotColumn → index name
     */
    private function readOrderedIndexes(): array
    {
        if ($this->pageTableNames === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($this->pageTableNames), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT * FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE()'
            . " AND TABLE_NAME IN ({$placeholders})"
            . ' ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX'
        );
        $stmt->execute(array_values($this->pageTableNames));

        /** @var array<string, int> $pageIdOfTable */
        $pageIdOfTable = array_flip($this->pageTableNames);

        /** @var array<string, array<string, list<array{column: ?string, subPart: mixed, type: string}>>> $keyParts */
        $keyParts = [];
        /** @var array<string, array<string, true>> $skipped */
        $skipped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $r = array_change_key_case($row, CASE_LOWER);
            $table = (string) $r['table_name'];
            $index = (string) $r['index_name'];

            if (($r['is_visible'] ?? 'YES') === 'NO' || ($r['ignored'] ?? 'NO') === 'YES') {
                $skipped[$table][$index] = true;
            }
            $keyParts[$table][$index][] = [
                'column'  => $r['column_name'] === null ? null : strtolower((string) $r['column_name']),
                'subPart' => $r['sub_part'],
                'type'    => strtoupper((string) $r['index_type']),
            ];
        }

        $out = [];
        foreach ($keyParts as $table => $indexes) {
            foreach ($indexes as $index => $parts) {
                $index = (string) $index;
                if (isset($skipped[$table][$index]) || count($parts) !== 2) {
                    continue;
                }
                [$tenant, $slot] = $parts;
                if (
                    $tenant['column'] !== 'tenant_id' || $tenant['subPart'] !== null
                    || $slot['column'] === null || $slot['subPart'] !== null
                    || $slot['type'] !== 'BTREE'
                ) {
                    continue;
                }

                if (! isset($pageIdOfTable[$table])) {
                    continue;
                }
                $pageId = $pageIdOfTable[$table];
                $current = $out[$pageId][$slot['column']] ?? null;
                if ($current === null || strcmp($index, $current) < 0) {
                    $out[$pageId][$slot['column']] = $index;
                }
            }
        }

        return $out;
    }

    /**
     * For each string slot whose page indexes it through a generated key
     * (ADR 0057), how to look it up through that key.
     *
     * A page's shape comes from the data dictionary of the page table and never
     * from a registry column. The table is the only fact: a crash between a
     * page's `CREATE TABLE` and the registry transaction can leave an orphan
     * table that a later provisioning reuses by name, so a flag in the registry
     * could name a shape the table does not have, and the compiler would then
     * name a column that is not there.
     *
     * The query runs only when the index map already holds a key column, so a
     * model that lives on prefix pages pays nothing, and a warm read pays
     * nothing either because the snapshot is cached per schema version. A slot
     * is left out when the index map has no usable index on its key (a hidden
     * one included), when the key column is not a `VARBINARY`, or when the
     * dictionary's collation or width cannot be trusted. A slot left out
     * compiles to the SQL it had before keys.
     *
     * @param array<int, array<string, string>> $orderedIndexes pageId → column → index name
     * @return array<int, array<string, StringKey>> pageId → slotColumn → key
     */
    private function readStringKeys(array $orderedIndexes): array
    {
        /** @var array<int, array<string, string>> $candidates pageId → slotColumn → key column */
        $candidates = [];
        foreach ($orderedIndexes as $pageId => $byColumn) {
            foreach (array_keys($byColumn) as $column) {
                $slot = StringKeyLayout::slotColumn((string) $column);
                if ($slot !== null && isset($this->pageTableNames[$pageId])) {
                    $candidates[$pageId][$slot] = (string) $column;
                }
            }
        }
        if ($candidates === []) {
            return [];
        }

        $tables = [];
        foreach (array_keys($candidates) as $pageId) {
            $tables[] = $this->pageTableNames[$pageId];
        }
        $placeholders = implode(',', array_fill(0, count($tables), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLLATION_NAME, CHARACTER_MAXIMUM_LENGTH'
            . ' FROM information_schema.COLUMNS'
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})"
        );
        $stmt->execute($tables);

        /** @var array<string, array<string, array<string, mixed>>> $columns table → column → row */
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $r = array_change_key_case($row, CASE_LOWER);
            $columns[(string) $r['table_name']][strtolower((string) $r['column_name'])] = $r;
        }

        $out = [];
        foreach ($candidates as $pageId => $bySlot) {
            $table = $this->pageTableNames[$pageId];
            foreach ($bySlot as $slot => $keyColumn) {
                $keyRow = $columns[$table][$keyColumn] ?? null;
                $slotRow = $columns[$table][$slot] ?? null;
                if ($keyRow === null || $slotRow === null || strtolower((string) $keyRow['data_type']) !== 'varbinary') {
                    continue;
                }

                $key = StringKey::fromDictionary(
                    $keyColumn,
                    $orderedIndexes[$pageId][$keyColumn],
                    (int) $keyRow['character_maximum_length'],
                    $slotRow['collation_name'] === null ? null : (string) $slotRow['collation_name'],
                );
                if ($key !== null) {
                    $out[$pageId][$slot] = $key;
                }
            }
        }

        return $out;
    }
}
