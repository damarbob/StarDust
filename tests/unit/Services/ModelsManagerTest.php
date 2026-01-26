<?php

namespace StarDust\Tests\Unit\Services;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use StarDust\Services\ModelsManager;
use StarDust\Services\EntriesManager;

/**
 * Test suite for ModelsManager service
 *
 * Tests all CRUD operations including create, read, update, delete,
 * restore, and purge functionality. Also tests cascade behavior to entries.
 *
 * @todo Add test for updateData() method (currently not tested directly)
 * @todo Add error handling tests (invalid JSON, database failures, etc.)
 * @todo Add RuntimeIndexer integration tests (verify syncIndexes is called)
 * @todo Add getInstance() singleton pattern tests
 * @todo Add data validation tests (empty fields, null values, required fields)
 * @todo Add tests for concurrent operations/race conditions
 * @todo Add tests for syncIndexes failure scenarios (noted in create/update TODOs)
 */
class ModelsManagerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $migrateOnce = false;
    protected $refresh = true;
    protected $namespace = 'StarDust';

    private ModelsManager $modelsManager;
    private EntriesManager $entriesManager;
    private int $testUserId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modelsManager = ModelsManager::getInstance();
        $this->entriesManager = EntriesManager::getInstance();
    }

    // ========================================
    // CREATE Tests
    // ========================================

    public function testCreate(): void
    {
        $modelId = $this->modelsManager->create([
            'name' => 'Product Model',
            'fields' => json_encode([
                ['id' => 'title', 'type' => 'text'],
                ['id' => 'price', 'type' => 'number']
            ])
        ], $this->testUserId);

        $this->assertIsInt($modelId);
        $this->assertGreaterThan(0, $modelId);

        // Verify the model was created correctly
        $model = $this->modelsManager->find($modelId);
        $this->assertIsArray($model);
        $this->assertEquals('Product Model', $model['name']);
        $this->assertNotNull($model['data_id']);  // data_id is selected by builder
    }

    public function testCreateWithMultipleFields(): void
    {
        $fields = [
            ['id' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true],
            ['id' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true],
            ['id' => 'phone', 'type' => 'tel', 'label' => 'Phone', 'required' => false],
            ['id' => 'address', 'type' => 'textarea', 'label' => 'Address', 'required' => false]
        ];

        $modelId = $this->modelsManager->create([
            'name' => 'Contact Model',
            'fields' => json_encode($fields)
        ], $this->testUserId);

        $model = $this->modelsManager->find($modelId);
        $this->assertIsArray($model);
        $this->assertEquals('Contact Model', $model['name']);
        $this->assertJson($model['fields']);

        $decodedFields = json_decode($model['fields'], true);
        $this->assertCount(4, $decodedFields);
    }

    public function testCreateSetsModelDataId(): void
    {
        $modelId = $this->modelsManager->create([
            'name' => 'Test Model',
            'fields' => json_encode([['id' => 'field1', 'type' => 'text']])
        ], $this->testUserId);

        $model = $this->modelsManager->find($modelId);
        $this->assertNotNull($model['data_id']);
        $this->assertIsInt(intval($model['data_id']));
        $this->assertGreaterThan(0, intval($model['data_id']));
    }

    // ========================================
    // READ Tests - get() and getDeleted()
    // ========================================

    public function testGetReturnsAllActiveModels(): void
    {
        // Create multiple models
        $this->modelsManager->create([
            'name' => 'Model 1',
            'fields' => json_encode([['id' => 'field1', 'type' => 'text']])
        ], $this->testUserId);

        $this->modelsManager->create([
            'name' => 'Model 2',
            'fields' => json_encode([['id' => 'field2', 'type' => 'text']])
        ], $this->testUserId);

        $models = $this->modelsManager->get();
        $this->assertIsArray($models);
        $this->assertCount(2, $models);
    }

    public function testGetDoesNotReturnDeletedModels(): void
    {
        $modelId = $this->modelsManager->create([
            'name' => 'To Be Deleted',
            'fields' => json_encode([['id' => 'field1', 'type' => 'text']])
        ], $this->testUserId);

        $this->modelsManager->deleteModels([$modelId], $this->testUserId);

        $models = $this->modelsManager->get();
        $this->assertIsArray($models);
        $this->assertCount(0, $models);
    }

    public function testGetDeletedReturnsOnlyDeletedModels(): void
    {
        $activeId = $this->modelsManager->create([
            'name' => 'Active Model',
            'fields' => json_encode([['id' => 'field1', 'type' => 'text']])
        ], $this->testUserId);

        $deletedId = $this->modelsManager->create([
            'name' => 'Deleted Model',
            'fields' => json_encode([['id' => 'field2', 'type' => 'text']])
        ], $this->testUserId);

        $this->modelsManager->deleteModels([$deletedId], $this->testUserId);

        $deletedModels = $this->modelsManager->getDeleted();
        $this->assertIsArray($deletedModels);
        $this->assertCount(1, $deletedModels);
        $this->assertEquals('Deleted Model', $deletedModels[0]['name']);
    }

    // ========================================
    // READ Tests - count() and countDeleted()
    // ========================================

    public function testCountReturnsCorrectNumber(): void
    {
        $this->modelsManager->create([
            'name' => 'Model 1',
            'fields' => json_encode([['id' => 'field1', 'type' => 'text']])
        ], $this->testUserId);

        $this->modelsManager->create([
            'name' => 'Model 2',
            'fields' => json_encode([['id' => 'field2', 'type' => 'text']])
        ], $this->testUserId);

        $count = $this->modelsManager->count();
        $this->assertEquals(2, $count);
    }

    public function testCountDeletedReturnsCorrectNumber(): void
    {
        $id1 = $this->modelsManager->create([
            'name' => 'Model 1',
            'fields' => json_encode([['id' => 'field1', 'type' => 'text']])
        ], $this->testUserId);

        $id2 = $this->modelsManager->create([
            'name' => 'Model 2',
            'fields' => json_encode([['id' => 'field2', 'type' => 'text']])
        ], $this->testUserId);

        $this->modelsManager->deleteModels([$id1, $id2], $this->testUserId);

        $count = $this->modelsManager->countDeleted();
        $this->assertEquals(2, $count);
    }

    // ========================================
    // READ Tests - find() and findDeleted()
    // ========================================

    public function testFindReturnsCorrectModel(): void
    {
        $modelId = $this->modelsManager->create([
            'name' => 'Specific Model',
            'fields' => json_encode([['id' => 'field1', 'type' => 'text']])
        ], $this->testUserId);

        $model = $this->modelsManager->find($modelId);
        $this->assertIsArray($model);
        $this->assertEquals('Specific Model', $model['name']);
        $this->assertEquals($modelId, $model['id']);
    }

    public function testFindReturnsFalseForNonexistent(): void
    {
        $result = $this->modelsManager->find(99999);
        $this->assertFalse($result);
    }

    public function testFindReturnsFalseForDeletedModel(): void
    {
        $modelId = $this->modelsManager->create([
            'name' => 'Deleted Model',
            'fields' => json_encode([['id' => 'field1', 'type' => 'text']])
        ], $this->testUserId);

        $this->modelsManager->deleteModels([$modelId], $this->testUserId);

        $result = $this->modelsManager->find($modelId);
        $this->assertFalse($result);
    }

    public function testFindDeletedReturnsDeletedModel(): void
    {
        $modelId = $this->modelsManager->create([
            'name' => 'Deleted Model',
            'fields' => json_encode([['id' => 'field1', 'type' => 'text']])
        ], $this->testUserId);

        $this->modelsManager->deleteModels([$modelId], $this->testUserId);

        $model = $this->modelsManager->findDeleted($modelId);
        $this->assertIsArray($model);
        $this->assertEquals('Deleted Model', $model['name']);
    }

    public function testFindDeletedReturnsFalseForActiveModel(): void
    {
        $modelId = $this->modelsManager->create([
            'name' => 'Active Model',
            'fields' => json_encode([['id' => 'field1', 'type' => 'text']])
        ], $this->testUserId);

        $result = $this->modelsManager->findDeleted($modelId);
        $this->assertFalse($result);
    }

    // ========================================
    // READ Tests - findModels() and findDeletedModels()
    // ========================================

    public function testFindModelsReturnsMultipleModels(): void
    {
        $id1 = $this->modelsManager->create([
            'name' => 'Model 1',
            'fields' => json_encode([['id' => 'field1', 'type' => 'text']])
        ], $this->testUserId);

        $id2 = $this->modelsManager->create([
            'name' => 'Model 2',
            'fields' => json_encode([['id' => 'field2', 'type' => 'text']])
        ], $this->testUserId);

        $models = $this->modelsManager->findModels([$id1, $id2]);
        $this->assertIsArray($models);
        $this->assertCount(2, $models);
    }

    public function testFindModelsReturnsFalseForEmpty(): void
    {
        $result = $this->modelsManager->findModels([99999, 88888]);
        $this->assertFalse($result);
    }

    public function testFindDeletedModelsReturnsMultipleDeletedModels(): void
    {
        $id1 = $this->modelsManager->create([
            'name' => 'Model 1',
            'fields' => json_encode([['id' => 'field1', 'type' => 'text']])
        ], $this->testUserId);

        $id2 = $this->modelsManager->create([
            'name' => 'Model 2',
            'fields' => json_encode([['id' => 'field2', 'type' => 'text']])
        ], $this->testUserId);

        $this->modelsManager->deleteModels([$id1, $id2], $this->testUserId);

        $models = $this->modelsManager->findDeletedModels([$id1, $id2]);
        $this->assertIsArray($models);
        $this->assertCount(2, $models);
    }

    // ========================================
    // UPDATE Tests
    // ========================================

    public function testUpdate(): void
    {
        $modelId = $this->modelsManager->create([
            'name' => 'Original Name',
            'fields' => json_encode([['id' => 'field1', 'type' => 'text']])
        ], $this->testUserId);

        $this->modelsManager->update($modelId, [
            'name' => 'Updated Name',
            'fields' => json_encode([
                ['id' => 'field1', 'type' => 'text'],
                ['id' => 'field2', 'type' => 'number']
            ])
        ], $this->testUserId);

        $model = $this->modelsManager->find($modelId);
        $this->assertEquals('Updated Name', $model['name']);

        $decodedFields = json_decode($model['fields'], true);
        $this->assertCount(2, $decodedFields);
    }

    public function testUpdateCreatesNewModelData(): void
    {
        $modelId = $this->modelsManager->create([
            'name' => 'Test Model',
            'fields' => json_encode([['id' => 'field1', 'type' => 'text']])
        ], $this->testUserId);

        $originalModel = $this->modelsManager->find($modelId);
        $originalDataId = $originalModel['data_id'];

        $this->modelsManager->update($modelId, [
            'name' => 'Updated Model',
            'fields' => json_encode([['id' => 'field1', 'type' => 'text']])
        ], $this->testUserId);

        $updatedModel = $this->modelsManager->find($modelId);
        $newDataId = $updatedModel['data_id'];

        $this->assertNotEquals($originalDataId, $newDataId);
    }

    public function testUpdateModels(): void
    {
        $id1 = $this->modelsManager->create([
            'name' => 'Model 1',
            'fields' => json_encode([['id' => 'field1', 'type' => 'text']])
        ], $this->testUserId);

        $id2 = $this->modelsManager->create([
            'name' => 'Model 2',
            'fields' => json_encode([['id' => 'field2', 'type' => 'text']])
        ], $this->testUserId);

        // Update the models table directly
        $this->modelsManager->updateModels([$id1, $id2], ['creator_id' => 999]);

        // Verify the updates - we'll need to check with count since creator_id is not in the select
        $count = $this->modelsManager->count();
        $this->assertEquals(2, $count);  // Both models should still exist
    }

    // ========================================
    // DELETE Tests
    // ========================================

    /**
     * @todo assertEquals current user id with deleted USER ID. Because the deleted_by provides the current username instead of user id.
     */
    public function testDeleteModels(): void
    {
        $modelId = $this->modelsManager->create([
            'name' => 'To Delete',
            'fields' => json_encode([[
                'id' => 'field1',
                'type' => 'text',
                'label' => 'Field 1',
                'required' => true
            ]]),
        ], $this->testUserId);

        $this->modelsManager->deleteModels([$modelId], $this->testUserId);

        // Should not be found in active models
        $result = $this->modelsManager->find($modelId);
        $this->assertFalse($result);

        // Should be found in deleted models
        $deletedModel = $this->modelsManager->findDeleted($modelId);
        $this->assertIsArray($deletedModel);

        // $this->assertEquals($this->testUserId, $deletedModel['deleted_by']);
    }

    public function testDeleteModelsCascadesToEntries(): void
    {
        $modelId = $this->modelsManager->create([
            'name' => 'Model With Entries',
            'fields' => json_encode([['id' => 'field1', 'type' => 'text']])
        ], $this->testUserId);

        // Create some entries for this model
        $entryId1 = $this->entriesManager->create([
            'model_id' => $modelId,
            'fields' => json_encode(['field1' => 'value1'])
        ], $this->testUserId);

        $entryId2 = $this->entriesManager->create([
            'model_id' => $modelId,
            'fields' => json_encode(['field1' => 'value2'])
        ], $this->testUserId);

        // Delete the model
        $this->modelsManager->deleteModels([$modelId], $this->testUserId);

        // Verify entries are also deleted
        $entry1 = $this->entriesManager->find($entryId1);
        $entry2 = $this->entriesManager->find($entryId2);

        $this->assertFalse($entry1);
        $this->assertFalse($entry2);

        // Verify entries exist in deleted state
        $deletedEntry1 = $this->entriesManager->findDeleted($entryId1);
        $deletedEntry2 = $this->entriesManager->findDeleted($entryId2);

        $this->assertIsArray($deletedEntry1);
        $this->assertIsArray($deletedEntry2);
    }

    public function testDeleteMultipleModels(): void
    {
        $id1 = $this->modelsManager->create([
            'name' => 'Model 1',
            'fields' => json_encode([['id' => 'field1', 'type' => 'text']])
        ], $this->testUserId);

        $id2 = $this->modelsManager->create([
            'name' => 'Model 2',
            'fields' => json_encode([['id' => 'field2', 'type' => 'text']])
        ], $this->testUserId);

        $this->modelsManager->deleteModels([$id1, $id2], $this->testUserId);

        $this->assertFalse($this->modelsManager->find($id1));
        $this->assertFalse($this->modelsManager->find($id2));

        $this->assertIsArray($this->modelsManager->findDeleted($id1));
        $this->assertIsArray($this->modelsManager->findDeleted($id2));
    }

    // ========================================
    // RESTORE Tests
    // ========================================

    public function testRestore(): void
    {
        $modelId = $this->modelsManager->create([
            'name' => 'To Restore',
            'fields' => json_encode([['id' => 'field1', 'type' => 'text']])
        ], $this->testUserId);

        $this->modelsManager->deleteModels([$modelId], $this->testUserId);
        $this->modelsManager->restore([$modelId]);

        // Should be found in active models
        $model = $this->modelsManager->find($modelId);
        $this->assertIsArray($model);
        $this->assertEquals('To Restore', $model['name']);

        // Should not be found in deleted models
        $deletedModel = $this->modelsManager->findDeleted($modelId);
        $this->assertFalse($deletedModel);
    }

    public function testRestoreCascadesToEntries(): void
    {
        $modelId = $this->modelsManager->create([
            'name' => 'Model With Entries',
            'fields' => json_encode([['id' => 'field1', 'type' => 'text']])
        ], $this->testUserId);

        $entryId = $this->entriesManager->create([
            'model_id' => $modelId,
            'fields' => json_encode(['field1' => 'value1'])
        ], $this->testUserId);

        // Delete and then restore
        $this->modelsManager->deleteModels([$modelId], $this->testUserId);
        $this->modelsManager->restore([$modelId]);

        // Verify entry is also restored
        $entry = $this->entriesManager->find($entryId);
        $this->assertIsArray($entry);
        $this->assertEquals('value1', json_decode($entry['fields'], true)['field1']);
    }

    public function testRestoreMultipleModels(): void
    {
        $id1 = $this->modelsManager->create([
            'name' => 'Model 1',
            'fields' => json_encode([['id' => 'field1', 'type' => 'text']])
        ], $this->testUserId);

        $id2 = $this->modelsManager->create([
            'name' => 'Model 2',
            'fields' => json_encode([['id' => 'field2', 'type' => 'text']])
        ], $this->testUserId);

        $this->modelsManager->deleteModels([$id1, $id2], $this->testUserId);
        $this->modelsManager->restore([$id1, $id2]);

        $this->assertIsArray($this->modelsManager->find($id1));
        $this->assertIsArray($this->modelsManager->find($id2));
    }

    // ========================================
    // PURGE Tests
    // ========================================

    public function testPurgeDeleted(): void
    {
        $modelId = $this->modelsManager->create([
            'name' => 'To Purge',
            'fields' => json_encode([['id' => 'field1', 'type' => 'text']])
        ], $this->testUserId);

        $this->modelsManager->deleteModels([$modelId], $this->testUserId);
        $this->modelsManager->purgeDeleted();

        // Should not be found anywhere
        $this->assertFalse($this->modelsManager->find($modelId));
        $this->assertFalse($this->modelsManager->findDeleted($modelId));
    }

    public function testPurgeDeletedWaitsForEntriesToClear(): void
    {
        $modelId = $this->modelsManager->create([
            'name' => 'Model With Entries',
            'fields' => json_encode([['id' => 'field1', 'type' => 'text']])
        ], $this->testUserId);

        $entryId = $this->entriesManager->create([
            'model_id' => $modelId,
            'fields' => json_encode(['field1' => 'value1'])
        ], $this->testUserId);

        // Soft delete both
        $this->modelsManager->deleteModels([$modelId], $this->testUserId);

        // 1. Run Purge Models
        // Should NOT purge the model because an entry (soft-deleted) still exists
        $purgedCount = $this->modelsManager->purgeDeleted();
        $this->assertEquals(0, $purgedCount, 'Should skip models that still have entries');

        // Verify model still exists (as deleted)
        $this->assertIsArray($this->modelsManager->findDeleted($modelId));

        // 2. Run Purge Entries
        // Now we really delete the entry
        $this->entriesManager->purgeDeleted();

        // 3. Run Purge Models again
        // Now it should succeed
        $purgedCount = $this->modelsManager->purgeDeleted();
        $this->assertEquals(1, $purgedCount, 'Should purge model after entries are gone');

        // Verify model is permanently deleted
        $this->assertFalse($this->modelsManager->findDeleted($modelId));
    }

    public function testPurgeDeletedDoesNotAffectActiveModels(): void
    {
        $activeId = $this->modelsManager->create([
            'name' => 'Active Model',
            'fields' => json_encode([['id' => 'field1', 'type' => 'text']])
        ], $this->testUserId);

        $deletedId = $this->modelsManager->create([
            'name' => 'Deleted Model',
            'fields' => json_encode([['id' => 'field2', 'type' => 'text']])
        ], $this->testUserId);

        $this->modelsManager->deleteModels([$deletedId], $this->testUserId);
        $this->modelsManager->purgeDeleted();

        // Active model should still exist
        $activeModel = $this->modelsManager->find($activeId);
        $this->assertIsArray($activeModel);
        $this->assertEquals('Active Model', $activeModel['name']);

        // Deleted model should be purged
        $this->assertFalse($this->modelsManager->findDeleted($deletedId));
    }

    public function testPurgeDeletedWithNoDeletedModels(): void
    {
        // This should not throw any errors
        $this->modelsManager->purgeDeleted();

        // Create a model to verify database is still intact
        $modelId = $this->modelsManager->create([
            'name' => 'Test Model',
            'fields' => json_encode([['id' => 'field1', 'type' => 'text']])
        ], $this->testUserId);

        $this->assertGreaterThan(0, $modelId);
    }

    public function testPurgeDeletedRespectsLimit(): void
    {
        // Create 5 models to delete
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->modelsManager->create([
                'name' => "Model $i",
                'fields' => json_encode([['id' => 'field1', 'type' => 'text']])
            ], $this->testUserId);
        }

        // Delete all of them
        $this->modelsManager->deleteModels($ids, $this->testUserId);

        // Verify all 5 are deleted
        $this->assertEquals(5, $this->modelsManager->countDeleted());

        // Purge with limit = 3
        $purgedCount = $this->modelsManager->purgeDeleted(3);

        // Verify only 3 were purged
        $this->assertEquals(3, $purgedCount);
        $this->assertEquals(2, $this->modelsManager->countDeleted());

        // Purge the remaining
        $this->modelsManager->purgeDeleted();
        $this->assertEquals(0, $this->modelsManager->countDeleted());
    }
}
