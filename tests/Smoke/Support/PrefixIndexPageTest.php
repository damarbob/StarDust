<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Support;

use InvalidArgumentException;
use PDO;
use StarDust\Support\ServerEngine;
use StarDust\Tests\Smoke\WritePathTestCase;

/**
 * Proves the frozen prefix-index fixture builds the shape it claims to.
 *
 * The fixture is a copy of what the provisioner used to emit, so nothing else
 * would notice if it drifted. Every assertion reads the finished table from the
 * data dictionary, never the DDL text.
 */
final class PrefixIndexPageTest extends WritePathTestCase
{
    /** @return list<string> */
    private function columnsOf(int $pageId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute(["entry_slots_page_{$pageId}"]);

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string, list<array{column: string, subPart: ?int}>> index name => key parts in order */
    private function indexesOf(int $pageId): array
    {
        $indexes = [];
        foreach ($this->pdo->query("SHOW INDEX FROM entry_slots_page_{$pageId}")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $indexes[(string) $row['Key_name']][(int) $row['Seq_in_index']] = [
                'column'  => (string) $row['Column_name'],
                'subPart' => $row['Sub_part'] === null ? null : (int) $row['Sub_part'],
            ];
        }
        foreach ($indexes as &$parts) {
            ksort($parts);
            $parts = array_values($parts);
        }

        return $indexes;
    }

    public function testCarriesExactlyTheRequestedColumnsInLayoutOrder(): void
    {
        $pageId = $this->provisionPrefixPage(['i_int_01', 'i_str_02', 'i_str_01']);

        self::assertSame(
            ['entry_id', 'tenant_id', 'i_str_01', 'i_str_02', 'i_int_01'],
            $this->columnsOf($pageId),
        );
    }

    public function testIndexesAStringSlotOnItsFirst766CharactersAndOthersInFull(): void
    {
        $pageId = $this->provisionPrefixPage(['i_str_01', 'i_int_01']);
        $indexes = $this->indexesOf($pageId);

        self::assertSame(
            [['column' => 'tenant_id', 'subPart' => null], ['column' => 'i_str_01', 'subPart' => 766]],
            $indexes["ix_entry_slots_page_{$pageId}_i_str_01"],
        );
        self::assertSame(
            [['column' => 'tenant_id', 'subPart' => null], ['column' => 'i_int_01', 'subPart' => null]],
            $indexes["ix_entry_slots_page_{$pageId}_i_int_01"],
        );
    }

    public function testHasNoKeyColumnBesideAStringSlot(): void
    {
        $pageId = $this->provisionPrefixPage(['i_str_01']);

        foreach ($this->columnsOf($pageId) as $column) {
            self::assertStringEndsNotWith('_k', $column, 'A prefix page carries no generated key column.');
        }
    }

    public function testInventoryNamesTheRequestedColumnsAsFree(): void
    {
        $pageId = $this->provisionPrefixPage(['i_str_01', 'i_dt_01']);

        $rows = $this->pdo
            ->query(
                'SELECT slot_column, slot_type, status FROM stardust_slot_assignments'
                . " WHERE page_id = {$pageId} ORDER BY slot_column"
            )
            ->fetchAll(PDO::FETCH_ASSOC);

        self::assertSame(
            [
                ['slot_column' => 'i_dt_01', 'slot_type' => 'dt', 'status' => 'free'],
                ['slot_column' => 'i_str_01', 'slot_type' => 'str', 'status' => 'free'],
            ],
            $rows,
        );
    }

    public function testUsesTheCollationOfTheConnectedEngine(): void
    {
        $pageId = $this->provisionPrefixPage(['i_str_01']);

        $stmt = $this->pdo->prepare(
            'SELECT TABLE_COLLATION FROM information_schema.TABLES'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute(["entry_slots_page_{$pageId}"]);

        self::assertSame(
            match ($this->engine) {
                ServerEngine::MYSQL   => 'utf8mb4_0900_ai_ci',
                ServerEngine::MARIADB => 'utf8mb4_unicode_520_nopad_ci',
            },
            $stmt->fetchColumn(),
        );
    }

    public function testBumpsTheSchemaVersionOnce(): void
    {
        $before = (int) $this->pdo->query('SELECT version FROM stardust_schema_version WHERE id = 1')->fetchColumn();

        $this->provisionPrefixPage(['i_str_01']);

        $after = (int) $this->pdo->query('SELECT version FROM stardust_schema_version WHERE id = 1')->fetchColumn();
        self::assertSame($before + 1, $after);
    }

    public function testRejectsAnUnknownColumnAndAnEmptyList(): void
    {
        try {
            $this->provisionPrefixPage(['i_str_26']);
            self::fail('A column outside the layout must be refused.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('i_str_26', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->provisionPrefixPage([]);
    }
}
