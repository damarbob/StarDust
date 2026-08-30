<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use StarDust\Exception\InvalidCursorException;
use StarDust\Filter\Ast\FilterNode;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Filter\Ast\OrNode;
use StarDust\Read\Cursor;
use StarDust\Read\CursorCodec;
use StarDust\Read\EntryQuery;
use StarDust\Read\SortDirection;
use StarDust\Read\SortSpec;

/**
 * Keyset pagination under a sort.
 *
 * A sorted page is only correct if the `ORDER BY` and the keyset
 * predicate agree about the ordering at every page boundary. These tests
 * walk a result set one small page at a time and assert the walk equals
 * the single-page ordering exactly — no row skipped, none repeated.
 *
 * @see SortOrderTest for the ordering itself.
 */
final class SortCursorPaginationTest extends ReadPathTestCase
{
    /**
     * Walks every page under `$sort`, returning ids in visit order.
     *
     * @return list<int>
     */
    private function walkAll(?SortSpec $sort, int $modelId, int $pageSize): array
    {
        $reader = $this->reader();
        $ids    = [];
        $cursor = null;
        // Bounded so a cursor that fails to advance ends the test with a
        // readable assertion rather than hanging the suite.
        for ($page = 0; $page < 50; $page++) {
            $result = $reader->read(new EntryQuery(
                tenantId: 1,
                modelId:  $modelId,
                pageSize: $pageSize,
                cursor:   $cursor,
                sort:     $sort,
            ));
            foreach ($result->rows as $entry) {
                $ids[] = $entry->id;
            }
            if ($result->nextCursor === null) {
                return $ids;
            }
            $cursor = $result->nextCursor;
        }
        self::fail('Pagination did not terminate within 50 pages.');
    }

    private function assertWalkMatchesSinglePage(?SortSpec $sort, int $modelId): void
    {
        $singlePage = $this->reader()->read(new EntryQuery(
            tenantId: 1,
            modelId:  $modelId,
            pageSize: 100,
            sort:     $sort,
        ));
        $expected = array_map(static fn ($e): int => $e->id, $singlePage->rows);

        $walked = $this->walkAll($sort, $modelId, 2);

        self::assertSame($expected, $walked);
        self::assertSame(count($expected), count(array_unique($walked)));
    }

    /**
     * @return list<int>
     */
    private function idsMatching(FilterNode $filter, int $modelId, ?SortSpec $sort): array
    {
        $page = $this->reader()->read(new EntryQuery(
            tenantId: 1,
            modelId:  $modelId,
            filter:   $filter,
            pageSize: 100,
            sort:     $sort,
        ));
        return array_map(static fn ($e): int => $e->id, $page->rows);
    }

    /**
     * @return list<int>
     */
    private function walkFiltered(FilterNode $filter, ?SortSpec $sort, int $modelId): array
    {
        $reader = $this->reader();
        $ids    = [];
        $cursor = null;
        for ($page = 0; $page < 50; $page++) {
            $result = $reader->read(new EntryQuery(
                tenantId: 1,
                modelId:  $modelId,
                filter:   $filter,
                pageSize: 2,
                cursor:   $cursor,
                sort:     $sort,
            ));
            foreach ($result->rows as $entry) {
                $ids[] = $entry->id;
            }
            if ($result->nextCursor === null) {
                return $ids;
            }
            $cursor = $result->nextCursor;
        }
        self::fail('Filtered pagination did not terminate within 50 pages.');
    }

    public function testWalksAStringSortWithoutSkippingOrRepeating(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        foreach (['delta', 'alpha', 'echo', 'bravo', 'charlie', 'foxtrot', 'golf'] as $v) {
            $this->seedEntry(1, $modelId, [$names['string'] => $v]);
        }

        $this->assertWalkMatchesSinglePage(SortSpec::byField($names['string']), $modelId);
        $this->assertWalkMatchesSinglePage(
            SortSpec::byField($names['string'], SortDirection::Desc),
            $modelId,
        );
    }

    public function testWalksAnIntSortWithTiesSpanningAPageBoundary(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        // Deliberately more ties than fit in one page, so a boundary
        // lands inside a run of equal sort values — the case the
        // id tiebreak in the keyset predicate exists for.
        foreach ([5, 5, 5, 5, 5, 1, 9] as $v) {
            $this->seedEntry(1, $modelId, [$names['int'] => $v]);
        }

        $this->assertWalkMatchesSinglePage(SortSpec::byField($names['int']), $modelId);
        $this->assertWalkMatchesSinglePage(
            SortSpec::byField($names['int'], SortDirection::Desc),
            $modelId,
        );
    }

    public function testWalksThroughTheNullBlockInBothDirections(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        // Three rows with no value for the sort field and three with one,
        // so the NULL block is wider than a page and the walk must cross
        // into and out of it.
        foreach ([null, null, null] as $_) {
            $this->seedEntry(1, $modelId, [$names['int'] => 1]);
        }
        foreach (['alpha', 'bravo', 'charlie'] as $v) {
            $this->seedEntry(1, $modelId, [$names['string'] => $v]);
        }

        // Each direction reaches the NULL block from a different side:
        // ascending starts inside it, descending ends inside it.
        $this->assertWalkMatchesSinglePage(SortSpec::byField($names['string']), $modelId);
        $this->assertWalkMatchesSinglePage(
            SortSpec::byField($names['string'], SortDirection::Desc),
            $modelId,
        );
    }

    public function testWalksStringsThatDivergeBeyondTheSortLengthLimit(): void
    {
        [$modelId, $names] = $this->setupSortableModel();

        // MySQL's max_sort_length is 1024 bytes by default. Probed on
        // 8.0.13: ORDER BY on a TEXT column is nonetheless exact — values
        // differing only past that boundary still order correctly, even
        // with the setting forced down to 8. These rows pin that, because
        // the engine's keyset predicate compares the full value and would
        // disagree with a truncating ORDER BY at exactly this boundary,
        // skipping or repeating rows across a page edge.
        $prefix = str_repeat('x', 1100);
        foreach (['c', 'a', 'd', 'b'] as $suffix) {
            $this->seedEntry(1, $modelId, [$names['string'] => $prefix . $suffix]);
        }

        $sorted = $this->reader()->read(new EntryQuery(
            tenantId: 1,
            modelId:  $modelId,
            pageSize: 100,
            sort:     SortSpec::byField($names['string']),
        ));
        $tails = array_map(
            static fn ($e): string => substr((string) $e->fields['title'], -1),
            $sorted->rows,
        );
        self::assertSame(['a', 'b', 'c', 'd'], $tails);

        $this->assertWalkMatchesSinglePage(SortSpec::byField($names['string']), $modelId);
    }

    public function testWalksACreatedAtSort(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        foreach (['a', 'b', 'c', 'd', 'e'] as $v) {
            $this->seedEntry(1, $modelId, [$names['string'] => $v]);
        }

        // The created_at anchor reads entry_data rather than a page
        // table, which is a different branch of compileAnchorJoin().
        $this->assertWalkMatchesSinglePage(SortSpec::byCreatedAt(), $modelId);
        $this->assertWalkMatchesSinglePage(SortSpec::byCreatedAt(SortDirection::Desc), $modelId);
    }

    public function testWalksASortCombinedWithAJoinStrategyFilter(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        foreach ([['a', 1], ['b', 2], ['c', 1], ['d', 2], ['e', 1]] as [$t, $r]) {
            $this->seedEntry(1, $modelId, [$names['string'] => $t, $names['int'] => $r]);
        }

        // Filter and sort land on the SAME page here, so this exercises
        // the alias-reuse branch end-to-end rather than only in SQL text.
        $filter = LeafNode::local($names['int'], 'eq', 1);
        $expected = $this->idsMatching($filter, $modelId, SortSpec::byField($names['string'], SortDirection::Desc));
        self::assertCount(3, $expected);

        self::assertSame(
            $expected,
            $this->walkFiltered($filter, SortSpec::byField($names['string'], SortDirection::Desc), $modelId),
        );
    }

    public function testWalksASortCombinedWithAnExistsStrategyFilter(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        foreach ([['a', 1], ['b', 2], ['c', 3], ['d', 2], ['e', 1]] as [$t, $r]) {
            $this->seedEntry(1, $modelId, [$names['string'] => $t, $names['int'] => $r]);
        }

        // An OR tree forces the EXISTS strategy, which emits no outer
        // joins of its own — so the sort join and the anchor have to
        // stand on their own here.
        $filter = new OrNode([
            LeafNode::local($names['int'], 'eq', 1),
            LeafNode::local($names['int'], 'eq', 3),
        ]);
        $expected = $this->idsMatching($filter, $modelId, SortSpec::byField($names['string']));
        self::assertCount(3, $expected);

        self::assertSame(
            $expected,
            $this->walkFiltered($filter, SortSpec::byField($names['string']), $modelId),
        );
    }

    public function testUnsortedReadStillEmitsAV1Cursor(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        foreach (['a', 'b', 'c'] as $v) {
            $this->seedEntry(1, $modelId, [$names['string'] => $v]);
        }

        $page = $this->reader()->read(new EntryQuery(
            tenantId: 1,
            modelId:  $modelId,
            pageSize: 2,
        ));

        self::assertNotNull($page->nextCursor);
        // Byte-level: an unsorted caller's token must be exactly what it
        // was before sorting existed, or every cursor in flight breaks.
        self::assertStringStartsWith('v1:', (string) base64_decode(
            strtr($page->nextCursor->opaque, '-_', '+/'),
            true,
        ));
    }

    public function testAV1CursorStillPaginatesAnUnsortedRead(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        $ids = [];
        foreach (['a', 'b', 'c'] as $v) {
            $ids[] = $this->seedEntry(1, $modelId, [$names['string'] => $v]);
        }

        // Hand-built in the pre-sort format, as a consumer's stored token
        // would be.
        $legacy = CursorCodec::encode($ids[0]);

        $page = $this->reader()->read(new EntryQuery(
            tenantId: 1,
            modelId:  $modelId,
            pageSize: 10,
            cursor:   $legacy,
        ));

        self::assertSame([$ids[1], $ids[2]], array_map(static fn ($e): int => $e->id, $page->rows));
    }

    public function testAV1CursorIsAcceptedByAnExplicitDefaultSort(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        $ids = [];
        foreach (['a', 'b', 'c'] as $v) {
            $ids[] = $this->seedEntry(1, $modelId, [$names['string'] => $v]);
        }

        // `null` and `byId(Asc)` name the same ordering, so a v1 token
        // must not be rejected merely because the caller started spelling
        // the default out.
        $page = $this->reader()->read(new EntryQuery(
            tenantId: 1,
            modelId:  $modelId,
            pageSize: 10,
            cursor:   CursorCodec::encode($ids[0]),
            sort:     SortSpec::byId(),
        ));

        self::assertSame([$ids[1], $ids[2]], array_map(static fn ($e): int => $e->id, $page->rows));
    }

    public function testCursorFromADifferentSortIsRejected(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        foreach (['a', 'b', 'c'] as $v) {
            $this->seedEntry(1, $modelId, [$names['string'] => $v]);
        }

        $first = $this->reader()->read(new EntryQuery(
            tenantId: 1,
            modelId:  $modelId,
            pageSize: 2,
            sort:     SortSpec::byField($names['string']),
        ));
        self::assertNotNull($first->nextCursor);

        // ADR 0006 has always said a cursor is invalidated when the sort
        // changes. Until there was a sort parameter that was unenforceable;
        // replaying the token now walks a detectably different ordering
        // and is refused rather than silently returning wrong rows.
        $this->expectException(InvalidCursorException::class);
        $this->reader()->read(new EntryQuery(
            tenantId: 1,
            modelId:  $modelId,
            pageSize: 2,
            cursor:   $first->nextCursor,
            sort:     SortSpec::byField($names['int']),
        ));
    }

    public function testCursorFromAReversedDirectionIsRejected(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        foreach (['a', 'b', 'c'] as $v) {
            $this->seedEntry(1, $modelId, [$names['string'] => $v]);
        }

        $first = $this->reader()->read(new EntryQuery(
            tenantId: 1,
            modelId:  $modelId,
            pageSize: 2,
            sort:     SortSpec::byField($names['string']),
        ));
        self::assertNotNull($first->nextCursor);

        // Same key, opposite direction — a different ordering, so the
        // same rejection. Direction is stamped alongside the key for
        // exactly this case.
        $this->expectException(InvalidCursorException::class);
        $this->reader()->read(new EntryQuery(
            tenantId: 1,
            modelId:  $modelId,
            pageSize: 2,
            cursor:   $first->nextCursor,
            sort:     SortSpec::byField($names['string'], SortDirection::Desc),
        ));
    }

    public function testStructurallyMalformedCursorIsRejected(): void
    {
        [$modelId] = $this->setupSortableModel();
        $this->expectException(InvalidCursorException::class);
        $this->reader()->read(new EntryQuery(
            tenantId: 1,
            modelId:  $modelId,
            cursor:   new Cursor('not-a-cursor'),
        ));
    }
}
