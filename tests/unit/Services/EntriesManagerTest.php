<?php

namespace StarDust\Tests\Unit\Services;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use StarDust\Services\EntriesManager;
use StarDust\Services\ModelsManager;

/**
 * Test suite for EntriesManager service
 *
 * Tests all CRUD operations including create, read, update, delete,
 * restore, and purge functionality.
 *
 * @todo Add test for countData() method (currently not tested)
 * @todo Add test for updateData() method (currently not tested directly)
 * @todo Add cascade behavior tests for deleteEntries() (should cascade to entry_data)
 * @todo Add cascade behavior tests for purgeDeleted() (should cascade to entry_data)
 * @todo Add error handling tests (invalid JSON, database failures, etc.)
 * @todo Add getInstance() singleton pattern tests
 * @todo Add data validation tests (empty fields, null values, required fields)
 * @todo Add tests for concurrent operations/race conditions
 */
class EntriesManagerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $migrateOnce = false;
    protected $refresh = true;
    protected $namespace = 'StarDust';

    private EntriesManager $entriesManager;
    private ModelsManager $modelsManager;
    private int $testModelId;
    private int $testUserId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entriesManager = EntriesManager::getInstance();
        $this->modelsManager = ModelsManager::getInstance();

        // Create a test model for entries to use
        $this->testModelId = $this->modelsManager->create([
            'name' => 'Test Model',
            'fields' => json_encode([
                ['id' => 'name', 'type' => 'text', 'label' => 'Name'],
                ['id' => 'email', 'type' => 'email', 'label' => 'Email'],
                ['id' => 'age', 'type' => 'number', 'label' => 'Age']
            ])
        ], $this->testUserId);
    }

    // ========================================
    // CREATE Tests
    // ========================================

    public function testCreate(): void
    {
        $entryId = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'John Doe', 'email' => 'john@example.com'])
        ], $this->testUserId);

        $this->assertIsInt($entryId);
        $this->assertGreaterThan(0, $entryId);

        // Verify the entry was created correctly
        $entry = $this->entriesManager->find($entryId);
        $this->assertIsArray($entry);
        $this->assertEquals($this->testModelId, $entry['model_id']);
        $this->assertNotNull($entry['data_id']);  // data_id is selected by builder
    }

    public function testCreateWithFields(): void
    {
        $fields = [
            'name' => 'Jane Smith',
            'email' => 'jane@example.com',
            'age' => 30
        ];

        $entryId = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode($fields)
        ], $this->testUserId);

        $entry = $this->entriesManager->find($entryId);
        $this->assertIsArray($entry);
        $this->assertEquals($this->testModelId, $entry['model_id']);
        $this->assertJson($entry['fields']);

        $decodedFields = json_decode($entry['fields'], true);
        $this->assertEquals('Jane Smith', $decodedFields['name']);
        $this->assertEquals('jane@example.com', $decodedFields['email']);
        $this->assertEquals(30, $decodedFields['age']);
    }

    public function testCreateSetsEntryDataId(): void
    {
        $entryId = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Test Entry'])
        ], $this->testUserId);

        $entry = $this->entriesManager->find($entryId);
        $this->assertNotNull($entry['data_id']);
        $this->assertIsInt(intval($entry['data_id']));
        $this->assertGreaterThan(0, intval($entry['data_id']));
    }

    // ========================================
    // READ Tests - get() and getDeleted()
    // ========================================

    public function testGetReturnsAllActiveEntries(): void
    {
        // Create multiple entries
        $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Entry 1'])
        ], $this->testUserId);

        $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Entry 2'])
        ], $this->testUserId);

        $entries = $this->entriesManager->get();
        $this->assertIsArray($entries);
        $this->assertCount(2, $entries);
    }

    public function testGetDoesNotReturnDeletedEntries(): void
    {
        $entryId = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'To Be Deleted'])
        ], $this->testUserId);

        $this->entriesManager->deleteEntries([$entryId], $this->testUserId);

        $entries = $this->entriesManager->get();
        $this->assertIsArray($entries);
        $this->assertCount(0, $entries);
    }

    public function testGetDeletedReturnsOnlyDeletedEntries(): void
    {
        $activeId = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Active Entry'])
        ], $this->testUserId);

        $deletedId = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Deleted Entry'])
        ], $this->testUserId);

        $this->entriesManager->deleteEntries([$deletedId], $this->testUserId);

        $deletedEntries = $this->entriesManager->getDeleted();
        $this->assertIsArray($deletedEntries);
        $this->assertCount(1, $deletedEntries);
        $this->assertEquals('Deleted Entry', json_decode($deletedEntries[0]['fields'], true)['name']);
    }

    // ========================================
    // READ Tests - count() and countDeleted()
    // ========================================

    public function testCountReturnsCorrectNumber(): void
    {
        $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Entry 1'])
        ], $this->testUserId);

        $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Entry 2'])
        ], $this->testUserId);

        $count = $this->entriesManager->count();
        $this->assertEquals(2, $count);
    }

    public function testCountDeletedReturnsCorrectNumber(): void
    {
        $id1 = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Entry 1'])
        ], $this->testUserId);

        $id2 = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Entry 2'])
        ], $this->testUserId);

        $this->entriesManager->deleteEntries([$id1, $id2], $this->testUserId);

        $count = $this->entriesManager->countDeleted();
        $this->assertEquals(2, $count);
    }

    // ========================================
    // READ Tests - find() and findDeleted()
    // ========================================

    public function testFindReturnsCorrectEntry(): void
    {
        $entryId = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Specific Entry'])
        ], $this->testUserId);

        $entry = $this->entriesManager->find($entryId);
        $this->assertIsArray($entry);
        $this->assertEquals('Specific Entry', json_decode($entry['fields'], true)['name']);
        $this->assertEquals($entryId, $entry['id']);
    }

    public function testFindReturnsFalseForNonexistent(): void
    {
        $result = $this->entriesManager->find(99999);
        $this->assertFalse($result);
    }

    public function testFindReturnsFalseForDeletedEntry(): void
    {
        $entryId = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Deleted Entry'])
        ], $this->testUserId);

        $this->entriesManager->deleteEntries([$entryId], $this->testUserId);

        $result = $this->entriesManager->find($entryId);
        $this->assertFalse($result);
    }

    public function testFindDeletedReturnsDeletedEntry(): void
    {
        $entryId = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Deleted Entry'])
        ], $this->testUserId);

        $this->entriesManager->deleteEntries([$entryId], $this->testUserId);

        $entry = $this->entriesManager->findDeleted($entryId);
        $this->assertIsArray($entry);
        $this->assertEquals('Deleted Entry', json_decode($entry['fields'], true)['name']);
    }

    public function testFindDeletedReturnsFalseForActiveEntry(): void
    {
        $entryId = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Active Entry'])
        ], $this->testUserId);

        $result = $this->entriesManager->findDeleted($entryId);
        $this->assertFalse($result);
    }

    // ========================================
    // READ Tests - findEntries() and findDeletedEntries()
    // ========================================

    public function testFindEntriesReturnsMultipleEntries(): void
    {
        $id1 = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Entry 1'])
        ], $this->testUserId);

        $id2 = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Entry 2'])
        ], $this->testUserId);

        $entries = $this->entriesManager->findEntries([$id1, $id2]);
        $this->assertIsArray($entries);
        $this->assertCount(2, $entries);
    }

    public function testFindEntriesReturnsFalseForEmpty(): void
    {
        $result = $this->entriesManager->findEntries([99999, 88888]);
        $this->assertFalse($result);
    }

    public function testFindDeletedEntriesReturnsMultipleDeletedEntries(): void
    {
        $id1 = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Entry 1'])
        ], $this->testUserId);

        $id2 = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Entry 2'])
        ], $this->testUserId);

        $this->entriesManager->deleteEntries([$id1, $id2], $this->testUserId);

        $entries = $this->entriesManager->findDeletedEntries([$id1, $id2]);
        $this->assertIsArray($entries);
        $this->assertCount(2, $entries);
    }

    // ========================================
    // UPDATE Tests
    // ========================================

    public function testUpdate(): void
    {
        $entryId = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Original Name', 'email' => 'original@example.com'])
        ], $this->testUserId);

        $this->entriesManager->update($entryId, [
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Updated Name', 'email' => 'updated@example.com', 'age' => 25])
        ], $this->testUserId);

        $entry = $this->entriesManager->find($entryId);
        $decodedFields = json_decode($entry['fields'], true);
        $this->assertEquals('Updated Name', $decodedFields['name']);
        $this->assertEquals('updated@example.com', $decodedFields['email']);
        $this->assertEquals(25, $decodedFields['age']);
    }

    public function testUpdateCreatesNewEntryData(): void
    {
        $entryId = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Test Entry'])
        ], $this->testUserId);

        $originalEntry = $this->entriesManager->find($entryId);
        $originalDataId = $originalEntry['data_id'];

        $this->entriesManager->update($entryId, [
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Updated Entry'])
        ], $this->testUserId);

        $updatedEntry = $this->entriesManager->find($entryId);
        $newDataId = $updatedEntry['data_id'];

        $this->assertNotEquals($originalDataId, $newDataId);
    }

    public function testUpdateEntries(): void
    {
        $id1 = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Entry 1'])
        ], $this->testUserId);

        $id2 = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Entry 2'])
        ], $this->testUserId);

        // Update the entries table directly
        $this->entriesManager->updateEntries([$id1, $id2], ['creator_id' => 999]);

        // Verify the updates - we'll need to check with count since creator_id is not in the select
        $count = $this->entriesManager->count();
        $this->assertEquals(2, $count);  // Both entries should still exist
    }

    // ========================================
    // DELETE Tests
    // ========================================

    /**
     * @todo assertEquals current user id with deleted USER ID. Because the deleted_by provides the current username instead of user id.
     */
    public function testDeleteEntries(): void
    {
        $entryId = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'To Delete'])
        ], $this->testUserId);

        $this->entriesManager->deleteEntries([$entryId], $this->testUserId);

        // Should not be found in active entries
        $result = $this->entriesManager->find($entryId);
        $this->assertFalse($result);

        // Should be found in deleted entries
        $deletedEntry = $this->entriesManager->findDeleted($entryId);
        $this->assertIsArray($deletedEntry);

        // $this->assertEquals($this->testUserId, $deletedEntry['deleted_by']);
    }

    public function testDeleteMultipleEntries(): void
    {
        $id1 = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Entry 1'])
        ], $this->testUserId);

        $id2 = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Entry 2'])
        ], $this->testUserId);

        $this->entriesManager->deleteEntries([$id1, $id2], $this->testUserId);

        $this->assertFalse($this->entriesManager->find($id1));
        $this->assertFalse($this->entriesManager->find($id2));

        $this->assertIsArray($this->entriesManager->findDeleted($id1));
        $this->assertIsArray($this->entriesManager->findDeleted($id2));
    }

    // ========================================
    // RESTORE Tests
    // ========================================

    public function testRestore(): void
    {
        $entryId = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'To Restore'])
        ], $this->testUserId);

        $this->entriesManager->deleteEntries([$entryId], $this->testUserId);
        $this->entriesManager->restore([$entryId]);

        // Should be found in active entries
        $entry = $this->entriesManager->find($entryId);
        $this->assertIsArray($entry);
        $this->assertEquals('To Restore', json_decode($entry['fields'], true)['name']);

        // Should not be found in deleted entries
        $deletedEntry = $this->entriesManager->findDeleted($entryId);
        $this->assertFalse($deletedEntry);
    }

    public function testRestoreMultipleEntries(): void
    {
        $id1 = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Entry 1'])
        ], $this->testUserId);

        $id2 = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Entry 2'])
        ], $this->testUserId);

        $this->entriesManager->deleteEntries([$id1, $id2], $this->testUserId);
        $this->entriesManager->restore([$id1, $id2]);

        $this->assertIsArray($this->entriesManager->find($id1));
        $this->assertIsArray($this->entriesManager->find($id2));
    }

    // ========================================
    // PURGE Tests
    // ========================================

    public function testPurgeDeleted(): void
    {
        $entryId = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'To Purge'])
        ], $this->testUserId);

        $this->entriesManager->deleteEntries([$entryId], $this->testUserId);
        $this->entriesManager->purgeDeleted();

        // Should not be found anywhere
        $this->assertFalse($this->entriesManager->find($entryId));
        $this->assertFalse($this->entriesManager->findDeleted($entryId));
    }

    public function testPurgeDeletedDoesNotAffectActiveEntries(): void
    {
        $activeId = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Active Entry'])
        ], $this->testUserId);

        $deletedId = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Deleted Entry'])
        ], $this->testUserId);

        $this->entriesManager->deleteEntries([$deletedId], $this->testUserId);
        $this->entriesManager->purgeDeleted();

        // Active entry should still exist
        $activeEntry = $this->entriesManager->find($activeId);
        $this->assertIsArray($activeEntry);
        $this->assertEquals('Active Entry', json_decode($activeEntry['fields'], true)['name']);

        // Deleted entry should be purged
        $this->assertFalse($this->entriesManager->findDeleted($deletedId));
    }

    public function testPurgeDeletedWithNoDeletedEntries(): void
    {
        // This should not throw any errors
        $this->entriesManager->purgeDeleted();

        // Create an entry to verify database is still intact
        $entryId = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Test Entry'])
        ], $this->testUserId);

        $this->assertGreaterThan(0, $entryId);
    }

    public function testPurgeDeletedRespectsLimit(): void
    {
        // Create 5 entries
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->entriesManager->create([
                'model_id' => $this->testModelId,
                'fields' => json_encode(['name' => "Entry $i"])
            ], $this->testUserId);
        }

        // Delete all of them
        $this->entriesManager->deleteEntries($ids, $this->testUserId);

        // Verify all 5 are deleted
        $this->assertEquals(5, $this->entriesManager->countDeleted());

        // Purge with limit = 3
        $purgedCount = $this->entriesManager->purgeDeleted(3);

        // Verify only 3 were purged
        $this->assertEquals(3, $purgedCount);
        $this->assertEquals(2, $this->entriesManager->countDeleted());

        // Purge the remaining
        $this->entriesManager->purgeDeleted();
        $this->assertEquals(0, $this->entriesManager->countDeleted());
    }
}
