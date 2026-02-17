<?php

namespace StarDust\Tests\Unit\Data;

use PHPUnit\Framework\TestCase;
use StarDust\Data\VirtualColumnFilter;
use StarDust\Data\EntrySearchCriteria;

/**
 * Unit tests for VirtualColumnFilter DTO and EntrySearchCriteria integration.
 */
class VirtualColumnFilterTest extends TestCase
{
    // ---------------------------------------------------------------
    // VirtualColumnFilter
    // ---------------------------------------------------------------

    public function testDefaultOperatorIsEquals(): void
    {
        $filter = new VirtualColumnFilter('price', 100);

        $this->assertSame('=', $filter->operator);
    }

    /**
     * @dataProvider allowedOperatorsProvider
     */
    public function testAllAllowedOperatorsConstruct(string $operator): void
    {
        $filter = new VirtualColumnFilter('price', 100, $operator);

        $this->assertSame($operator, $filter->operator);
    }

    public static function allowedOperatorsProvider(): array
    {
        return [
            'equals'                => ['='],
            'not equals'            => ['!='],
            'greater than'          => ['>'],
            'greater than or equal' => ['>='],
            'less than'             => ['<'],
            'less than or equal'    => ['<='],
            'like'                  => ['LIKE'],
        ];
    }

    public function testRejectsInvalidOperator(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new VirtualColumnFilter('price', 100, 'DROP TABLE');
    }

    public function testRejectsEmptyOperator(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new VirtualColumnFilter('price', 100, '');
    }

    public function testFieldAndValueAreStored(): void
    {
        $filter = new VirtualColumnFilter('price_01_num', 250);

        $this->assertSame('price_01_num', $filter->field);
        $this->assertSame(250, $filter->value);
    }

    // ---------------------------------------------------------------
    // EntrySearchCriteria integration
    // ---------------------------------------------------------------

    public function testAddCustomFilterDefaultOperator(): void
    {
        $criteria = new EntrySearchCriteria();
        $criteria->addCustomFilter('price', 100);

        $this->assertCount(1, $criteria->customFilters);
        $this->assertInstanceOf(VirtualColumnFilter::class, $criteria->customFilters[0]);
        $this->assertSame('=', $criteria->customFilters[0]->operator);
        $this->assertSame('price', $criteria->customFilters[0]->field);
        $this->assertSame(100, $criteria->customFilters[0]->value);
    }

    public function testAddCustomFilterWithOperator(): void
    {
        $criteria = new EntrySearchCriteria();
        $criteria->addCustomFilter('price', 100, '>');

        $this->assertCount(1, $criteria->customFilters);
        $this->assertSame('>', $criteria->customFilters[0]->operator);
    }

    public function testAddMultipleCustomFilters(): void
    {
        $criteria = new EntrySearchCriteria();
        $criteria->addCustomFilter('price', 100, '>');
        $criteria->addCustomFilter('price', 500, '<=');

        $this->assertCount(2, $criteria->customFilters);
        $this->assertSame('>', $criteria->customFilters[0]->operator);
        $this->assertSame('<=', $criteria->customFilters[1]->operator);
    }

    public function testHasCustomFiltersReturnsTrueWhenPopulated(): void
    {
        $criteria = new EntrySearchCriteria();
        $this->assertFalse($criteria->hasCustomFilters());

        $criteria->addCustomFilter('price', 100);
        $this->assertTrue($criteria->hasCustomFilters());
    }
}
