<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Search;

use PHPUnit\Framework\TestCase;
use StarDust\Filter\Ast\FieldRef;
use StarDust\Filter\Ast\FilterNode;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Filter\Ast\OrNode;
use StarDust\Filter\Ast\TypedValue;
use StarDust\Read\CursorCodec;
use StarDust\Read\EntryQuery;
use StarDust\Read\FieldDescriptor;
use StarDust\Read\SnapshotEntry;
use StarDust\Read\SortDirection;
use StarDust\Read\SortSpec;
use StarDust\Search\Mysql\SqlFilterCompiler;

/**
 * SQL-shape tests for the sort half of {@see SqlFilterCompiler}.
 *
 * Pure-unit, same posture as {@see SqlFilterCompilerTest}: the snapshot
 * and descriptors are built in memory, so this asserts the emitted SQL
 * and binding order without a database. Ordering *behaviour* is covered
 * against real MySQL in `SortOrderTest` / `SortCursorPaginationTest`.
 */
final class SortCompilerTest extends TestCase
{
    private const SORT_FIELD = 'title';

    private function descriptor(int $fieldId, int $pageId, string $slotColumn, string $name): FieldDescriptor
    {
        return new FieldDescriptor(
            fieldId:      $fieldId,
            fieldName:    $name,
            declaredType: 'string',
            isFilterable: true,
            slotColumn:   $slotColumn,
            slotStatus:   'ready',
            pageId:       $pageId,
        );
    }

    private function snapshot(int $sortPageId = 1): SnapshotEntry
    {
        return new SnapshotEntry(
            modelId:           1,
            capturedAtVersion: 1,
            capturedAtUnixTs:  0,
            fieldsByName:      [
                self::SORT_FIELD => $this->descriptor(101, $sortPageId, 'i_str_01', self::SORT_FIELD),
            ],
            pageTableNames:    [1 => 'entry_slots_page_1', 2 => 'entry_slots_page_2'],
        );
    }

    private function leafOnPage(int $pageId, string $slotColumn): LeafNode
    {
        $descriptor = $this->descriptor(202, $pageId, $slotColumn, 'other');
        return new LeafNode(
            'eq',
            new FieldRef(
                modelName:  'demo',
                fieldName:  'other',
                modelId:    1,
                fieldId:    202,
                descriptor: $descriptor,
            ),
            new TypedValue('x'),
        );
    }

    private function query(?FilterNode $filter, ?SortSpec $sort, ?int $cursorEntryId = null): EntryQuery
    {
        return new EntryQuery(
            tenantId: 7,
            modelId:  1,
            filter:   $filter,
            pageSize: 10,
            cursor:   $cursorEntryId === null ? null : CursorCodec::encodeFor($sort, $cursorEntryId),
            sort:     $sort,
        );
    }

    public function testFieldSortJoinsItsPageWithALeftJoin(): void
    {
        $sql = (new SqlFilterCompiler())->compile(
            null,
            $this->query(null, SortSpec::byField(self::SORT_FIELD)),
            $this->snapshot(),
        )->sql;

        // LEFT, never INNER: an entry with no row on the sort field's
        // page must still appear, or the sort silently becomes a filter.
        self::assertStringContainsString('LEFT JOIN entry_slots_page_1 sp', $sql);
        self::assertStringContainsString('ORDER BY sp.i_str_01 ASC, entry_data.id ASC', $sql);
    }

    public function testSortJoinIsEmittedUnderTheExistsStrategyToo(): void
    {
        $filter = new OrNode([
            $this->leafOnPage(2, 'i_str_02'),
            $this->leafOnPage(2, 'i_str_03'),
        ]);
        $compiler = new SqlFilterCompiler();
        self::assertSame('exists', $compiler->chooseStrategy($filter));

        $sql = $compiler->compile(
            $filter,
            $this->query($filter, SortSpec::byField(self::SORT_FIELD)),
            $this->snapshot(),
        )->sql;

        // The EXISTS strategy emits no outer joins of its own, so without
        // this the ORDER BY would reference a column reachable only from
        // inside a subquery.
        self::assertStringContainsString('EXISTS', $sql);
        self::assertStringContainsString('LEFT JOIN entry_slots_page_1 sp', $sql);
        self::assertStringContainsString('ORDER BY sp.i_str_01 ASC', $sql);
    }

    public function testSortReusesAPageTheFilterAlreadyJoined(): void
    {
        // Filter leaf and sort field both live on page 1.
        $filter = $this->leafOnPage(1, 'i_str_02');
        $sql = (new SqlFilterCompiler())->compile(
            $filter,
            $this->query($filter, SortSpec::byField(self::SORT_FIELD)),
            $this->snapshot(1),
        )->sql;

        self::assertStringContainsString('INNER JOIN entry_slots_page_1 p0', $sql);
        self::assertStringNotContainsString('LEFT JOIN entry_slots_page_1 sp', $sql);
        // Ordered through the filter's own alias rather than a second
        // join to the same table.
        self::assertStringContainsString('ORDER BY p0.i_str_01 ASC', $sql);
    }

    public function testSortJoinsItsOwnPageWhenTheFilterJoinedADifferentOne(): void
    {
        // Filter on page 2, sort on page 1 — the joins strategy, but with
        // no alias to reuse. Both joins must appear, with the right kinds.
        $filter = $this->leafOnPage(2, 'i_str_02');
        $sql = (new SqlFilterCompiler())->compile(
            $filter,
            $this->query($filter, SortSpec::byField(self::SORT_FIELD)),
            $this->snapshot(1),
        )->sql;

        self::assertStringContainsString('INNER JOIN entry_slots_page_2 p0', $sql);
        self::assertStringContainsString('LEFT JOIN entry_slots_page_1 sp', $sql);
        self::assertStringContainsString('ORDER BY sp.i_str_01 ASC', $sql);
    }

    public function testIntrinsicIdSortNeedsNoJoinAndNoAnchor(): void
    {
        $fragment = (new SqlFilterCompiler())->compile(
            null,
            $this->query(null, SortSpec::byId(SortDirection::Desc), 42),
            $this->snapshot(),
        );

        self::assertStringNotContainsString('JOIN', $fragment->sql);
        self::assertStringNotContainsString('sort_anchor', $fragment->sql);
        // The cursor IS the sort value when ordering by id, so the keyset
        // predicate is a plain comparison and needs no anchor lookup.
        self::assertStringContainsString('entry_data.id < ?', $fragment->sql);
        self::assertStringContainsString('ORDER BY entry_data.id DESC', $fragment->sql);
        self::assertSame([7, 1, 42, 11], $fragment->bindings);
    }

    public function testCreatedAtSortAnchorsAgainstEntryData(): void
    {
        $fragment = (new SqlFilterCompiler())->compile(
            null,
            $this->query(null, SortSpec::byCreatedAt(), 42),
            $this->snapshot(),
        );

        self::assertStringContainsString(
            'CROSS JOIN (SELECT (SELECT created_at FROM entry_data WHERE id = ? AND tenant_id = ?) AS av) sort_anchor',
            $fragment->sql,
        );
        self::assertStringContainsString('ORDER BY entry_data.created_at ASC, entry_data.id ASC', $fragment->sql);
        // Anchor bindings lead, because the CROSS JOIN precedes the WHERE
        // clause in the emitted SQL.
        self::assertSame([42, 7, 7, 1, 42, 11], $fragment->bindings);
    }

    public function testFieldSortAnchorsAgainstThePageTable(): void
    {
        $fragment = (new SqlFilterCompiler())->compile(
            null,
            $this->query(null, SortSpec::byField(self::SORT_FIELD), 42),
            $this->snapshot(),
        );

        self::assertStringContainsString(
            'CROSS JOIN (SELECT (SELECT i_str_01 FROM entry_slots_page_1 WHERE entry_id = ? AND tenant_id = ?) AS av) sort_anchor',
            $fragment->sql,
        );
        self::assertSame([42, 7, 7, 1, 42, 11], $fragment->bindings);
    }

    public function testKeysetPredicateCarriesTheNullBranchInBothDirections(): void
    {
        $compiler = new SqlFilterCompiler();

        $asc = $compiler->compile(
            null,
            $this->query(null, SortSpec::byField(self::SORT_FIELD), 42),
            $this->snapshot(),
        )->sql;
        // NULLs sort first ascending, so from a NULL anchor every
        // non-NULL row is still ahead.
        self::assertStringContainsString('(sort_anchor.av IS NULL AND sp.i_str_01 IS NOT NULL)', $asc);
        self::assertStringContainsString('sp.i_str_01 <=> sort_anchor.av AND entry_data.id > ?', $asc);

        $desc = $compiler->compile(
            null,
            $this->query(null, SortSpec::byField(self::SORT_FIELD, SortDirection::Desc), 42),
            $this->snapshot(),
        )->sql;
        // NULLs sort last descending, so from a non-NULL anchor the whole
        // NULL block is still ahead.
        self::assertStringContainsString('(sort_anchor.av IS NOT NULL AND sp.i_str_01 IS NULL)', $desc);
        self::assertStringContainsString('sp.i_str_01 <=> sort_anchor.av AND entry_data.id < ?', $desc);
    }

    public function testNoCursorEmitsNoKeysetClauseAndNoAnchor(): void
    {
        $fragment = (new SqlFilterCompiler())->compile(
            null,
            $this->query(null, SortSpec::byField(self::SORT_FIELD)),
            $this->snapshot(),
        );

        self::assertStringNotContainsString('sort_anchor', $fragment->sql);
        self::assertSame([7, 1, 11], $fragment->bindings);
    }

    public function testTenantIsolationSurvivesTheSortJoinAndAnchor(): void
    {
        $sql = (new SqlFilterCompiler())->compile(
            null,
            $this->query(null, SortSpec::byField(self::SORT_FIELD), 42),
            $this->snapshot(),
        )->sql;

        // Blueprint §1.2: every join and subquery replays the tenant
        // predicate. The anchor lookup is a new place to forget it.
        self::assertStringContainsString('sp.tenant_id = entry_data.tenant_id', $sql);
        self::assertStringContainsString('WHERE entry_id = ? AND tenant_id = ?', $sql);
        self::assertStringContainsString('entry_data.tenant_id = ?', $sql);
    }
}
