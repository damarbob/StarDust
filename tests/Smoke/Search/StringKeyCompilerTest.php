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
use StarDust\Read\EntryQuery;
use StarDust\Read\FieldDescriptor;
use StarDust\Read\SnapshotEntry;
use StarDust\Read\StringKey;
use StarDust\Search\Mysql\SqlFilterCompiler;

/**
 * SQL-shape tests for the string key rewrite in {@see SqlFilterCompiler}
 * (ADR 0057).
 *
 * Pure-unit, same posture as {@see SqlFilterCompilerTest}: the snapshot and the
 * descriptors are built in memory, so this pins the emitted SQL and the binding
 * order without a database. What the rewritten queries return against real data,
 * including the values that make a slightly wrong lookup go wrong, is
 * `StringKeyMatchingTest`'s subject.
 *
 * The expected statements are written out by hand, as `StringKeyLayoutTest`
 * does, so a change to the layout shows up here as a failing line.
 */
final class StringKeyCompilerTest extends TestCase
{
    private const INDEX = 'ix_entry_slots_page_1_i_str_01';
    private const COLLATION = 'utf8mb4_0900_ai_ci';

    /** The weight string of a bound value, cast to the page collation. */
    private const WEIGHT = 'WEIGHT_STRING(CONVERT(? USING utf8mb4) COLLATE utf8mb4_0900_ai_ci)';

    private static function leaf(string $op, mixed $value, string $slotColumn = 'i_str_01', int $pageId = 1, string $declaredType = 'string'): LeafNode
    {
        $descriptor = new FieldDescriptor(
            fieldId:      101,
            fieldName:    'label',
            declaredType: $declaredType,
            isFilterable: true,
            slotColumn:   $slotColumn,
            slotStatus:   'ready',
            pageId:       $pageId,
        );

        return new LeafNode(
            $op,
            new FieldRef(modelName: 'demo', fieldName: 'label', modelId: 1, fieldId: 101, descriptor: $descriptor),
            $value === null ? null : new TypedValue($value),
        );
    }

    private function snapshot(bool $withKey = true, int $head = 32): SnapshotEntry
    {
        return new SnapshotEntry(
            modelId:           1,
            capturedAtVersion: 1,
            capturedAtUnixTs:  0,
            fieldsByName:      [],
            pageTableNames:    [1 => 'entry_slots_page_1', 2 => 'entry_slots_page_2'],
            orderedIndexes:    $withKey ? [1 => ['i_str_01_k' => self::INDEX]] : [],
            stringKeys:        $withKey
                ? [1 => ['i_str_01' => new StringKey('i_str_01_k', self::INDEX, $head, self::COLLATION)]]
                : [],
        );
    }

    /** @return array{sql: string, bindings: list<mixed>} */
    private function compile(FilterNode $filter, ?SnapshotEntry $snapshot = null): array
    {
        $fragment = (new SqlFilterCompiler())->compile(
            $filter,
            new EntryQuery(tenantId: 7, modelId: 1, filter: $filter, pageSize: 10),
            $snapshot ?? $this->snapshot(),
        );

        return ['sql' => $fragment->sql, 'bindings' => $fragment->bindings];
    }

    /**
     * The filter's own bindings: everything after the tenant and model ahead of
     * it and before the page size that closes the statement.
     *
     * @param  list<mixed> $bindings
     * @return list<mixed>
     */
    private static function filterBindings(array $bindings, int $count): array
    {
        array_pop($bindings);

        return array_slice($bindings, -$count);
    }

    public function testEqualityAddsTheKeyLookupAndKeepsTheOriginalAsTheRecheck(): void
    {
        $out = $this->compile(self::leaf('eq', 'PAID'));

        self::assertStringContainsString(
            ' AND p0.i_str_01_k = CONCAT(RPAD(LEFT(' . self::WEIGHT . ", 32), 32, UNHEX('00')),"
            . ' LEFT(UNHEX(SHA2(' . self::WEIGHT . ', 256)), 16)) AND p0.i_str_01 = ?',
            $out['sql'],
        );
        self::assertSame(['PAID', 'PAID', 'PAID'], self::filterBindings($out['bindings'], 3));
    }

    public function testInSearchesEachElementsKeyAndRechecksTheList(): void
    {
        $out = $this->compile(self::leaf('in', ['a', 'b']));

        $key = 'CONCAT(RPAD(LEFT(' . self::WEIGHT . ", 32), 32, UNHEX('00')),"
            . ' LEFT(UNHEX(SHA2(' . self::WEIGHT . ', 256)), 16))';
        self::assertStringContainsString(" AND p0.i_str_01_k IN ({$key}, {$key}) AND p0.i_str_01 IN (?,?)", $out['sql']);
        self::assertSame(['a', 'a', 'b', 'b', 'a', 'b'], self::filterBindings($out['bindings'], 6));
    }

    public function testPrefixBindsTheRawPrefixForTheKeyAndTheEscapedPatternForTheRecheck(): void
    {
        $out = $this->compile(self::leaf('prefix', '50%_off'));

        $head = 'LEFT(' . self::WEIGHT . ', 32)';
        self::assertStringContainsString(
            " AND p0.i_str_01_k BETWEEN {$head} AND CONCAT({$head}, REPEAT(UNHEX('FF'), 48))"
            . " AND p0.i_str_01 LIKE ? ESCAPE '\\\\'",
            $out['sql'],
        );
        self::assertSame(
            ['50%_off', '50%_off', '50\\%\\_off%'],
            self::filterBindings($out['bindings'], 3),
            'The key gets the prefix as written, and only the recheck gets the escaped pattern.',
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function lowerBounds(): iterable
    {
        yield 'gt' => ['gt', '>'];
        yield 'gte' => ['gte', '>='];
    }

    #[DataProvider('lowerBounds')]
    public function testALowerBoundBoundsTheKeyFromBelowAndRechecksTheStrictness(string $op, string $sqlOp): void
    {
        $out = $this->compile(self::leaf($op, 'm'));

        self::assertStringContainsString(
            ' AND p0.i_str_01_k >= RPAD(LEFT(' . self::WEIGHT . ", 32), 32, UNHEX('00')) AND p0.i_str_01 {$sqlOp} ?",
            $out['sql'],
            'Both forms use the inclusive key bound, because the key cannot tell the equal value from the greater ones.',
        );
        self::assertSame(['m', 'm'], self::filterBindings($out['bindings'], 2));
    }

    /** @return iterable<string, array{string, string}> */
    public static function upperBounds(): iterable
    {
        yield 'lt' => ['lt', '<'];
        yield 'lte' => ['lte', '<='];
    }

    #[DataProvider('upperBounds')]
    public function testAnUpperBoundBoundsTheKeyFromAboveAcrossTheDigest(string $op, string $sqlOp): void
    {
        $out = $this->compile(self::leaf($op, 'm'));

        self::assertStringContainsString(
            ' AND p0.i_str_01_k <= CONCAT(RPAD(LEFT(' . self::WEIGHT . ", 32), 32, UNHEX('00')), REPEAT(UNHEX('FF'), 16))"
            . " AND p0.i_str_01 {$sqlOp} ?",
            $out['sql'],
        );
        self::assertSame(['m', 'm'], self::filterBindings($out['bindings'], 2));
    }

    public function testBetweenBoundsBothEndsInOrder(): void
    {
        $out = $this->compile(self::leaf('between', ['low', 'high']));

        self::assertStringContainsString(
            ' AND p0.i_str_01_k >= RPAD(LEFT(' . self::WEIGHT . ", 32), 32, UNHEX('00'))"
            . ' AND p0.i_str_01_k <= CONCAT(RPAD(LEFT(' . self::WEIGHT . ", 32), 32, UNHEX('00')), REPEAT(UNHEX('FF'), 16))"
            . ' AND p0.i_str_01 BETWEEN ? AND ?',
            $out['sql'],
        );
        self::assertSame(['low', 'high', 'low', 'high'], self::filterBindings($out['bindings'], 4));
    }

    public function testNullTestsAskTheKeyColumnAndTheSlot(): void
    {
        self::assertStringContainsString(
            ' AND p0.i_str_01_k IS NULL AND p0.i_str_01 IS NULL',
            $this->compile(self::leaf('is_null', null))['sql'],
        );
        self::assertStringContainsString(
            ' AND p0.i_str_01_k IS NOT NULL AND p0.i_str_01 IS NOT NULL',
            $this->compile(self::leaf('is_not_null', null))['sql'],
        );
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function operatorsNoKeyCanServe(): iterable
    {
        yield 'neq' => ['neq', 'x'];
        yield 'nin' => ['nin', ['x', 'y']];
    }

    #[DataProvider('operatorsNoKeyCanServe')]
    public function testNotEqualAndNotInKeepTheSqlTheyHadBeforeKeys(string $op, mixed $value): void
    {
        $filter = self::leaf($op, $value);

        self::assertSame(
            $this->compile($filter, $this->snapshot(withKey: false)),
            $this->compile($filter),
        );
    }

    public function testASlotWithoutAKeyCompilesToTheSqlItHadBeforeKeys(): void
    {
        $filters = [
            self::leaf('eq', 'x'),
            self::leaf('in', ['x', 'y']),
            self::leaf('prefix', 'x'),
            self::leaf('gte', 'x'),
            self::leaf('lt', 'x'),
            self::leaf('between', ['x', 'y']),
            self::leaf('is_null', null),
        ];

        foreach ($filters as $filter) {
            $withoutKey = $this->compile($filter, $this->snapshot(withKey: false));

            self::assertStringNotContainsString('_k', $withoutKey['sql'], $filter->operator);
            self::assertStringNotContainsString('WEIGHT_STRING', $withoutKey['sql'], $filter->operator);
        }
    }

    public function testTheExistsStrategyKeepsTheSqlAStringLeafHadBeforeKeys(): void
    {
        $orFilter = new OrNode([self::leaf('eq', 'a'), self::leaf('eq', 'b')]);
        $notFilter = new NotNode(self::leaf('prefix', 'a'));

        foreach ([$orFilter, $notFilter] as $filter) {
            self::assertSame(
                $this->compile($filter, $this->snapshot(withKey: false)),
                $this->compile($filter),
                'A page reached by primary key per entry gains nothing from a key.',
            );
        }
    }

    public function testTheHeadComesFromTheKeyAndNeverFromAConstant(): void
    {
        $out = $this->compile(self::leaf('prefix', 'x'), $this->snapshot(head: 64));

        self::assertStringContainsString('LEFT(' . self::WEIGHT . ', 64)', $out['sql']);
        self::assertStringContainsString("REPEAT(UNHEX('FF'), 80)", $out['sql']);
        self::assertStringNotContainsString(', 32)', $out['sql']);
    }

    public function testEveryPlaceholderTakesOneBindingAcrossTwoLeaves(): void
    {
        $filter = new AndNode([
            self::leaf('gte', 'a'),
            self::leaf('between', ['b', 'c']),
        ]);

        $out = $this->compile($filter);

        self::assertSame(substr_count($out['sql'], '?'), count($out['bindings']), $out['sql']);
        // Tenant and model first, then each leaf's key lookup before its recheck, in written order.
        self::assertSame(
            [7, 1, 'a', 'a', 'b', 'c', 'b', 'c', 11],
            $out['bindings'],
        );
    }

    public function testTenantIsolationHoldsOnARewrittenQuery(): void
    {
        $out = $this->compile(self::leaf('gte', 'x'));

        self::assertStringContainsString('entry_data.tenant_id = ?', $out['sql']);
        self::assertStringContainsString('p0.tenant_id = entry_data.tenant_id', $out['sql']);
        self::assertSame(7, $out['bindings'][0]);
    }

    public function testTenantIsolationHoldsOnADrivenQueryToo(): void
    {
        // An equality on a key page drives from its own page, which then carries its own tenant predicate.
        $out = $this->compile(self::leaf('eq', 'x'));

        self::assertStringContainsString('entry_data.tenant_id = ?', $out['sql']);
        self::assertStringContainsString('p0.tenant_id = ?', $out['sql']);
        self::assertStringContainsString('entry_data.tenant_id = p0.tenant_id', $out['sql']);
        self::assertSame([7, 1, 7], array_slice($out['bindings'], 0, 3));
    }
}
