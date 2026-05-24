<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use PDO;
use StarDust\Read\EntryQuery;
use StarDust\Read\QueryFilter;

final class EntryReaderTest extends ReadPathTestCase
{
    public function testReadReturnsAllRowsBelowPageSize(): void
    {
        [$modelId, , , $fieldName] = $this->setupFilterableStringField();

        $this->seedEntry(1, $modelId, [$fieldName => 'alpha']);
        $this->seedEntry(1, $modelId, [$fieldName => 'beta']);
        $this->seedEntry(1, $modelId, [$fieldName => 'gamma']);

        $page = $this->reader()->read(new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
            pageSize: 10,
        ));

        self::assertCount(3, $page->rows);
        self::assertNull($page->nextCursor, 'page below threshold must have absent next-cursor sentinel');

        $values = array_map(static fn ($e): mixed => $e->fields[$fieldName], $page->rows);
        self::assertSame(['alpha', 'beta', 'gamma'], $values);
    }

    public function testReadWithEqualityFilterUsesIndexedSlot(): void
    {
        [$modelId, , , $fieldName] = $this->setupFilterableStringField();

        $this->seedEntry(1, $modelId, [$fieldName => 'alpha']);
        $this->seedEntry(1, $modelId, [$fieldName => 'beta']);
        $this->seedEntry(1, $modelId, [$fieldName => 'alpha']);

        $page = $this->reader()->read(new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
            filters: [new QueryFilter($fieldName, 'eq', 'alpha')],
            pageSize: 10,
        ));

        self::assertCount(2, $page->rows);
        foreach ($page->rows as $entry) {
            self::assertSame('alpha', $entry->fields[$fieldName]);
        }
    }

    public function testGetReturnsTheRequestedEntry(): void
    {
        [$modelId, , , $fieldName] = $this->setupFilterableStringField();
        $entryId = $this->seedEntry(1, $modelId, [$fieldName => 'point']);

        $entry = $this->reader()->get(1, $entryId);

        self::assertNotNull($entry);
        self::assertSame($entryId, $entry->id);
        self::assertSame(1, $entry->tenantId);
        self::assertSame($modelId, $entry->modelId);
        self::assertSame('point', $entry->fields[$fieldName]);
    }

    public function testGetReturnsNullForUnknownId(): void
    {
        [$modelId] = $this->setupFilterableStringField();
        $this->seedEntry(1, $modelId, ['name' => 'present']);

        $entry = $this->reader()->get(1, 999_999);
        self::assertNull($entry);
    }

    public function testIndexedFilterProducesIndexRangeScan(): void
    {
        // Phase 4 exit criterion #4: every EXPLAIN for a slot-based
        // filter shows an index range scan on the (tenant_id,
        // slot_column) composite index, never a full table scan.
        [$modelId, , , $fieldName] = $this->setupFilterableStringField();

        for ($i = 0; $i < 5; $i++) {
            $this->seedEntry(1, $modelId, [$fieldName => 'value_' . $i]);
        }

        $explain = $this->pdo->query(
            "EXPLAIN SELECT entry_data.id FROM entry_data"
            . " INNER JOIN entry_slots_page_1 p0"
            . "   ON p0.entry_id = entry_data.id AND p0.tenant_id = entry_data.tenant_id"
            . " WHERE entry_data.tenant_id = 1"
            . "   AND entry_data.model_id = {$modelId}"
            . "   AND entry_data.deleted_at IS NULL"
            . "   AND entry_data.id > 0"
            . "   AND p0.i_str_01 = 'value_1'"
            . " ORDER BY entry_data.id ASC LIMIT 11"
        )?->fetchAll(PDO::FETCH_ASSOC) ?? [];

        $touchedSlotPage = array_filter(
            $explain,
            static fn (array $row): bool => ($row['table'] ?? null) === 'p0'
        );
        self::assertNotEmpty($touchedSlotPage, 'EXPLAIN must include the slot-page join');

        foreach ($touchedSlotPage as $row) {
            // Either a ref/eq_ref index lookup or a range scan over the
            // composite index — anything but ALL (full table scan).
            self::assertNotSame(
                'ALL',
                $row['type'] ?? null,
                'slot-page join must not be a full table scan'
            );
        }
    }
}
