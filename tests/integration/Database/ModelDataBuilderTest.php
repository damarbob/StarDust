<?php

namespace StarDust\Tests\Integration\Database;

use StarDust\Tests\Integration\StarDustTestCase;
use StarDust\Models\ModelDataModel;
use StarDust\Services\ModelsManager;

/**
 * Test suite for ModelDataBuilder class
 *
 * Tests query building methods including:
 * - Join methods (single and chained)
 * - Select methods
 * - Where conditions (active)
 * - Default method combinations
 * - Order by methods
 *
 * @internal
 */
class ModelDataBuilderTest extends StarDustTestCase
{
    private ModelDataModel $modelDataModel;
    private ModelsManager $modelsManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modelDataModel = new ModelDataModel();
        $this->modelsManager = ModelsManager::getInstance();
    }

    // ========================================
    // DEFAULT Method Tests
    // ========================================

    public function testDefault(): void
    {
        $builder = $this->modelDataModel->builder()->default();
        $query = $builder->getCompiledSelect();

        // Should include all selects and joins
        $this->assertStringContainsStringIgnoringCase('`model_data`.`name`', $query);
        $this->assertStringContainsStringIgnoringCase('models', $query);
        $this->assertStringContainsStringIgnoringCase('users', $query);
    }

    // ========================================
    // SELECT Methods Tests
    // ========================================

    public function testSelectModelData(): void
    {
        $builder = $this->modelDataModel->builder()->selectModelData();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('`model_data`.`id` as `data_id`', $query);
        $this->assertStringContainsStringIgnoringCase('`model_data`.`name`', $query);
        $this->assertStringContainsStringIgnoringCase('`model_data`.`fields`', $query);
        $this->assertStringContainsStringIgnoringCase('`model_data`.`icon`', $query);
        $this->assertStringContainsStringIgnoringCase('`created_at` AS `date_created`', $query);
    }

    public function testSelectModels(): void
    {
        $builder = $this->modelDataModel->builder()->selectModels();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('`models`.`id`', $query);
    }

    public function testSelectUsers(): void
    {
        $builder = $this->modelDataModel->builder()->selectUsers();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('`users`.`username` AS `created_by`', $query);
    }

    public function testSelectDefault(): void
    {
        $builder = $this->modelDataModel->builder()->selectDefault();
        $query = $builder->getCompiledSelect();

        // Should include all select methods
        $this->assertStringContainsStringIgnoringCase('`model_data`.`name`', $query);
        $this->assertStringContainsStringIgnoringCase('`models`.`id`', $query);
        $this->assertStringContainsStringIgnoringCase('`users`.`username` AS `created_by`', $query);
    }

    // ========================================
    // JOIN Methods Tests
    // ========================================

    public function testJoinModels(): void
    {
        $builder = $this->modelDataModel->builder()->joinModels();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('LEFT JOIN', $query);
        $this->assertStringContainsStringIgnoringCase('models', $query);
        $this->assertStringContainsStringIgnoringCase('model_id', $query);
    }

    public function testJoinCreator(): void
    {
        $builder = $this->modelDataModel->builder()->joinCreator();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('LEFT JOIN', $query);
        $this->assertStringContainsStringIgnoringCase('users', $query);
        $this->assertStringContainsStringIgnoringCase('creator_id', $query);
    }

    public function testJoinDefault(): void
    {
        $builder = $this->modelDataModel->builder()->joinDefault();
        $query = $builder->getCompiledSelect();

        // Should include all join methods
        $this->assertStringContainsStringIgnoringCase('models', $query);
        $this->assertStringContainsStringIgnoringCase('users', $query);
    }

    // ========================================
    // WHERE Methods Tests
    // ========================================

    public function testWhereActive(): void
    {
        $builder = $this->modelDataModel->builder()->whereActive();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('WHERE', $query);
        $this->assertStringContainsStringIgnoringCase('deleted_at', $query);
        $this->assertStringContainsStringIgnoringCase('IS NULL', $query);
    }

    // ========================================
    // ORDER BY Methods Tests
    // ========================================

    public function testOrderByDefault(): void
    {
        $builder = $this->modelDataModel->builder()->orderByDefault();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('ORDER BY', $query);
        $this->assertStringContainsStringIgnoringCase('data_id', $query);
        $this->assertStringContainsStringIgnoringCase('DESC', $query);
    }

    // ========================================
    // Method Chaining Tests
    // ========================================

    public function testMethodChainingReturnsBuilder(): void
    {
        $builder = $this->modelDataModel->builder();

        $result = $builder->selectModelData();
        $this->assertSame($builder, $result);

        $result = $builder->joinModels();
        $this->assertSame($builder, $result);

        $result = $builder->whereActive();
        $this->assertSame($builder, $result);

        $result = $builder->orderByDefault();
        $this->assertSame($builder, $result);
    }

    public function testComplexMethodChaining(): void
    {
        $builder = $this->modelDataModel->builder()
            ->selectModelData()
            ->selectModels()
            ->joinModels()
            ->joinCreator()
            ->whereActive()
            ->orderByDefault();

        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('`model_data`.`name`', $query);
        $this->assertStringContainsStringIgnoringCase('`models`.`id`', $query);
        $this->assertStringContainsStringIgnoringCase('models', $query);
        $this->assertStringContainsStringIgnoringCase('users', $query);
        $this->assertStringContainsStringIgnoringCase('deleted_at', $query);
        $this->assertStringContainsStringIgnoringCase('ORDER BY', $query);
    }

    // ========================================
    // Integration Tests with Real Data
    // ========================================

    public function testDefaultMethodWithActiveModelData(): void
    {
        // Create test models
        $this->modelsManager->create([
            'name' => 'Test Model 1',
            'fields' => json_encode([
                ['id' => 'field1', 'type' => 'text', 'label' => 'Field 1']
            ])
        ], $this->testUserId);

        $this->modelsManager->create([
            'name' => 'Test Model 2',
            'fields' => json_encode([
                ['id' => 'field2', 'type' => 'text', 'label' => 'Field 2']
            ])
        ], $this->testUserId);

        $results = $this->modelDataModel->builder()
            ->default()
            ->whereActive()
            ->get()
            ->getResultArray();

        $this->assertCount(2, $results);
        $this->assertArrayHasKey('data_id', $results[0]);
        $this->assertArrayHasKey('name', $results[0]);
        $this->assertArrayHasKey('fields', $results[0]);
        $this->assertArrayHasKey('created_by', $results[0]);
    }

    public function testOrderByDefaultWithRealData(): void
    {
        // Create test models
        $id1 = $this->modelsManager->create([
            'name' => 'First Model',
            'fields' => json_encode([])
        ], $this->testUserId);

        $id2 = $this->modelsManager->create([
            'name' => 'Second Model',
            'fields' => json_encode([])
        ], $this->testUserId);

        $results = $this->modelDataModel->builder()
            ->selectModelData()
            ->whereActive()
            ->orderByDefault()
            ->get()
            ->getResultArray();

        // Should be ordered by data_id DESC, so second model should be first
        $this->assertEquals('Second Model', $results[0]['name']);
        $this->assertEquals('First Model', $results[1]['name']);
    }

    // ========================================
    // Edge Cases and Error Handling
    // ========================================

    public function testMultipleWhereConditions(): void
    {
        $builder = $this->modelDataModel->builder()
            ->whereActive()
            ->where('model_data.id >', 0);

        $query = $builder->getCompiledSelect();
        $this->assertStringContainsStringIgnoringCase('WHERE', $query);
        $this->assertStringContainsStringIgnoringCase('deleted_at', $query);
        $this->assertStringContainsStringIgnoringCase('`model_data`.`id`', $query);
    }

    public function testBuilderCanBeReused(): void
    {
        $builder = $this->modelDataModel->builder();

        // First query
        $query1 = $builder->selectModelData()->getCompiledSelect(false);

        // Second query with additional conditions
        $query2 = $builder->where('model_data.id', 1)->getCompiledSelect();

        $this->assertNotEquals($query1, $query2);
        $this->assertStringContainsString('`model_data`.`name`', $query1);
        $this->assertStringContainsString('WHERE', $query2);
    }

    public function testSelectWithoutJoinStillCompiles(): void
    {
        // Selecting from tables without joining should still compile
        $builder = $this->modelDataModel->builder()
            ->selectModelData()
            ->selectModels();

        $query = $builder->getCompiledSelect();
        $this->assertStringContainsStringIgnoringCase('SELECT', $query);
        $this->assertStringContainsStringIgnoringCase('`model_data`.`name`', $query);
        $this->assertStringContainsStringIgnoringCase('`models`.`id`', $query);
    }
}
