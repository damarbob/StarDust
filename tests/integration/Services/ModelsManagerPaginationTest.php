<?php

namespace StarDust\Tests\Integration\Services;

use StarDust\Tests\Integration\StarDustTestCase;
use StarDust\Services\ModelsManager;
use StarDust\Data\ModelSearchCriteria;

/**
 * Test suite for ModelsManager pagination
 */
class ModelsManagerPaginationTest extends StarDustTestCase
{
    private ModelsManager $modelsManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modelsManager = service('modelsManager');
        if (!$this->modelsManager) {
            $this->modelsManager = ModelsManager::getInstance();
        }
    }

    public function testPaginateReturnsAllModelsWhenNoCriteria(): void
    {
        $this->createModels(5);

        $result = $this->modelsManager->paginate(1, 10);

        $this->assertCount(5, $result);
    }

    public function testPaginateRespectsLimit(): void
    {
        $this->createModels(15);

        $result = $this->modelsManager->paginate(1, 10);

        $this->assertCount(10, $result);

        $resultPage2 = $this->modelsManager->paginate(2, 10);
        $this->assertCount(5, $resultPage2);
    }

    public function testPaginateFiltersBySearchTerm(): void
    {
        $this->modelsManager->create([
            'name' => 'Apple Product',
            'fields' => json_encode([])
        ], $this->testUserId);

        $this->modelsManager->create([
            'name' => 'Banana Product',
            'fields' => json_encode([])
        ], $this->testUserId);

        $this->modelsManager->create([
            'name' => 'Cherry Product',
            'fields' => json_encode([])
        ], $this->testUserId);

        $criteria = new ModelSearchCriteria(searchQuery: 'app');
        $result = $this->modelsManager->paginate(1, 10, $criteria);

        $this->assertCount(1, $result);
        $this->assertEquals('Apple Product', $result[0]['name']);

        // Test case insensitive or slug if applicable (assuming like behavior)
        $criteria = new ModelSearchCriteria(searchQuery: 'product');
        $result = $this->modelsManager->paginate(1, 10, $criteria);
        $this->assertCount(3, $result);
    }

    public function testPaginateFiltersByDateRange(): void
    {
        $id1 = $this->modelsManager->create([
            'name' => 'Old Model',
            'fields' => json_encode([])
        ], $this->testUserId);

        sleep(1);
        $cutoff = date('Y-m-d H:i:s');
        sleep(1);

        $id2 = $this->modelsManager->create([
            'name' => 'New Model',
            'fields' => json_encode([])
        ], $this->testUserId);

        // Test Created After
        $criteria = new ModelSearchCriteria(createdAfter: $cutoff);
        $result = $this->modelsManager->paginate(1, 10, $criteria);

        $this->assertCount(1, $result);
        $this->assertEquals($id2, $result[0]['id']);

        // Test Created Before
        $criteria = new ModelSearchCriteria(createdBefore: $cutoff);
        $result = $this->modelsManager->paginate(1, 10, $criteria);

        $this->assertCount(1, $result);
        $this->assertEquals($id1, $result[0]['id']);
    }

    public function testPaginateFiltersByIds(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->modelsManager->create([
                'name' => "Model $i",
                'fields' => json_encode([])
            ], $this->testUserId);
        }

        $targetIds = [$ids[1], $ids[3]];
        $criteria = new ModelSearchCriteria(ids: $targetIds);

        $result = $this->modelsManager->paginate(1, 10, $criteria);

        $this->assertCount(2, $result);

        // Handle potential string/int mismatch from DB driver
        $foundIds = array_map('intval', array_column($result, 'id'));
        $this->assertContains($ids[1], $foundIds);
        $this->assertContains($ids[3], $foundIds);
    }

    // Note: ModelsManager::paginate doesn't seem to have an explicit 'includeDeleted' flag 
    // exposed in the method arguments or DTO usage in the current code view i saw earlier.
    // It relies on $this->query() which usually returns active models.
    // Let's verify if `paginate` handles deleted items or if that's a separate concern.
    // The previous analysis showed paginate() uses $this->query() active builder unconditionally.
    // So distinct from EntriesManager, ModelsManager might filter strictly active.

    private function createModels(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->modelsManager->create([
                'name' => "Model $i",
                'fields' => json_encode([])
            ], $this->testUserId);
        }
    }
}
