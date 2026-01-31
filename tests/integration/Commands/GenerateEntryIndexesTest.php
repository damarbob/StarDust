<?php

namespace StarDust\Tests\Integration\Commands;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use StarDust\Services\ModelsManager;
use StarDust\Libraries\RuntimeIndexer;

/**
 * Test suite for GenerateEntryIndexes command
 *
 * Tests the virtual column and index generation functionality by testing
 * the underlying RuntimeIndexer methods that the command uses.
 * 
 * Note: These tests focus on the logic and statistics of index generation
 * rather than testing actual DDL execution or CLI interface.
 */
class GenerateEntryIndexesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $migrateOnce = false;
    protected $refresh = true;
    protected $namespace = 'StarDust';

    private ModelsManager $modelsManager;
    private RuntimeIndexer $indexer;
    private int $testUserId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modelsManager = ModelsManager::getInstance();
        $this->indexer = \StarDust\Config\Services::runtimeIndexer();
    }

    // ========================================
    // Test: Index Generation Statistics
    // ========================================

    public function testIndexAllModelsWithSingleModel(): void
    {
        // Create a model with fields
        $this->modelsManager->create([
            'name' => 'Test Model',
            'fields' => json_encode([
                ['id' => 'username', 'type' => 'text', 'label' => 'Username'],
                ['id' => 'score', 'type' => 'number', 'label' => 'Score']
            ])
        ], $this->testUserId);

        // Run indexAllModels
        $stats = $this->indexer->indexAllModels();

        // Verify statistics
        $this->assertArrayHasKey('models_processed', $stats);
        $this->assertArrayHasKey('columns_created', $stats);
        $this->assertArrayHasKey('columns_skipped', $stats);

        // Should have processed at least 1 model
        $this->assertGreaterThanOrEqual(1, $stats['models_processed']);
    }

    public function testIndexAllModelsWithMultipleModels(): void
    {
        // Create multiple models
        $this->modelsManager->create([
            'name' => 'Product Model',
            'fields' => json_encode([
                ['id' => 'product_name', 'type' => 'text', 'label' => 'Product Name'],
                ['id' => 'price', 'type' => 'number', 'label' => 'Price']
            ])
        ], $this->testUserId);

        $this->modelsManager->create([
            'name' => 'Order Model',
            'fields' => json_encode([
                ['id' => 'order_date', 'type' => 'date', 'label' => 'Order Date'],
                ['id' => 'quantity', 'type' => 'number', 'label' => 'Quantity']
            ])
        ], $this->testUserId);

        // Run indexAllModels
        $stats = $this->indexer->indexAllModels();

        // Should have processed at least 2 models
        $this->assertGreaterThanOrEqual(2, $stats['models_processed']);
    }

    // ========================================
    // Test: Field Type Handling
    // ========================================

    public function testIndexAllModelsWithDifferentFieldTypes(): void
    {
        // Create model with various field types
        $this->modelsManager->create([
            'name' => 'Mixed Types Model',
            'fields' => json_encode([
                ['id' => 'text_field', 'type' => 'text', 'label' => 'Text Field'],
                ['id' => 'email_field', 'type' => 'email', 'label' => 'Email Field'],
                ['id' => 'number_field', 'type' => 'number', 'label' => 'Number Field'],
                ['id' => 'date_field', 'type' => 'date', 'label' => 'Date Field'],
                ['id' => 'datetime_field', 'type' => 'datetime-local', 'label' => 'DateTime Field'],
                ['id' => 'range_field', 'type' => 'range', 'label' => 'Range Field'],
            ])
        ], $this->testUserId);

        // Run indexAllModels
        $stats = $this->indexer->indexAllModels();

        // Should have processed the model
        $this->assertGreaterThanOrEqual(1, $stats['models_processed']);

        // Different field types should be indexed (6 fields, minus any skipped types)
        $totalFieldsProcessed = $stats['columns_created'] + $stats['columns_skipped'];
        $this->assertGreaterThan(0, $totalFieldsProcessed);
    }

    public function testSyncIndexesSkipsPasswordFields(): void
    {
        // Create model with password field (should be skipped)
        $fields = [
            ['id' => 'normal_field', 'type' => 'text', 'label' => 'Normal Field'],
            ['id' => 'password_field', 'type' => 'password', 'label' => 'Password'],
        ];

        $this->modelsManager->create([
            'name' => 'Skipped Fields Model',
            'fields' => json_encode($fields)
        ], $this->testUserId);

        $stats = $this->indexer->indexAllModels();

        // Model should be processed
        $this->assertGreaterThanOrEqual(1, $stats['models_processed']);

        // At least 1 field should be skipped (the password)
        $this->assertGreaterThanOrEqual(1, $stats['columns_skipped']);
    }

    // ========================================
    // Test: Empty and Edge Cases
    // ========================================

    public function testIndexAllModelsWithNoModels(): void
    {
        // Delete all models
        $db = \Config\Database::connect();
        $db->table('models')->truncate();

        // Run indexAllModels
        $stats = $this->indexer->indexAllModels();

        // Should return stats with 0 models processed
        $this->assertEquals(0, $stats['models_processed']);
        $this->assertEquals(0, $stats['columns_created']);
    }

    public function testIndexAllModelsWithEmptyFieldsArray(): void
    {
        // Create model with empty fields
        $this->modelsManager->create([
            'name' => 'Empty Fields Model',
            'fields' => json_encode([])
        ], $this->testUserId);

        // Run indexAllModels - should handle gracefully
        $stats = $this->indexer->indexAllModels();

        // Should process the model even though it has no fields
        $this->assertArrayHasKey('models_processed', $stats);
    }

    // ========================================
    // Test: Soft-Deleted Models
    // ========================================

    public function testIndexAllModelsExcludesSoftDeleted(): void
    {
        // Create and immediately delete a model
        $modelId = $this->modelsManager->create([
            'name' => 'To Be Deleted Model',
            'fields' => json_encode([
                ['id' => 'deleted_field', 'type' => 'text', 'label' => 'Deleted Field']
            ])
        ], $this->testUserId);

        $this->modelsManager->deleteModels([$modelId], $this->testUserId);

        // Run indexAllModels
        $stats = $this->indexer->indexAllModels();

        // Soft-deleted models should not be indexed
        // So if this was the only model, models_processed should be 0
        $this->assertEquals(0, $stats['models_processed']);
    }

    // ========================================
    // Test: Idempotency
    // ========================================

    public function testIndexAllModelsIsIdempotent(): void
    {
        // Run twice (even with no models created)
        $stats1 = $this->indexer->indexAllModels();
        $stats2 = $this->indexer->indexAllModels();

        // Both runs should have the same structure
        $this->assertArrayHasKey('models_processed', $stats1);
        $this->assertArrayHasKey('models_processed', $stats2);

        // Both runs should return the same result
        $this->assertEquals($stats1['models_processed'], $stats2['models_processed']);
        $this->assertEquals($stats1['columns_created'], $stats2['columns_created']);
    }
}
