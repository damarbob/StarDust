<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Page;

use StarDust\Exception\UncoercibleSlotValueException;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Read\EntryQuery;
use StarDust\Tests\Smoke\ReadPathTestCase;

/**
 * Gap 4 / ADR 0030: string slots store the full normative QueryFilter
 * bound (4096 chars, `FilterLimits::DEFAULT_MAX_STRING_LENGTH`) as `TEXT`
 * and index a 766-char prefix under ROW_FORMAT=DYNAMIC.
 *
 * `VARCHAR(4096)` is not an option: 25 string slots × 4096 × 4 bytes
 * (utf8mb4) exceeds MySQL's 65,535-byte row-definition limit (errno 1118).
 * TEXT escapes the row limit but must be prefix-indexed, and the 766-char
 * prefix (3072 bytes with tenant_id) requires the DYNAMIC key-size limit.
 *
 * Three proofs:
 *   - the DDL shape (`text` column, `SUB_PART = 766` index, `Dynamic` row
 *     format), and
 *   - end-to-end behavior — a >255-char value writes without MySQL 1406 and
 *     an `eq` filter disambiguates two values that share the indexed prefix,
 *     proving the per-operator full-value recheck behind the prefix scan,
 *   - point-read returns the full-length value from the JSON payload.
 */
final class StringSlotWidthTest extends ReadPathTestCase
{
    public function testStringSlotIsTextWithPrefixIndexAndDynamicRowFormat(): void
    {
        $this->provisionPage(['i_str_01']);

        $columnType = $this->pdo
            ->query(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS"
                . " WHERE table_schema = DATABASE()"
                . "   AND table_name = 'entry_slots_page_1'"
                . "   AND column_name = 'i_str_01'"
            )
            ->fetchColumn();
        self::assertSame('text', strtolower((string) $columnType));

        $subPart = $this->pdo
            ->query(
                "SELECT SUB_PART FROM information_schema.STATISTICS"
                . " WHERE table_schema = DATABASE()"
                . "   AND table_name = 'entry_slots_page_1'"
                . "   AND index_name = 'ix_entry_slots_page_1_i_str_01'"
                . "   AND column_name = 'i_str_01'"
            )
            ->fetchColumn();
        self::assertSame(766, (int) $subPart, 'String slot index must use a 766-char prefix.');

        $rowFormat = $this->pdo
            ->query(
                "SELECT ROW_FORMAT FROM information_schema.TABLES"
                . " WHERE table_schema = DATABASE()"
                . "   AND table_name = 'entry_slots_page_1'"
            )
            ->fetchColumn();
        self::assertSame(
            'dynamic',
            strtolower((string) $rowFormat),
            'Page DDL must pin ROW_FORMAT=DYNAMIC — the 766-char prefix needs the 3072-byte key limit.'
        );
    }

    public function testLongFilterableStringWritesAndFiltersExactly(): void
    {
        [$modelId, , , $fieldName] = $this->setupFilterableStringField();

        // Two 4096-char values sharing the first 766 chars (the indexed
        // prefix) but differing in the tail. The prefix index alone would
        // return both; the full-value recheck must isolate exactly one.
        $sharedPrefix = str_repeat('a', 766);
        $valueA = $sharedPrefix . str_repeat('b', 4096 - 766);
        $valueB = $sharedPrefix . str_repeat('c', 4096 - 766);

        // Seeding via the real EntryWriter proves the >255-char write does
        // not raise MySQL 1406 "Data too long".
        $this->seedEntry(1, $modelId, [$fieldName => $valueA]);
        $this->seedEntry(1, $modelId, [$fieldName => $valueB]);

        $page = $this->reader()->read(new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
            filter: LeafNode::local($fieldName, 'eq', $valueA),
            pageSize: 10,
        ));

        self::assertCount(1, $page->rows, 'eq filter must recheck beyond the shared 766-char prefix.');
        self::assertSame($valueA, $page->rows[0]->fields[$fieldName]);
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
}
