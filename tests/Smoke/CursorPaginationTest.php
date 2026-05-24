<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use StarDust\Exception\InvalidCursorException;
use StarDust\Read\Cursor;
use StarDust\Read\EntryQuery;

final class CursorPaginationTest extends ReadPathTestCase
{
    public function testPaginationCoversAllRowsWithoutOverlap(): void
    {
        // Phase 4 exit criterion #3: pages do not duplicate or skip
        // rows for entries that existed before page 1.
        [$modelId, , , $fieldName] = $this->setupFilterableStringField();

        $expectedIds = [];
        for ($i = 0; $i < 25; $i++) {
            $expectedIds[] = $this->seedEntry(1, $modelId, [$fieldName => 'v_' . $i]);
        }

        $reader = $this->reader();
        $observed = [];
        $cursor = null;
        $loopGuard = 10;

        do {
            $page = $reader->read(new EntryQuery(
                tenantId: 1,
                modelId: $modelId,
                pageSize: 10,
                cursor: $cursor,
            ));
            foreach ($page->rows as $entry) {
                $observed[] = $entry->id;
            }
            $cursor = $page->nextCursor;
            $loopGuard--;
        } while ($cursor !== null && $loopGuard > 0);

        self::assertGreaterThan(0, $loopGuard, 'pagination did not terminate');
        self::assertSame($expectedIds, $observed);
    }

    public function testTrailingPageReturnsNullNextCursor(): void
    {
        // Phase 4 exit criterion #8 + #9: when fewer than pageSize+1
        // rows return, the API returns absent next-cursor.
        [$modelId] = $this->setupFilterableStringField();

        for ($i = 0; $i < 7; $i++) {
            $this->seedEntry(1, $modelId, ['name' => 'r_' . $i]);
        }

        // pageSize = 5 — first page has more, second page is trailing.
        $reader = $this->reader();

        $first = $reader->read(new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
            pageSize: 5,
        ));
        self::assertCount(5, $first->rows);
        self::assertNotNull($first->nextCursor, 'page with N+1 rows must hand back a cursor');

        $second = $reader->read(new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
            pageSize: 5,
            cursor: $first->nextCursor,
        ));
        self::assertCount(2, $second->rows);
        self::assertNull($second->nextCursor, 'final page must omit next-cursor sentinel');
    }

    public function testRowInsertedMidPaginationAppearsLater(): void
    {
        // Phase 4 exit criterion #3, second clause: dataset mutation
        // between requests must not produce skips/duplicates for
        // rows that existed before page 1.
        [$modelId] = $this->setupFilterableStringField();

        $existingIds = [];
        for ($i = 0; $i < 4; $i++) {
            $existingIds[] = $this->seedEntry(1, $modelId, ['name' => 'pre_' . $i]);
        }

        $reader = $this->reader();
        $first = $reader->read(new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
            pageSize: 2,
        ));
        self::assertSame(array_slice($existingIds, 0, 2), array_map(static fn ($e): int => $e->id, $first->rows));

        // Insert a new row between page 1 and page 2 — should
        // appear AFTER the existing pre-page-1 rows on page 2 / 3
        // because entry_data.id is monotonic.
        $midRowId = $this->seedEntry(1, $modelId, ['name' => 'inserted_mid_pagination']);

        $second = $reader->read(new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
            pageSize: 2,
            cursor: $first->nextCursor,
        ));
        $secondIds = array_map(static fn ($e): int => $e->id, $second->rows);
        self::assertSame(array_slice($existingIds, 2, 2), $secondIds, 'page 2 returns the next two existing entries');
        self::assertNotContains($midRowId, $secondIds, 'mid-insert row not yet visible');

        $third = $reader->read(new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
            pageSize: 2,
            cursor: $second->nextCursor,
        ));
        $thirdIds = array_map(static fn ($e): int => $e->id, $third->rows);
        self::assertContains($midRowId, $thirdIds, 'mid-insert row appears in a later page');
    }

    public function testMalformedCursorIsRejected(): void
    {
        [$modelId] = $this->setupFilterableStringField();

        try {
            $this->reader()->read(new EntryQuery(
                tenantId: 1,
                modelId: $modelId,
                cursor: new Cursor('not-a-real-cursor-blob'),
            ));
            self::fail('Expected InvalidCursorException');
        } catch (InvalidCursorException $e) {
            self::assertStringContainsString('Cursor decode failed', $e->getMessage());
        }
    }
}
