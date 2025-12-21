<?php

namespace StarDust\Tests\Unit\Database;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use StarDust\Models\ModelsModel;
use StarDust\Services\ModelsManager;

/**
 * Test suite for ModelsBuilder class
 *
 * Tests query building methods including:
 * - Join methods (single and chained)
 * - Select methods
 * - Where conditions (active/deleted)
 * - Default method combinations
 *
 * @internal
 */
class ModelsBuilderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $migrateOnce = false;
    protected $refresh = true;
    protected $namespace = 'StarDust';

    private ModelsModel $modelsModel;
    private ModelsManager $modelsManager;
    private int $testUserId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modelsModel = new ModelsModel();
        $this->modelsManager = ModelsManager::getInstance();
    }

    // ========================================
    // DEFAULT Method Tests
    // ========================================

    public function testDefault(): void
    {
        $builder = $this->modelsModel->builder()->default();
        $query = $builder->getCompiledSelect();

        // Should include all selects and joins
        $this->assertStringContainsStringIgnoringCase('`models`.`id`', $query);
        $this->assertStringContainsStringIgnoringCase('model_data', $query);
        $this->assertStringContainsStringIgnoringCase('users', $query);
    }

    // ========================================
    // SELECT Methods Tests
    // ========================================

    public function testSelectModels(): void
    {
        $builder = $this->modelsModel->builder()->selectModels();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('`models`.`id`', $query);
        $this->assertStringContainsStringIgnoringCase('`models`.`created_at`', $query);
        $this->assertStringContainsStringIgnoringCase('`deleted_at` AS `date_deleted`', $query);
    }

    public function testSelectModelData(): void
    {
        $builder = $this->modelsModel->builder()->selectModelData();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('`model_data`.`name`', $query);
        $this->assertStringContainsStringIgnoringCase('`model_data`.`fields`', $query);
        $this->assertStringContainsStringIgnoringCase('`model_data`.`group`', $query);
        $this->assertStringContainsStringIgnoringCase('`model_data`.`user_groups`', $query);
        $this->assertStringContainsStringIgnoringCase('`model_data`.`icon`', $query);
        $this->assertStringContainsStringIgnoringCase('`created_at` AS `date_modified`', $query);
        $this->assertStringContainsStringIgnoringCase('`model_data`.`id` as `data_id`', $query);
    }

    public function testSelectUsers(): void
    {
        $builder = $this->modelsModel->builder()->selectUsers();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('`users`.`username` AS `created_by`', $query);
        $this->assertStringContainsStringIgnoringCase('`editors`.`username` AS `edited_by`', $query);
        $this->assertStringContainsStringIgnoringCase('`deleters`.`username` AS `deleted_by`', $query);
    }

    public function testSelectDefault(): void
    {
        $builder = $this->modelsModel->builder()->selectDefault();
        $query = $builder->getCompiledSelect();

        // Should include all select methods
        $this->assertStringContainsStringIgnoringCase('`models`.`id`', $query);
        $this->assertStringContainsStringIgnoringCase('`model_data`.`name`', $query);
        $this->assertStringContainsStringIgnoringCase('`users`.`username` AS `created_by`', $query);
    }

    // ========================================
    // JOIN Methods Tests
    // ========================================

    public function testJoinModelData(): void
    {
        $builder = $this->modelsModel->builder()->joinModelData();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('LEFT JOIN', $query);
        $this->assertStringContainsStringIgnoringCase('model_data', $query);
        $this->assertStringContainsStringIgnoringCase('current_model_data_id', $query);
    }

    public function testJoinCreator(): void
    {
        $builder = $this->modelsModel->builder()->joinCreator();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('LEFT JOIN', $query);
        $this->assertStringContainsStringIgnoringCase('users', $query);
        $this->assertStringContainsStringIgnoringCase('creator_id', $query);
    }

    public function testJoinEditor(): void
    {
        $builder = $this->modelsModel->builder()->joinEditor();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('LEFT JOIN', $query);
        $this->assertStringContainsStringIgnoringCase('editors', $query);
        $this->assertStringContainsStringIgnoringCase('`model_data`.`creator_id`', $query);
    }

    public function testJoinDeleter(): void
    {
        $builder = $this->modelsModel->builder()->joinDeleter();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('LEFT JOIN', $query);
        $this->assertStringContainsStringIgnoringCase('deleters', $query);
        $this->assertStringContainsStringIgnoringCase('deleter_id', $query);
    }

    public function testJoinDefault(): void
    {
        $builder = $this->modelsModel->builder()->joinDefault();
        $query = $builder->getCompiledSelect();

        // Should include all join methods
        $this->assertStringContainsStringIgnoringCase('model_data', $query);
        $this->assertStringContainsStringIgnoringCase('users', $query);
        $this->assertStringContainsStringIgnoringCase('editors', $query);
        $this->assertStringContainsStringIgnoringCase('deleters', $query);
    }

    // ========================================
    // WHERE Methods Tests
    // ========================================

    public function testWhereActive(): void
    {
        $builder = $this->modelsModel->builder()->whereActive();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('WHERE', $query);
        $this->assertStringContainsStringIgnoringCase('deleted_at', $query);
        $this->assertStringContainsStringIgnoringCase('IS NULL', $query);
    }

    public function testWhereDeleted(): void
    {
        $builder = $this->modelsModel->builder()->whereDeleted();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('WHERE', $query);
        $this->assertStringContainsStringIgnoringCase('deleted_at IS NOT NULL', $query);
    }

    // ========================================
    // Method Chaining Tests
    // ========================================

    public function testMethodChainingReturnsBuilder(): void
    {
        $builder = $this->modelsModel->builder();

        $result = $builder->selectModels();
        $this->assertSame($builder, $result);

        $result = $builder->joinModelData();
        $this->assertSame($builder, $result);

        $result = $builder->whereActive();
        $this->assertSame($builder, $result);
    }

    public function testComplexMethodChaining(): void
    {
        $builder = $this->modelsModel->builder()
            ->selectModels()
            ->selectModelData()
            ->joinModelData()
            ->joinCreator()
            ->whereActive();

        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('`models`.`id`', $query);
        $this->assertStringContainsStringIgnoringCase('`model_data`.`name`', $query);
        $this->assertStringContainsStringIgnoringCase('model_data', $query);
        $this->assertStringContainsStringIgnoringCase('users', $query);
        $this->assertStringContainsStringIgnoringCase('deleted_at', $query);
    }

    // ========================================
    // Integration Tests with Real Data
    // ========================================

    public function testDefaultMethodWithActiveModels(): void
    {
        // Create test models
        $this->modelsManager->create([
            'name' => 'Active Model 1',
            'fields' => json_encode([
                ['id' => 'field1', 'type' => 'text', 'label' => 'Field 1']
            ])
        ], $this->testUserId);

        $this->modelsManager->create([
            'name' => 'Active Model 2',
            'fields' => json_encode([
                ['id' => 'field2', 'type' => 'text', 'label' => 'Field 2']
            ])
        ], $this->testUserId);

        $results = $this->modelsModel->builder()
            ->default()
            ->whereActive()
            ->get()
            ->getResultArray();

        $this->assertCount(2, $results);
        $this->assertArrayHasKey('id', $results[0]);
        $this->assertArrayHasKey('name', $results[0]);
        $this->assertArrayHasKey('fields', $results[0]);
        $this->assertArrayHasKey('created_by', $results[0]);
    }

    public function testDefaultMethodWithDeletedModels(): void
    {
        // Create and delete a model
        $modelId = $this->modelsManager->create([
            'name' => 'Deleted Model',
            'fields' => json_encode([
                ['id' => 'field1', 'type' => 'text', 'label' => 'Field 1']
            ])
        ], $this->testUserId);

        $this->modelsManager->deleteModels([$modelId], $this->testUserId);

        // Query deleted models
        $results = $this->modelsModel->builder()
            ->default()
            ->whereDeleted()
            ->get()
            ->getResultArray();

        $this->assertCount(1, $results);
        $this->assertNotNull($results[0]['date_deleted']);
        $this->assertArrayHasKey('deleted_by', $results[0]);
    }

    public function testJoinOrderDependency(): void
    {
        // Test that joinEditor requires joinModelData
        $builder = $this->modelsModel->builder()
            ->joinModelData()
            ->joinEditor();

        $query = $builder->getCompiledSelect();
        $this->assertStringContainsString('LEFT JOIN `model_data`', $query);
        $this->assertStringContainsString('LEFT JOIN `users` as `editors`', $query);
    }

    public function testSelectWithoutJoinStillCompiles(): void
    {
        // Selecting from tables without joining should still compile
        $builder = $this->modelsModel->builder()
            ->selectModels()
            ->selectModelData();

        $query = $builder->getCompiledSelect();
        $this->assertStringContainsStringIgnoringCase('SELECT', $query);
        $this->assertStringContainsStringIgnoringCase('`models`.`id`', $query);
        $this->assertStringContainsStringIgnoringCase('`model_data`.`name`', $query);
    }

    // ========================================
    // Edge Cases and Error Handling
    // ========================================

    public function testMultipleWhereConditions(): void
    {
        $builder = $this->modelsModel->builder()
            ->whereActive()
            ->where('models.id >', 0);

        $query = $builder->getCompiledSelect();
        $this->assertStringContainsStringIgnoringCase('WHERE', $query);
        $this->assertStringContainsStringIgnoringCase('deleted_at', $query);
        $this->assertStringContainsStringIgnoringCase('`models`.`id`', $query);
    }

    public function testBuilderCanBeReused(): void
    {
        $builder = $this->modelsModel->builder();

        // First query
        $query1 = $builder->selectModels()->getCompiledSelect(false);

        // Second query with additional conditions
        $query2 = $builder->where('models.id', 1)->getCompiledSelect();

        $this->assertNotEquals($query1, $query2);
        $this->assertStringContainsString('`models`.`id`', $query1);
        $this->assertStringContainsString('WHERE', $query2);
    }

    public function testBothActiveAndDeletedConditions(): void
    {
        // Create active and deleted models
        $this->modelsManager->create([
            'name' => 'Active Model',
            'fields' => json_encode([])
        ], $this->testUserId);

        $deletedId = $this->modelsManager->create([
            'name' => 'Deleted Model',
            'fields' => json_encode([])
        ], $this->testUserId);

        $this->modelsManager->deleteModels([$deletedId], $this->testUserId);

        // Test active
        $activeResults = $this->modelsModel->builder()
            ->selectModels()
            ->whereActive()
            ->get()
            ->getResultArray();

        $this->assertCount(1, $activeResults);

        // Test deleted
        $deletedResults = $this->modelsModel->builder()
            ->selectModels()
            ->whereDeleted()
            ->get()
            ->getResultArray();

        $this->assertCount(1, $deletedResults);
    }
}
