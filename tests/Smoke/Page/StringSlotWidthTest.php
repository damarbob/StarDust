<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Page;

use PDO;
use StarDust\Exception\UncoercibleSlotValueException;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Read\EntryQuery;
use StarDust\Tests\Smoke\ReadPathTestCase;

/**
 * String slots store the full normative QueryFilter bound (4096 chars,
 * `FilterLimits::DEFAULT_MAX_STRING_LENGTH`) as `TEXT`, and a page is indexed
 * under ROW_FORMAT=DYNAMIC.
 *
 * `VARCHAR(4096)` is not an option: 25 string slots × 4096 × 4 bytes
 * (utf8mb4) exceeds MySQL's 65,535-byte row-definition limit (errno 1118).
 * TEXT escapes the row limit but cannot be indexed in full, so a page built
 * now indexes a stored generated key beside each string slot (ADR 0057), and
 * a page built before that indexes a 766 character prefix (ADR 0030).
 *
 * Four proofs:
 *   - the DDL shape of a page built now (`text` slot, `varbinary(48)` stored
 *     generated key, a composite index over the key in full, `Dynamic` row
 *     format),
 *   - end-to-end behaviour on that page: a >255-char value writes without
 *     MySQL 1406 and an `eq` filter separates two values that share their
 *     first 766 characters,
 *   - the same separation on a prefix page, where it rests on the per-operator
 *     full-value recheck behind the prefix scan, and
 *   - a point read returns the full-length value from the JSON payload.
 */
final class StringSlotWidthTest extends ReadPathTestCase
{
    public function testStringSlotIsTextWithAKeyIndexAndDynamicRowFormat(): void
    {
        $this->provisionPage(['i_str_01']);

        self::assertSame('text', strtolower($this->columnType('i_str_01')));

        self::assertSame('varbinary(48)', strtolower($this->columnType('i_str_01_k')));
        $extra = $this->pdo
            ->query(
                'SELECT EXTRA FROM information_schema.COLUMNS'
                . ' WHERE table_schema = DATABASE()'
                . "   AND table_name = 'entry_slots_page_1'"
                . "   AND column_name = 'i_str_01_k'"
            )
            ->fetchColumn();
        self::assertMatchesRegularExpression(
            '/STORED GENERATED|PERSISTENT/i',
            (string) $extra,
            'The key must be a stored generated column, so the server fills it on every write.',
        );

        $keyParts = $this->pdo
            ->query(
                'SELECT COLUMN_NAME, SUB_PART FROM information_schema.STATISTICS'
                . ' WHERE table_schema = DATABASE()'
                . "   AND table_name = 'entry_slots_page_1'"
                . "   AND index_name = 'ix_entry_slots_page_1_i_str_01'"
                . ' ORDER BY SEQ_IN_INDEX'
            )
            ->fetchAll(PDO::FETCH_NUM);
        self::assertSame(
            [['tenant_id', null], ['i_str_01_k', null]],
            $keyParts,
            'A string slot is indexed through its key, in full, and the slot itself is in no index.',
        );

        self::assertSame('dynamic', $this->rowFormat('entry_slots_page_1'));
    }

    public function testLongFilterableStringWritesAndFiltersExactly(): void
    {
        [$modelId, , , $fieldName] = $this->setupFilterableStringField();

        $this->assertEqSeparatesValuesSharingTheirFirst766Characters($modelId, $fieldName);
    }

    /**
     * A page built before string keys is still served, and it still relies on
     * the full-value recheck behind its prefix index.
     */
    public function testLongFilterableStringFiltersExactlyOnAPrefixPage(): void
    {
        $this->provisionPrefixPage(['i_str_01']);
        $modelId = $this->createModel(1);
        $fieldName = 'name';
        $this->reserveSlotFor($this->createField($modelId, 'string', true, $fieldName));

        $this->assertEqSeparatesValuesSharingTheirFirst766Characters($modelId, $fieldName);
    }

    public function testGetReturnsFullLengthValueFromPayload(): void
    {
        [$modelId, , , $fieldName] = $this->setupFilterableStringField();

        $value = str_repeat('x', 4096);
        $entryId = $this->seedEntry(1, $modelId, [$fieldName => $value]);

        $entry = $this->reader()->get(1, $entryId);

        self::assertNotNull($entry);
        self::assertSame($value, $entry->fields[$fieldName]);
    }

    public function testStringExceedingMaxLengthThrowsTypedExceptionAndPersistsNothing(): void
    {
        [$modelId, , , $fieldName] = $this->setupFilterableStringField();

        // One past FilterLimits::DEFAULT_MAX_STRING_LENGTH (4096) — fits TEXT
        // storage but exceeds the filter bound the slot is queried by. The
        // write must fail with a typed StarDust exception, never a raw 1406.
        $tooLong = str_repeat('a', 4097);

        try {
            $this->seedEntry(1, $modelId, [$fieldName => $tooLong]);
            self::fail('Expected UncoercibleSlotValueException for a 4097-char string.');
        } catch (UncoercibleSlotValueException $e) {
            self::assertStringContainsString('4097', $e->getMessage());
        }

        // Coercion runs before the entry_data INSERT, so a rejected over-length
        // write is fail-fast — nothing is persisted.
        $count = (int) $this->pdo
            ->query('SELECT COUNT(*) FROM entry_data WHERE model_id = ' . $modelId)
            ->fetchColumn();
        self::assertSame(0, $count, 'A rejected over-length write must leave no entry_data row.');
    }

    /**
     * Two 4096-char values share their first 766 chars and differ in the
     * tail. Seeding through the real EntryWriter proves the >255-char write
     * does not raise MySQL 1406 "Data too long", and the `eq` filter has to
     * isolate exactly one of them.
     */
    private function assertEqSeparatesValuesSharingTheirFirst766Characters(int $modelId, string $fieldName): void
    {
        $sharedPrefix = str_repeat('a', 766);
        $valueA = $sharedPrefix . str_repeat('b', 4096 - 766);
        $valueB = $sharedPrefix . str_repeat('c', 4096 - 766);

        $this->seedEntry(1, $modelId, [$fieldName => $valueA]);
        $this->seedEntry(1, $modelId, [$fieldName => $valueB]);

        $page = $this->reader()->read(new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
            filter: LeafNode::local($fieldName, 'eq', $valueA),
            pageSize: 10,
        ));

        self::assertCount(1, $page->rows, 'eq filter must separate values that share their first 766 chars.');
        self::assertSame($valueA, $page->rows[0]->fields[$fieldName]);
    }

    private function columnType(string $column): string
    {
        return (string) $this->pdo
            ->query(
                'SELECT COLUMN_TYPE FROM information_schema.COLUMNS'
                . ' WHERE table_schema = DATABASE()'
                . "   AND table_name = 'entry_slots_page_1'"
                . "   AND column_name = '{$column}'"
            )
            ->fetchColumn();
    }

    private function rowFormat(string $table): string
    {
        return strtolower((string) $this->pdo
            ->query(
                'SELECT ROW_FORMAT FROM information_schema.TABLES'
                . " WHERE table_schema = DATABASE() AND table_name = '{$table}'"
            )
            ->fetchColumn());
    }
}
