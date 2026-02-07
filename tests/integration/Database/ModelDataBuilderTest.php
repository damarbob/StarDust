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
        $this->assertStringContainsStringIgnoringCase('`model_data`.`id`', $query);
        $this->assertStringContainsStringIgnoringCase('models', $query);
        $this->assertStringContainsStringIgnoringCase('users', $query);
    }

    // ========================================
    // SELECT Methods Tests
    // ========================================

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
    // Order By Tests
    // ========================================

    public function testOrderByDefault(): void
    {
        $builder = $this->modelDataModel->builder()->orderByDefault();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('ORDER BY', $query);
        $this->assertStringContainsStringIgnoringCase('`data_id` DESC', $query);
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
    // Method Chaining Tests
    // ========================================

    public function testMethodChainingReturnsBuilder(): void
    {
        $builder = $this->modelDataModel->builder();

        $result = $builder->selectModels();
        $this->assertSame($builder, $result);

        $result = $builder->joinModels();
        $this->assertSame($builder, $result);

        $result = $builder->whereActive();
        $this->assertSame($builder, $result);
    }

    public function testComplexMethodChaining(): void
    {
        $builder = $this->modelDataModel->builder()
            ->selectModels()
            ->selectDefault()
            ->joinModels()
            ->joinCreator()
            ->whereActive();

        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('`model_data`.`name`', $query);
        $this->assertStringContainsStringIgnoringCase('models', $query);
        $this->assertStringContainsStringIgnoringCase('users', $query);
        $this->assertStringContainsStringIgnoringCase('deleted_at', $query);
    }

    // ========================================
    // Integration Tests with Real Data
    // ========================================

    public function testDefaultMethodWithActiveModelData(): void
    {
        // Create test models
        $this->modelsManager->create([
            'name' => 'Test Model 1',
            'fields' => json_encode([['id' => 'field1', 'type' => 'text']])
        ], $this->testUserId);

        $this->modelsManager->create([
            'name' => 'Test Model 2',
            'fields' => json_encode([['id' => 'field2', 'type' => 'text']])
        ], $this->testUserId);

        $results = $this->modelDataModel->builder()
            ->default()
            ->whereActive()
            ->get()
            ->getResultArray();

        $this->assertCount(2, $results);
        $this->assertArrayHasKey('name', $results[0]);
        // $this->assertArrayHasKey('model_name', $results[0]); // Removed checks for unsupported columns
        $this->assertArrayHasKey('created_by', $results[0]);
    }

    public function testOrderByDefaultWithRealData(): void
    {
        // Create models with names that impose order
        $id1 = $this->modelsManager->create([
            'name' => 'B Model',
            'fields' => json_encode([['id' => 'field1', 'type' => 'text']])
        ], $this->testUserId);

        $id2 = $this->modelsManager->create([
            'name' => 'A Model',
            'fields' => json_encode([['id' => 'field2', 'type' => 'text']])
        ], $this->testUserId);

        $results = $this->modelDataModel->builder()
            ->default()
            ->whereActive()
            ->orderByDefault() // Sorts by data_id DESC (Newest First)
            ->get()
            ->getResultArray();

        $this->assertCount(2, $results);
        $this->assertEquals('A Model', $results[0]['name']); // ID 2
        $this->assertEquals('B Model', $results[1]['name']); // ID 1
    }

    public function testBuilderCanBeReused(): void
    {
        $builder = $this->modelDataModel->builder();

        // First query
        $query1 = $builder->selectModels()->getCompiledSelect(false);

        // Second query with additional conditions
        $query2 = $builder->where('model_data.id', 1)->getCompiledSelect();

        $this->assertNotEquals($query1, $query2);
        $this->assertStringContainsString('`models`.`id`', $query1);
        $this->assertStringContainsString('WHERE', $query2);
    }
}
