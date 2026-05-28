<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Search;

use PHPUnit\Framework\TestCase;
use StarDust\Filter\Ast\AndNode;
use StarDust\Filter\Ast\FieldRef;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Filter\Ast\NotNode;
use StarDust\Filter\Ast\OrNode;
use StarDust\Filter\Ast\TypedValue;
use StarDust\Read\EntryQuery;
use StarDust\Read\FieldDescriptor;
use StarDust\Read\SnapshotEntry;
use StarDust\Search\Mysql\SqlFilterCompiler;

/**
 * Pure-unit tests for {@see SqlFilterCompiler}. Builds resolved leaves
 * by constructing {@see FieldDescriptor} + {@see SnapshotEntry} in
 * memory so no MySQL is required. Asserts both the strategy decision
 * (`joins` vs `exists`) and the resulting SQL shape.
 */
final class SqlFilterCompilerTest extends TestCase
{
    private function descriptor(int $fieldId, int $pageId, string $slotColumn, string $declaredType = 'string'): FieldDescriptor
    {
        return new FieldDescriptor(
            fieldId:      $fieldId,
            fieldName:    "field_{$fieldId}",
            declaredType: $declaredType,
            isFilterable: true,
            slotColumn:   $slotColumn,
            slotStatus:   'ready',
            pageId:       $pageId,
        );
    }

    private function leaf(int $fieldId, int $pageId, string $slotColumn, string $op, mixed $value): LeafNode
    {
        $descriptor = $this->descriptor($fieldId, $pageId, $slotColumn);
        $field = new FieldRef(
            modelName:  'demo',
            fieldName:  $descriptor->fieldName,
            modelId:    1,
            fieldId:    $fieldId,
            descriptor: $descriptor,
        );
        return new LeafNode($op, $field, $value === null ? null : new TypedValue($value));
    }

    private function snapshot(array $pageTableNames = [1 => 'entry_slots_page_1', 2 => 'entry_slots_page_2']): SnapshotEntry
    {
        return new SnapshotEntry(
            modelId:           1,
            capturedAtVersion: 1,
            capturedAtUnixTs:  0,
            fieldsByName:      [],
            pageTableNames:    $pageTableNames,
        );
    }

    private function query(?\StarDust\Filter\Ast\FilterNode $filter): EntryQuery
    {
        return new EntryQuery(
            tenantId: 7,
            modelId:  1,
            filter:   $filter,
            pageSize: 10,
        );
    }

    public function testNullFilterUsesJoinStrategyAndEmitsNoJoins(): void
    {
        $compiler = new SqlFilterCompiler();
        $fragment = $compiler->compile(null, $this->query(null), $this->snapshot());
        self::assertSame('joins', $compiler->chooseStrategy(null));
        self::assertStringNotContainsString('INNER JOIN', $fragment->sql);
        self::assertStringNotContainsString('EXISTS', $fragment->sql);
        // Outer shape: tenant_id, model_id, deleted_at, id > cursor, ORDER BY id ASC, LIMIT.
        self::assertStringContainsString('entry_data.tenant_id = ?', $fragment->sql);
        self::assertStringContainsString('entry_data.id > ?', $fragment->sql);
        self::assertStringContainsString('ORDER BY entry_data.id ASC', $fragment->sql);
        self::assertSame([7, 1, 0, 11], $fragment->bindings);
    }

    public function testPureAndUsesJoinStrategyWithOneJoinPerDistinctPage(): void
    {
        $compiler = new SqlFilterCompiler();
        $leaves = [
            $this->leaf(101, 1, 'i_str_01', 'eq', 'paid'),
            $this->leaf(102, 1, 'i_str_02', 'eq', 'open'),    // same page as above
            $this->leaf(103, 2, 'i_int_01', 'gt', 100),       // different page
        ];
        $filter = new AndNode($leaves);
        $fragment = $compiler->compile($filter, $this->query($filter), $this->snapshot());

        self::assertSame('joins', $compiler->chooseStrategy($filter));
        // Two distinct pages → exactly two INNER JOINs, aliased p0/p1.
        self::assertSame(
            2,
            substr_count($fragment->sql, 'INNER JOIN'),
            "expected one INNER JOIN per distinct page, got SQL: {$fragment->sql}"
        );
        self::assertStringContainsString('entry_slots_page_1 p0', $fragment->sql);
        self::assertStringContainsString('entry_slots_page_2 p1', $fragment->sql);
        self::assertStringContainsString('p0.i_str_01 = ?', $fragment->sql);
        self::assertStringContainsString('p0.i_str_02 = ?', $fragment->sql);
        self::assertStringContainsString('p1.i_int_01 > ?', $fragment->sql);
        self::assertStringNotContainsString('EXISTS', $fragment->sql);
        // Bindings layout: tenant=7, model=1, cursor=0, then 3 leaf
        // values in declaration order, then page_size+1=11.
        self::assertSame([7, 1, 0, 'paid', 'open', 100, 11], $fragment->bindings);
    }

    public function testOrTriggersExistsStrategy(): void
    {
        $compiler = new SqlFilterCompiler();
        $filter = new OrNode([
            $this->leaf(101, 1, 'i_str_01', 'eq', 'a'),
            $this->leaf(101, 1, 'i_str_01', 'eq', 'b'),
        ]);
        $fragment = $compiler->compile($filter, $this->query($filter), $this->snapshot());

        self::assertSame('exists', $compiler->chooseStrategy($filter));
        self::assertStringNotContainsString('INNER JOIN', $fragment->sql);
        self::assertSame(2, substr_count($fragment->sql, 'EXISTS'));
        self::assertStringContainsString(' OR ', $fragment->sql);
        // Tenant isolation invariant: every EXISTS must carry
        // `s.tenant_id = entry_data.tenant_id`. Match the equality
        // predicate directly rather than counting occurrences so the
        // assertion is robust against incidental `tenant_id` mentions
        // elsewhere in the SQL string.
        self::assertSame(
            2,
            substr_count($fragment->sql, 's.tenant_id = entry_data.tenant_id'),
            'each EXISTS subquery must replay the tenant_id predicate'
        );
    }

    public function testNotEmitsNotExistsWrapper(): void
    {
        $compiler = new SqlFilterCompiler();
        $filter = new NotNode($this->leaf(101, 1, 'i_str_01', 'eq', 'cancelled'));
        $fragment = $compiler->compile($filter, $this->query($filter), $this->snapshot());

        self::assertSame('exists', $compiler->chooseStrategy($filter));
        self::assertStringContainsString('NOT (', $fragment->sql);
        self::assertStringContainsString('EXISTS', $fragment->sql);
    }

    public function testIsNullEmitsIsNullPredicate(): void
    {
        $compiler = new SqlFilterCompiler();
        $filter = $this->leaf(101, 1, 'i_str_01', 'is_null', null);
        $fragment = $compiler->compile($filter, $this->query($filter), $this->snapshot());

        self::assertSame('joins', $compiler->chooseStrategy($filter));
        self::assertStringContainsString('p0.i_str_01 IS NULL', $fragment->sql);
    }

    public function testInListEmitsParameterizedPlaceholders(): void
    {
        $compiler = new SqlFilterCompiler();
        $filter = $this->leaf(101, 1, 'i_str_01', 'in', ['a', 'b', 'c']);
        $fragment = $compiler->compile($filter, $this->query($filter), $this->snapshot());

        self::assertStringContainsString('p0.i_str_01 IN (?,?,?)', $fragment->sql);
        // tenant, model, cursor, three IN values, page_size+1
        self::assertSame([7, 1, 0, 'a', 'b', 'c', 11], $fragment->bindings);
    }

    public function testPrefixEscapesLikeMetacharacters(): void
    {
        $compiler = new SqlFilterCompiler();
        $filter = $this->leaf(101, 1, 'i_str_01', 'prefix', '50%_off\\');
        $fragment = $compiler->compile($filter, $this->query($filter), $this->snapshot());

        self::assertStringContainsString('LIKE ? ESCAPE', $fragment->sql);
        // % → \%, _ → \_, \ → \\, then append '%' for prefix match.
        self::assertSame('50\\%\\_off\\\\%', $fragment->bindings[3]);
    }
}
