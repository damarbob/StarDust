<?php

namespace StarDust\Tests\Integration\Commands;

use StarDust\Tests\Integration\StarDustTestCase;
use StarDust\Services\ModelsManager;
use StarDust\Libraries\RuntimeIndexer;

/**
 * Test suite for CleanupOrphanedColumns command
 *
 * Tests the orphaned column cleanup functionality by testing the underlying
 * RuntimeIndexer methods that the command uses.
 * 
 * Note: These tests focus on the logic of finding and removing orphaned columns
 * rather than testing the CLI command interface directly, which requires full
 * CodeIgniter CLI infrastructure.
 */
class CleanupOrphanedColumnsTest extends StarDustTestCase
{
    private ModelsManager $modelsManager;
    private RuntimeIndexer $indexer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modelsManager = ModelsManager::getInstance();
        $this->indexer = \StarDust\Config\Services::runtimeIndexer();
    }

    // ========================================
    // Test: Find Orphaned Columns Logic
    // ========================================

    public function testFindOrphanedColumnsWithNoModels(): void
    {
        // With no models, all virtual columns (if any exist) would be orphaned
        $orphaned = $this->indexer->findOrphanedColumns();

        // Should return an array (could be empty if no virtual columns exist)
        $this->assertIsArray($orphaned);
    }

    public function testFindOrphanedColumnsWithActiveModel(): void
    {
        // Create a model with fields
        $this->modelsManager->create([
            'name' => 'Test Model',
            'fields' => json_encode([
                ['id' => 'name', 'type' => 'text', 'label' => 'Name'],
                ['id' => 'age', 'type' => 'number', 'label' => 'Age']
            ])
        ], $this->testUserId);

        // Find orphaned columns - should not find columns for active model fields
        // (even if the virtual columns don't exist yet, they shouldn't be in orphaned list)
        $orphaned = $this->indexer->findOrphanedColumns();

        // The logic should not report v_name_str or v_age_num as orphaned
        // because these fields exist in an active model
        $this->assertNotContains('v_name_str', $orphaned);
        $this->assertNotContains('v_age_num', $orphaned);
    }

    public function testFindOrphanedColumnsWithSoftDeletedModel(): void
    {
        // Create a model
        $modelId = $this->modelsManager->create([
            'name' => 'To Be Deleted',
            'fields' => json_encode([
                ['id' => 'protected_field', 'type' => 'text', 'label' => 'Protected']
            ])
        ], $this->testUserId);

        // Soft-delete it
        $this->modelsManager->deleteModels([$modelId], $this->testUserId);

        // Find orphaned columns
        $orphaned = $this->indexer->findOrphanedColumns();

        // Soft-deleted model fields should NOT be considered orphaned
        // because findOrphanedColumns() checks withDeleted() models
        $this->assertNotContains('v_protected_field_str', $orphaned);
    }

    // ========================================
    // Test: Get All Virtual Columns
    // ========================================

    public function testGetAllVirtualColumns(): void
    {
        $allColumns = $this->indexer->getAllVirtualColumns();

        // Should return an array
        $this->assertIsArray($allColumns);

        // All items should match the virtual column pattern
        foreach ($allColumns as $column) {
            $this->assertMatchesRegularExpression('/^v_.+_(num|str|dt)$/', $column);
        }
    }

    // ========================================
    // Test: Remove Orphaned Columns Validation
    // ========================================

    public function testRemoveOrphanedColumnsWithInvalidName(): void
    {
        // Try to remove a column with invalid naming pattern
        $results = $this->indexer->removeOrphanedColumns(['invalid_column_name']);

        // Should fail validation
        $this->assertEmpty($results['success']);
        $this->assertNotEmpty($results['failed']);
        $this->assertArrayHasKey('invalid_column_name', $results['failed']);
        $this->assertStringContainsString('Invalid column name format', $results['failed']['invalid_column_name']);
    }

    public function testRemoveOrphanedColumnsWithValidPattern(): void
    {
        // Try to remove a non-existent but validly-named column
        $results = $this->indexer->removeOrphanedColumns(['v_test_field_str']);

        // Should attempt the operation (might succeed or fail depending on column existence)
        $this->assertIsArray($results);
        $this->assertArrayHasKey('success', $results);
        $this->assertArrayHasKey('failed', $results);
    }

    public function testRemoveOrphanedColumnsWithEmptyArray(): void
    {
        // Remove with empty array should handle gracefully
        $results = $this->indexer->removeOrphanedColumns([]);

        $this->assertEmpty($results['success']);
        $this->assertEmpty($results['failed']);
    }

    // ========================================
    // Test: Index All Models Statistics
    // ========================================

    public function testIndexAllModelsReturnsStatistics(): void
    {
        // Create a couple of models
        $this->modelsManager->create([
            'name' => 'Model 1',
            'fields' => json_encode([
                ['id' => 'field1', 'type' => 'text', 'label' => 'Field 1']
            ])
        ], $this->testUserId);

        $this->modelsManager->create([
            'name' => 'Model 2',
            'fields' => json_encode([
                ['id' => 'field2', 'type' => 'number', 'label' => 'Field 2']
            ])
        ], $this->testUserId);

        // Run indexAllModels
        $stats = $this->indexer->indexAllModels();

        // Verify statistics structure
        $this->assertArrayHasKey('models_processed', $stats);
        $this->assertArrayHasKey('columns_created', $stats);
        $this->assertArrayHasKey('columns_skipped', $stats);

        // Should have processed at least 2 models
        $this->assertGreaterThanOrEqual(2, $stats['models_processed']);
    }

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

    // ========================================
    // Test: Idempotency
    // ========================================

    public function testFindOrphanedColumnsIsIdempotent(): void
    {
        // Create a model
        $this->modelsManager->create([
            'name' => 'Test Model',
            'fields' => json_encode([
                ['id' => 'test', 'type' => 'text', 'label' => 'Test']
            ])
        ], $this->testUserId);

        // Run twice
        $orphaned1 = $this->indexer->findOrphanedColumns();
        $orphaned2 = $this->indexer->findOrphanedColumns();

        // Should return the same result
        $this->assertEquals($orphaned1, $orphaned2);
    }

    public function testIndexAllModelsIsIdempotent(): void
    {
        // Create a model
        $this->modelsManager->create([
            'name' => 'Test Model',
            'fields' => json_encode([
                ['id' => 'test', 'type' => 'text', 'label' => 'Test']
            ])
        ], $this->testUserId);

        // Run twice
        $stats1 = $this->indexer->indexAllModels();
        $stats2 = $this->indexer->indexAllModels();

        // Second run should skip already created columns
        // (columns_skipped should be higher or equal)
        $this->assertGreaterThanOrEqual(
            $stats1['columns_skipped'],
            $stats2['columns_skipped']
        );
    }
}
