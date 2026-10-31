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
use StarDust\Read\NullPlacement;
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

    /**
     * @return array<string, SortSpec>
     */
    private function everyPlacement(string $field): array
    {
        return [
            'ascending, nulls first'  => SortSpec::byField($field, SortDirection::Asc, NullPlacement::First),
            'ascending, nulls last'   => SortSpec::byField($field, SortDirection::Asc, NullPlacement::Last),
            'descending, nulls first' => SortSpec::byField($field, SortDirection::Desc, NullPlacement::First),
            'descending, nulls last'  => SortSpec::byField($field, SortDirection::Desc, NullPlacement::Last),
        ];
    }

    /**
     * Reads the whole ordering in one page and then walks it two rows at a
     * time. The explicit list is what catches an `ORDER BY` and a keyset
     * predicate that agree with each other about the wrong ordering.
     *
     * @param list<int> $expected
     */
    private function assertOrderAndWalk(SortSpec $sort, int $modelId, array $expected, string $label): void
    {
        $page = $this->reader()->read(new EntryQuery(
            tenantId: 1,
            modelId:  $modelId,
            pageSize: 100,
            sort:     $sort,
        ));
        self::assertSame(
            $expected,
            array_map(static fn ($e): int => $e->id, $page->rows),
            $label . ' (single page)',
        );
        self::assertSame($expected, $this->walkAll($sort, $modelId, 2), $label . ' (walk)');
    }

    public function testWalksEveryNullPlacementAcrossANullBlockWiderThanAPage(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        $seed = function (?string $title) use ($modelId, $names): int {
            // An entry with no title still gets a row on the page, with a
            // NULL title slot, because its rank is filterable.
            return $this->seedEntry(
                1,
                $modelId,
                $title === null ? [$names['int'] => 1] : [$names['string'] => $title],
            );
        };
        $bravo  = $seed('bravo');
        $none1  = $seed(null);
        $alpha1 = $seed('alpha');
        $none2  = $seed(null);
        $alpha2 = $seed('alpha');
        $none3  = $seed(null);
        $charlie = $seed('charlie');

        // Three NULLs against a page of two, and two equal values, so a
        // boundary falls inside the NULL block and inside a run of ties.
        // Ties and the NULL block both run in the sort's own direction.
        $expected = [
            'ascending, nulls first'  => [$none1, $none2, $none3, $alpha1, $alpha2, $bravo, $charlie],
            'ascending, nulls last'   => [$alpha1, $alpha2, $bravo, $charlie, $none1, $none2, $none3],
            'descending, nulls first' => [$none3, $none2, $none1, $charlie, $bravo, $alpha2, $alpha1],
            'descending, nulls last'  => [$charlie, $bravo, $alpha2, $alpha1, $none3, $none2, $none1],
        ];
        foreach ($this->everyPlacement($names['string']) as $label => $sort) {
            $this->assertOrderAndWalk($sort, $modelId, $expected[$label], $label);
        }
    }

    public function testWalksEveryNullPlacementOnAnIntegerFieldWithTies(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        $valued = [];
        foreach ([5, 5, 5, 5, 5, 1, 9] as $v) {
            $valued[] = $this->seedEntry(1, $modelId, [$names['int'] => $v]);
        }
        $none = [];
        foreach ([null, null, null] as $_) {
            $none[] = $this->seedEntry(1, $modelId, [$names['string'] => 'x']);
        }

        // 1, then the five fives in id order, then 9.
        $ascending = [$valued[5], $valued[0], $valued[1], $valued[2], $valued[3], $valued[4], $valued[6]];
        $descending = [$valued[6], $valued[4], $valued[3], $valued[2], $valued[1], $valued[0], $valued[5]];
        $expected = [
            'ascending, nulls first'  => [...$none, ...$ascending],
            'ascending, nulls last'   => [...$ascending, ...$none],
            'descending, nulls first' => [...array_reverse($none), ...$descending],
            'descending, nulls last'  => [...$descending, ...array_reverse($none)],
        ];
        foreach ($this->everyPlacement($names['int']) as $label => $sort) {
            $this->assertOrderAndWalk($sort, $modelId, $expected[$label], $label);
        }
    }

    public function testWalksEveryNullPlacementUnderJoinAndExistsFilters(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        $ids = [];
        foreach ([['a', 1], ['b', 2], ['c', 1], [null, 1], [null, 1], ['e', 3]] as [$title, $rank]) {
            $fields = [$names['int'] => $rank];
            if ($title !== null) {
                $fields[$names['string']] = $title;
            }
            $ids[] = $this->seedEntry(1, $modelId, $fields);
        }

        // The pure AND filter takes the JOIN strategy and shares the sort
        // field's page, so the sort reuses the filter's alias. The OR
        // filter takes the EXISTS strategy, which leaves the sort to join
        // the page itself. The placement has to hold in both.
        $join   = LeafNode::local($names['int'], 'eq', 1);
        $exists = new OrNode([
            LeafNode::local($names['int'], 'eq', 1),
            LeafNode::local($names['int'], 'eq', 3),
        ]);

        foreach ($this->everyPlacement($names['string']) as $label => $sort) {
            foreach ([$join, $exists] as $filter) {
                self::assertSame(
                    $this->idsMatching($filter, $modelId, $sort),
                    $this->walkFiltered($filter, $sort, $modelId),
                    $label,
                );
            }
        }

        $ascLast  = SortSpec::byField($names['string'], SortDirection::Asc, NullPlacement::Last);
        $descFirst = SortSpec::byField($names['string'], SortDirection::Desc, NullPlacement::First);
        self::assertSame([$ids[0], $ids[2], $ids[3], $ids[4]], $this->idsMatching($join, $modelId, $ascLast));
        self::assertSame([$ids[4], $ids[3], $ids[2], $ids[0]], $this->idsMatching($join, $modelId, $descFirst));
        self::assertSame(
            [$ids[0], $ids[2], $ids[5], $ids[3], $ids[4]],
            $this->idsMatching($exists, $modelId, $ascLast),
        );
        self::assertSame(
            [$ids[4], $ids[3], $ids[5], $ids[2], $ids[0]],
            $this->idsMatching($exists, $modelId, $descFirst),
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

    /**
     * Reads a first page under `$issuedUnder` and replays its cursor under
     * `$replayedUnder`, returning the ids of the second page, or null when
     * the cursor is refused.
     *
     * @return ?list<int>
     */
    private function replayCursor(SortSpec $issuedUnder, SortSpec $replayedUnder, int $modelId): ?array
    {
        $first = $this->reader()->read(new EntryQuery(
            tenantId: 1,
            modelId:  $modelId,
            pageSize: 2,
            sort:     $issuedUnder,
        ));
        self::assertNotNull($first->nextCursor);

        try {
            $second = $this->reader()->read(new EntryQuery(
                tenantId: 1,
                modelId:  $modelId,
                pageSize: 2,
                cursor:   $first->nextCursor,
                sort:     $replayedUnder,
            ));
        } catch (InvalidCursorException) {
            return null;
        }

        return array_map(static fn ($e): int => $e->id, $second->rows);
    }

    public function testCursorFromAnotherNullPlacementIsRefused(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        foreach (['a', 'b', 'c', 'd'] as $v) {
            $this->seedEntry(1, $modelId, [$names['string'] => $v]);
        }
        $field = $names['string'];

        // The same key and direction with the other placement is a different
        // ordering, so the token is refused rather than walked.
        self::assertNull($this->replayCursor(
            SortSpec::byField($field, SortDirection::Asc, NullPlacement::Last),
            SortSpec::byField($field),
            $modelId,
        ));
        self::assertNull($this->replayCursor(
            SortSpec::byField($field),
            SortSpec::byField($field, SortDirection::Asc, NullPlacement::Last),
            $modelId,
        ));
        self::assertNull($this->replayCursor(
            SortSpec::byField($field, SortDirection::Desc, NullPlacement::First),
            SortSpec::byField($field, SortDirection::Desc),
            $modelId,
        ));
        self::assertNull($this->replayCursor(
            SortSpec::byField($field, SortDirection::Desc),
            SortSpec::byField($field, SortDirection::Desc, NullPlacement::First),
            $modelId,
        ));
    }

    public function testSpellingTheDefaultPlacementOutKeepsACursorValid(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        $ids = [];
        foreach (['a', 'b', 'c', 'd'] as $v) {
            $ids[] = $this->seedEntry(1, $modelId, [$names['string'] => $v]);
        }
        $field = $names['string'];

        self::assertSame(
            [$ids[2], $ids[3]],
            $this->replayCursor(
                SortSpec::byField($field),
                SortSpec::byField($field, SortDirection::Asc, NullPlacement::First),
                $modelId,
            ),
        );
        self::assertSame(
            [$ids[1], $ids[0]],
            $this->replayCursor(
                SortSpec::byField($field, SortDirection::Desc, NullPlacement::Last),
                SortSpec::byField($field, SortDirection::Desc),
                $modelId,
            ),
        );
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
