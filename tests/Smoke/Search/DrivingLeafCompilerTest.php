<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Search;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use StarDust\Filter\Ast\AndNode;
use StarDust\Filter\Ast\FieldRef;
use StarDust\Filter\Ast\FilterNode;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Filter\Ast\NotNode;
use StarDust\Filter\Ast\OrNode;
use StarDust\Filter\Ast\TypedValue;
use StarDust\Read\CursorCodec;
use StarDust\Read\EntryQuery;
use StarDust\Read\FieldDescriptor;
use StarDust\Read\SnapshotEntry;
use StarDust\Read\SortDirection;
use StarDust\Read\SortSpec;
use StarDust\Read\StringKey;
use StarDust\Search\Mysql\SqlFilterCompiler;

/**
 * SQL-shape tests for the driving-leaf rule of {@see SqlFilterCompiler}
 * (ADR 0056).
 *
 * Pure-unit, same posture as {@see SqlFilterCompilerTest}: the snapshot and
 * descriptors are built in memory, so this pins the emitted SQL and the
 * binding order without a database. That the shape really bounds the read
 * is measured against real MySQL in `BoundedFilteredReadTest`.
 *
 * Each part of the rule was checked by breaking it and watching a case here
 * go red: not requiring an equality, letting a string slot with no key drive,
 * letting a created-at or field sort drive, dropping the driving page's own
 * tenant predicate, leaving the index name unquoted, leaving the order on
 * `entry_data.id`, and never choosing a driving leaf.
 *
 * A string equality drives only through its generated key (ADR 0057), in a
 * second pass that runs when no integer, numeric or datetime equality
 * qualified. The cases for it are at the end of the class.
 */
final class DrivingLeafCompilerTest extends TestCase
{
    private const INT_INDEX = 'ix_entry_slots_page_1_i_int_01';

    /** What a read with no driving leaf starts with, and what the rule must never change. */
    private const TODAYS_FROM = 'SELECT entry_data.id FROM entry_data';

    private static function leaf(int $fieldId, int $pageId, string $slotColumn, string $op, mixed $value, string $declaredType = 'int'): LeafNode
    {
        $descriptor = new FieldDescriptor(
            fieldId:      $fieldId,
            fieldName:    "field_{$fieldId}",
            declaredType: $declaredType,
            isFilterable: true,
            slotColumn:   $slotColumn,
            slotStatus:   'ready',
            pageId:       $pageId,
        );

        return new LeafNode(
            $op,
            new FieldRef(
                modelName:  'demo',
                fieldName:  $descriptor->fieldName,
                modelId:    1,
                fieldId:    $fieldId,
                descriptor: $descriptor,
            ),
            $value === null ? null : new TypedValue($value),
        );
    }

    /**
     * @param array<int, array<string, string>> $orderedIndexes
     * @param array<int, array<string, StringKey>> $stringKeys
     */
    private function snapshot(
        array $orderedIndexes = [1 => ['i_int_01' => self::INT_INDEX]],
        array $fields = [],
        array $stringKeys = [],
    ): SnapshotEntry {
        return new SnapshotEntry(
            modelId:           1,
            capturedAtVersion: 1,
            capturedAtUnixTs:  0,
            fieldsByName:      $fields,
            pageTableNames:    [1 => 'entry_slots_page_1', 2 => 'entry_slots_page_2'],
            orderedIndexes:    $orderedIndexes,
            stringKeys:        $stringKeys,
        );
    }

    private function query(?FilterNode $filter, ?SortSpec $sort = null, ?int $cursorEntryId = null): EntryQuery
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

    private function sql(FilterNode $filter, ?SortSpec $sort = null, ?SnapshotEntry $snapshot = null, ?int $cursor = null): string
    {
        return (new SqlFilterCompiler())
            ->compile($filter, $this->query($filter, $sort, $cursor), $snapshot ?? $this->snapshot())
            ->sql;
    }

    public function testAnIntegerEqualityDrivesFromItsOwnPage(): void
    {
        $filter = self::leaf(101, 1, 'i_int_01', 'eq', 5);

        $fragment = (new SqlFilterCompiler())->compile($filter, $this->query($filter), $this->snapshot());

        self::assertSame(
            'SELECT STRAIGHT_JOIN entry_data.id'
            . ' FROM entry_slots_page_1 p0 FORCE INDEX (`' . self::INT_INDEX . '`)'
            . ' INNER JOIN entry_data ON entry_data.id = p0.entry_id AND entry_data.tenant_id = p0.tenant_id'
            . ' WHERE entry_data.tenant_id = ? AND entry_data.model_id = ? AND p0.tenant_id = ?'
            . ' AND entry_data.deleted_at IS NULL AND p0.i_int_01 = ?'
            . ' ORDER BY p0.entry_id ASC LIMIT ?',
            $fragment->sql,
        );
        // Placeholders in clause order: tenant, model, the driving page's tenant, the filter value, the limit.
        self::assertSame([7, 1, 7, 5, 11], $fragment->bindings);
    }

    public function testWithoutAnIndexMapTheSqlIsTodaysByteForByte(): void
    {
        $filter = self::leaf(101, 1, 'i_int_01', 'eq', 5);

        $fragment = (new SqlFilterCompiler())->compile($filter, $this->query($filter), $this->snapshot([]));

        self::assertSame(
            self::TODAYS_FROM
            . ' INNER JOIN entry_slots_page_1 p0 ON p0.entry_id = entry_data.id AND p0.tenant_id = entry_data.tenant_id'
            . ' WHERE entry_data.tenant_id = ? AND entry_data.model_id = ? AND entry_data.deleted_at IS NULL AND p0.i_int_01 = ?'
            . ' ORDER BY entry_data.id ASC LIMIT ?',
            $fragment->sql,
        );
        self::assertSame([7, 1, 5, 11], $fragment->bindings);
    }

    /** @return iterable<string, array{FilterNode}> */
    public static function leavesThatNeverDrive(): iterable
    {
        $make = static fn (string $col, string $op, mixed $v, string $type = 'int'): LeafNode =>
            self::leaf(101, 1, $col, $op, $v, $type);

        yield 'string equality with no key' => [$make('i_str_01', 'eq', 'paid', 'string')];
        yield 'greater than'    => [$make('i_int_01', 'gt', 5)];
        yield 'not equal'       => [$make('i_int_01', 'neq', 5)];
        yield 'in list'         => [$make('i_int_01', 'in', [1, 2, 3])];
        yield 'not in list'     => [$make('i_int_01', 'nin', [1, 2, 3])];
        yield 'between'         => [$make('i_int_01', 'between', [1, 9])];
        yield 'prefix'          => [$make('i_str_01', 'prefix', 'pa', 'string')];
        yield 'is null'         => [$make('i_int_01', 'is_null', null)];
        yield 'or'              => [new OrNode([$make('i_int_01', 'eq', 1), $make('i_int_01', 'eq', 2)])];
        yield 'not'             => [new NotNode($make('i_int_01', 'eq', 1))];
    }

    #[DataProvider('leavesThatNeverDrive')]
    public function testLeavesThatNeverDriveKeepTodaysSql(FilterNode $filter): void
    {
        // The map names an index for every column used, so only the leaf's
        // own kind can be what keeps the rewrite away.
        $snapshot = $this->snapshot([1 => ['i_int_01' => self::INT_INDEX, 'i_str_01' => 'ix_entry_slots_page_1_i_str_01']]);

        $sql = $this->sql($filter, null, $snapshot);

        self::assertStringStartsWith(self::TODAYS_FROM, $sql);
        self::assertStringNotContainsString('STRAIGHT_JOIN', $sql);
        self::assertStringNotContainsString('FORCE INDEX', $sql);
    }

    public function testTheIntegerLeafDrivesEvenWhenAStringLeafComesFirst(): void
    {
        $filter = new AndNode([
            self::leaf(101, 1, 'i_str_01', 'eq', 'paid', 'string'),
            self::leaf(102, 2, 'i_int_01', 'eq', 5),
        ]);
        $snapshot = $this->snapshot([2 => ['i_int_01' => 'ix_entry_slots_page_2_i_int_01']]);

        $fragment = (new SqlFilterCompiler())->compile($filter, $this->query($filter), $snapshot);

        // Aliases are handed out in leaf order, so the driving page is p1.
        // It comes first in FROM, then entry_data, then the string page.
        self::assertSame(
            'SELECT STRAIGHT_JOIN entry_data.id'
            . ' FROM entry_slots_page_2 p1 FORCE INDEX (`ix_entry_slots_page_2_i_int_01`)'
            . ' INNER JOIN entry_data ON entry_data.id = p1.entry_id AND entry_data.tenant_id = p1.tenant_id'
            . ' INNER JOIN entry_slots_page_1 p0 ON p0.entry_id = entry_data.id AND p0.tenant_id = entry_data.tenant_id'
            . ' WHERE entry_data.tenant_id = ? AND entry_data.model_id = ? AND p1.tenant_id = ?'
            . ' AND entry_data.deleted_at IS NULL AND p0.i_str_01 = ? AND p1.i_int_01 = ?'
            . ' ORDER BY p1.entry_id ASC LIMIT ?',
            $fragment->sql,
        );
        // Filter bindings keep leaf order, so the string value still precedes the integer.
        self::assertSame([7, 1, 7, 'paid', 5, 11], $fragment->bindings);
    }

    public function testTheFirstLeafWithAnIndexDrivesWhenAnEarlierOneHasNone(): void
    {
        $filter = new AndNode([
            self::leaf(101, 1, 'i_int_02', 'eq', 4),
            self::leaf(102, 1, 'i_int_01', 'eq', 5),
        ]);

        $sql = $this->sql($filter);

        // Only i_int_01 has an index in the default map, and both leaves share a page and so an alias.
        self::assertStringContainsString('FORCE INDEX (`' . self::INT_INDEX . '`)', $sql);
        self::assertStringContainsString('ORDER BY p0.entry_id ASC', $sql);
    }

    /** @return iterable<string, array{string, string, mixed, string}> */
    public static function otherFullWidthFamilies(): iterable
    {
        yield 'numeric'  => ['i_num_01', 'numeric', 2.5, '2.5'];
        yield 'datetime' => ['i_dt_01', 'datetime', '2026-01-01T03:00:00Z', '2026-01-01 03:00:00'];
    }

    #[DataProvider('otherFullWidthFamilies')]
    public function testNumericAndDatetimeDriveLikeInteger(string $column, string $declaredType, mixed $value, string $expectedBinding): void
    {
        $filter = self::leaf(101, 1, $column, 'eq', $value, $declaredType);
        $snapshot = $this->snapshot([1 => [$column => "ix_entry_slots_page_1_{$column}"]]);

        $fragment = (new SqlFilterCompiler())->compile($filter, $this->query($filter), $snapshot);

        self::assertStringStartsWith('SELECT STRAIGHT_JOIN entry_data.id FROM entry_slots_page_1 p0 FORCE INDEX (`ix_entry_slots_page_1_' . $column . '`)', $fragment->sql);
        self::assertStringContainsString("p0.{$column} = ?", $fragment->sql);
        // The value still goes through the MySQL literal rendering, so a datetime keeps `Y-m-d H:i:s`.
        self::assertEquals($expectedBinding, (string) $fragment->bindings[3]);
    }

    public function testTheKeysetRunsOnTheDrivingPageAscending(): void
    {
        $filter = self::leaf(101, 1, 'i_int_01', 'eq', 5);

        $fragment = (new SqlFilterCompiler())->compile($filter, $this->query($filter, null, 42), $this->snapshot());

        self::assertStringContainsString('p0.entry_id > ?', $fragment->sql);
        self::assertStringNotContainsString('entry_data.id >', $fragment->sql);
        // tenant, model, driving tenant, keyset anchor, filter value, limit.
        self::assertSame([7, 1, 7, 42, 5, 11], $fragment->bindings);
    }

    public function testTheKeysetAndOrderRunOnTheDrivingPageDescending(): void
    {
        $filter = self::leaf(101, 1, 'i_int_01', 'eq', 5);
        $sort = SortSpec::byId(SortDirection::Desc);

        $fragment = (new SqlFilterCompiler())->compile($filter, $this->query($filter, $sort, 42), $this->snapshot());

        self::assertStringContainsString('p0.entry_id < ?', $fragment->sql);
        self::assertStringEndsWith('ORDER BY p0.entry_id DESC LIMIT ?', $fragment->sql);
        self::assertSame([7, 1, 7, 42, 5, 11], $fragment->bindings);
    }

    public function testACreatedAtSortKeepsTodaysSql(): void
    {
        $filter = self::leaf(101, 1, 'i_int_01', 'eq', 5);

        $sql = $this->sql($filter, SortSpec::byCreatedAt());

        self::assertStringStartsWith(self::TODAYS_FROM, $sql);
        self::assertStringContainsString('ORDER BY entry_data.created_at ASC, entry_data.id ASC', $sql);
    }

    public function testAFieldSortKeepsTodaysSql(): void
    {
        $sortField = new FieldDescriptor(
            fieldId:      202,
            fieldName:    'title',
            declaredType: 'string',
            isFilterable: true,
            slotColumn:   'i_str_01',
            slotStatus:   'ready',
            pageId:       1,
        );
        $filter = self::leaf(101, 1, 'i_int_01', 'eq', 5);
        $snapshot = $this->snapshot([1 => ['i_int_01' => self::INT_INDEX]], ['title' => $sortField]);

        $sql = $this->sql($filter, SortSpec::byField('title'), $snapshot);

        self::assertStringStartsWith(self::TODAYS_FROM, $sql);
        self::assertStringNotContainsString('STRAIGHT_JOIN', $sql);
    }

    public function testAnIndexNameIsQuotedAsAnIdentifier(): void
    {
        $filter = self::leaf(101, 1, 'i_int_01', 'eq', 5);
        $snapshot = $this->snapshot([1 => ['i_int_01' => 'odd`name']]);

        $sql = $this->sql($filter, null, $snapshot);

        self::assertStringContainsString('FORCE INDEX (`odd``name`)', $sql);
    }

    public function testAnIndexRecordedForAnotherColumnDoesNotDrive(): void
    {
        $filter = self::leaf(101, 1, 'i_int_02', 'eq', 5);

        $sql = $this->sql($filter);

        self::assertStringStartsWith(self::TODAYS_FROM, $sql);
    }

    private const STR_INDEX = 'ix_entry_slots_page_1_i_str_01';
    private const STR2_INDEX = 'ix_entry_slots_page_1_i_str_02';
    private const WEIGHT = 'WEIGHT_STRING(CONVERT(? USING utf8mb4) COLLATE utf8mb4_0900_ai_ci)';

    /**
     * A snapshot whose page 1 indexes `i_str_01` (and `i_str_02`) through keys.
     *
     * @return array{array<int, array<string, string>>, array<int, array<string, StringKey>>}
     */
    private static function keyedStrings(): array
    {
        return [
            [1 => ['i_str_01_k' => self::STR_INDEX, 'i_str_02_k' => self::STR2_INDEX]],
            [1 => [
                'i_str_01' => new StringKey('i_str_01_k', self::STR_INDEX, 32, 'utf8mb4_0900_ai_ci'),
                'i_str_02' => new StringKey('i_str_02_k', self::STR2_INDEX, 32, 'utf8mb4_0900_ai_ci'),
            ]],
        ];
    }

    public function testAStringEqualityOnAKeyPageDrivesFromItsKeysIndex(): void
    {
        [$indexes, $keys] = self::keyedStrings();
        $filter = self::leaf(101, 1, 'i_str_01', 'eq', 'paid', 'string');

        $fragment = (new SqlFilterCompiler())->compile($filter, $this->query($filter), $this->snapshot($indexes, [], $keys));

        self::assertSame(
            'SELECT STRAIGHT_JOIN entry_data.id'
            . ' FROM entry_slots_page_1 p0 FORCE INDEX (`' . self::STR_INDEX . '`)'
            . ' INNER JOIN entry_data ON entry_data.id = p0.entry_id AND entry_data.tenant_id = p0.tenant_id'
            . ' WHERE entry_data.tenant_id = ? AND entry_data.model_id = ? AND p0.tenant_id = ?'
            . ' AND entry_data.deleted_at IS NULL'
            . ' AND p0.i_str_01_k = CONCAT(RPAD(LEFT(' . self::WEIGHT . ", 32), 32, UNHEX('00')),"
            . ' LEFT(UNHEX(SHA2(' . self::WEIGHT . ', 256)), 16)) AND p0.i_str_01 = ?'
            . ' ORDER BY p0.entry_id ASC LIMIT ?',
            $fragment->sql,
        );
        // tenant, model, the driving page's tenant, the key lookup (twice), the recheck, the limit.
        self::assertSame([7, 1, 7, 'paid', 'paid', 'paid', 11], $fragment->bindings);
    }

    public function testAnIntegerEqualityStillDrivesWhenAKeyedStringEqualityComesFirst(): void
    {
        [$indexes, $keys] = self::keyedStrings();
        $indexes[2] = ['i_int_01' => 'ix_entry_slots_page_2_i_int_01'];
        $filter = new AndNode([
            self::leaf(101, 1, 'i_str_01', 'eq', 'paid', 'string'),
            self::leaf(102, 2, 'i_int_01', 'eq', 5),
        ]);

        $sql = $this->sql($filter, null, $this->snapshot($indexes, [], $keys));

        self::assertStringContainsString('FROM entry_slots_page_2 p1 FORCE INDEX (`ix_entry_slots_page_2_i_int_01`)', $sql);
        self::assertStringContainsString('ORDER BY p1.entry_id ASC', $sql);
    }

    public function testTheFirstKeyedStringEqualityDrivesWhenNoNumberOrDateQualifies(): void
    {
        [$indexes, $keys] = self::keyedStrings();
        $filter = new AndNode([
            self::leaf(101, 1, 'i_str_02', 'eq', 'a', 'string'),
            self::leaf(102, 1, 'i_str_01', 'eq', 'b', 'string'),
        ]);

        $sql = $this->sql($filter, null, $this->snapshot($indexes, [], $keys));

        self::assertStringContainsString('FORCE INDEX (`' . self::STR2_INDEX . '`)', $sql);
        self::assertStringNotContainsString('FORCE INDEX (`' . self::STR_INDEX . '`)', $sql);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function keyedStringLeavesThatNeverDrive(): iterable
    {
        yield 'prefix' => ['prefix', 'pa'];
        yield 'in list' => ['in', ['a', 'b']];
        yield 'greater than' => ['gt', 'm'];
        yield 'between' => ['between', ['a', 'z']];
        yield 'not equal' => ['neq', 'm'];
        yield 'is null' => ['is_null', null];
    }

    #[DataProvider('keyedStringLeavesThatNeverDrive')]
    public function testOnlyAStringEqualityDrivesEvenWithAKey(string $op, mixed $value): void
    {
        [$indexes, $keys] = self::keyedStrings();
        $filter = self::leaf(101, 1, 'i_str_01', $op, $value, 'string');

        $sql = $this->sql($filter, null, $this->snapshot($indexes, [], $keys));

        self::assertStringStartsWith(self::TODAYS_FROM, $sql);
        self::assertStringNotContainsString('FORCE INDEX', $sql);
    }

    public function testAKeyedStringEqualityInsideOrKeepsTodaysSql(): void
    {
        [$indexes, $keys] = self::keyedStrings();
        $filter = new OrNode([
            self::leaf(101, 1, 'i_str_01', 'eq', 'a', 'string'),
            self::leaf(101, 1, 'i_str_01', 'eq', 'b', 'string'),
        ]);

        $sql = $this->sql($filter, null, $this->snapshot($indexes, [], $keys));

        self::assertStringStartsWith(self::TODAYS_FROM, $sql);
        self::assertStringNotContainsString('_k', $sql);
    }

    public function testAKeyedStringEqualityKeepsTheKeysetOnTheDrivingPage(): void
    {
        [$indexes, $keys] = self::keyedStrings();
        $filter = self::leaf(101, 1, 'i_str_01', 'eq', 'paid', 'string');

        $fragment = (new SqlFilterCompiler())->compile(
            $filter,
            $this->query($filter, null, 42),
            $this->snapshot($indexes, [], $keys),
        );

        self::assertStringContainsString('p0.entry_id > ?', $fragment->sql);
        self::assertStringNotContainsString('entry_data.id >', $fragment->sql);
        // tenant, model, driving tenant, keyset anchor, key lookup twice, recheck, limit.
        self::assertSame([7, 1, 7, 42, 'paid', 'paid', 'paid', 11], $fragment->bindings);
    }

    public function testTenantIsolationHoldsOnTheDrivenQuery(): void
    {
        $filter = new AndNode([
            self::leaf(101, 1, 'i_int_01', 'eq', 5),
            self::leaf(102, 2, 'i_int_01', 'eq', 6),
        ]);
        $snapshot = $this->snapshot([
            1 => ['i_int_01' => self::INT_INDEX],
            2 => ['i_int_01' => 'ix_entry_slots_page_2_i_int_01'],
        ]);

        $sql = $this->sql($filter, null, $snapshot);

        // Every table in the query is pinned to the tenant: the driving page by
        // its own predicate, entry_data by the outer one, and the second page by
        // the join condition.
        self::assertStringContainsString('p0.tenant_id = ?', $sql);
        self::assertStringContainsString('entry_data.tenant_id = ?', $sql);
        self::assertStringContainsString('p1.tenant_id = entry_data.tenant_id', $sql);
    }
}
