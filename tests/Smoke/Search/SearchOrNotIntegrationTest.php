<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Search;

use StarDust\Filter\Ast\AndNode;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Filter\Ast\NotNode;
use StarDust\Filter\Ast\OrNode;
use StarDust\Search\SearchRequest;
use StarDust\Tests\Smoke\Phase8TestCase;

/**
 * End-to-end Phase 8 acceptance: AND/OR/NOT composition through the
 * MySQL driver against a real database. The compiler picks the
 * EXISTS strategy for any tree carrying an OR or NOT; this test
 * verifies the produced SQL returns the correct row set.
 */
final class SearchOrNotIntegrationTest extends Phase8TestCase
{
    public function testOrReturnsUnionOfBranches(): void
    {
        [$modelId, , , $fieldName] = $this->setupFilterableStringField();
        $this->seedEntry(1, $modelId, [$fieldName => 'alpha']);
        $this->seedEntry(1, $modelId, [$fieldName => 'beta']);
        $this->seedEntry(1, $modelId, [$fieldName => 'gamma']);

        $filter = new OrNode([
            LeafNode::local($fieldName, 'eq', 'alpha'),
            LeafNode::local($fieldName, 'eq', 'gamma'),
        ]);

        $result = $this->makeSearchService()->execute(new SearchRequest(
            tenantId: 1,
            modelId:  $modelId,
            filter:   $filter,
            pageSize: 10,
        ));

        $values = array_map(static fn ($e): mixed => $e->fields[$fieldName], $result->rows);
        sort($values);
        self::assertSame(['alpha', 'gamma'], $values);
    }

    public function testNotExcludesMatchingRows(): void
    {
        [$modelId, , , $fieldName] = $this->setupFilterableStringField();
        $this->seedEntry(1, $modelId, [$fieldName => 'keep']);
        $this->seedEntry(1, $modelId, [$fieldName => 'drop']);
        $this->seedEntry(1, $modelId, [$fieldName => 'keep']);

        $filter = new NotNode(LeafNode::local($fieldName, 'eq', 'drop'));

        $result = $this->makeSearchService()->execute(new SearchRequest(
            tenantId: 1,
            modelId:  $modelId,
            filter:   $filter,
            pageSize: 10,
        ));

        self::assertCount(2, $result->rows);
        foreach ($result->rows as $entry) {
            self::assertSame('keep', $entry->fields[$fieldName]);
        }
    }

    public function testNestedAndOrComposesCorrectly(): void
    {
        [$modelId, , , $fieldName] = $this->setupFilterableStringField();
        $this->seedEntry(1, $modelId, [$fieldName => 'A']);
        $this->seedEntry(1, $modelId, [$fieldName => 'B']);
        $this->seedEntry(1, $modelId, [$fieldName => 'C']);
        $this->seedEntry(1, $modelId, [$fieldName => 'D']);

        // (name = 'A' OR name = 'B') AND NOT (name = 'B')  →  only 'A'.
        $filter = new AndNode([
            new OrNode([
                LeafNode::local($fieldName, 'eq', 'A'),
                LeafNode::local($fieldName, 'eq', 'B'),
            ]),
            new NotNode(LeafNode::local($fieldName, 'eq', 'B')),
        ]);

        $result = $this->makeSearchService()->execute(new SearchRequest(
            tenantId: 1,
            modelId:  $modelId,
            filter:   $filter,
            pageSize: 10,
        ));

        self::assertCount(1, $result->rows);
        self::assertSame('A', $result->rows[0]->fields[$fieldName]);
    }
}
