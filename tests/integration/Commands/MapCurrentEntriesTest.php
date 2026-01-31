<?php

namespace StarDust\Tests\Integration\Commands;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use StarDust\Services\EntriesManager;
use StarDust\Services\ModelsManager;

/**
 * Test suite for MapCurrentEntries command
 *
 * Tests the mapping of current version IDs for entries and models.
 * The command updates current_entry_data_id and current_model_data_id 
 * columns to point to the latest history records.
 * 
 * Note: Tests the database update logic that the command performs.
 */
class MapCurrentEntriesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $migrateOnce = false;
    protected $refresh = true;
    protected $namespace = 'StarDust';

    private EntriesManager $entriesManager;
    private ModelsManager $modelsManager;
    private int $testUserId = 1;
    private int $testModelId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entriesManager = EntriesManager::getInstance();
        $this->modelsManager = ModelsManager::getInstance();

        // Create a test model
        $this->testModelId =  $this->modelsManager->create([
            'name' => 'Test Model',
            'fields' => json_encode([
                ['id' => 'name', 'type' => 'text', 'label' => 'Name'],
                ['id' => 'value', 'type' => 'number', 'label' => 'Value']
            ])
        ], $this->testUserId);
    }

    // ========================================
    // Helper Methods
    // ========================================

    private function getLatestEntryDataId(int $entryId): ?int
    {
        $db = \Config\Database::connect();
        $result = $db->table('entry_data')
            ->where('entry_id', $entryId)
            ->where('deleted_at IS NULL')
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        return $result ? (int)$result['id'] : null;
    }

    private function getCurrentEntryDataId(int $entryId): ?int
    {
        $db = \Config\Database::connect();
        $result = $db->table('entries')
            ->where('id', $entryId)
            ->get()
            ->getRowArray();

        return $result && isset($result['current_entry_data_id'])
            ? (int)$result['current_entry_data_id']
            : null;
    }

    private function mapCurrentEntriesCommand(): void
    {
        // Execute the mapping logic that the command performs
        $db = \Config\Database::connect();

        // Map entries
        $db->query("
            UPDATE entries
            INNER JOIN (
                SELECT entry_id, MAX(id) as max_id
                FROM entry_data
                WHERE deleted_at IS NULL
                GROUP BY entry_id
            ) as latest_history ON entries.id = latest_history.entry_id
            SET entries.current_entry_data_id = latest_history.max_id
            WHERE entries.deleted_at IS NULL
        ");

        // Map models
        $db->query("
            UPDATE models
            INNER JOIN (
                SELECT model_id, MAX(id) as max_id
                FROM model_data
                WHERE deleted_at IS NULL
                GROUP BY model_id
            ) as latest_history ON models.id = latest_history.model_id
            SET models.current_model_data_id = latest_history.max_id
            WHERE models.deleted_at IS NULL
        ");
    }

    // ========================================
    // Test: Map Single Entry
    // ========================================

    public function testMapSingleEntry(): void
    {
        // Create an entry
        $entryId = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Initial'])
        ], $this->testUserId);

        // Get expected data ID
        $expectedDataId = $this->getLatestEntryDataId($entryId);
        $this->assertNotNull($expectedDataId);

        // Run mapping
        $this->mapCurrentEntriesCommand();

        // Verify current_entry_data_id was set correctly
        $currentDataId = $this->getCurrentEntryDataId($entryId);
        $this->assertEquals($expectedDataId, $currentDataId);
    }

    // ========================================
    // Test: Map Multiple Entries
    // ========================================

    public function testMapMultipleEntries(): void
    {
        // Create multiple entries
        $entryId1 = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Entry 1'])
        ], $this->testUserId);

        $entryId2 = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Entry 2'])
        ], $this->testUserId);

        // Run mapping
        $this->mapCurrentEntriesCommand();

        // Verify both entries have current_entry_data_id set
        $current1 = $this->getCurrentEntryDataId($entryId1);
        $current2 = $this->getCurrentEntryDataId($entryId2);

        $this->assertNotNull($current1);
        $this->assertNotNull($current2);
        $this->assertEquals($this->getLatestEntryDataId($entryId1), $current1);
        $this->assertEquals($this->getLatestEntryDataId($entryId2), $current2);
    }

    // ========================================
    // Test: Map Entry with Multiple Versions
    // ========================================

    public function testMapEntryWithMultipleVersions(): void
    {
        // Create an entry
        $entryId = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Version 1'])
        ], $this->testUserId);

        // Update multiple times
        $this->entriesManager->update($entryId, [
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Version 2'])
        ], $this->testUserId);

        $this->entriesManager->update($entryId, [
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Version 3'])
        ], $this->testUserId);

        $latestDataId = $this->getLatestEntryDataId($entryId);

        // Run mapping
        $this->mapCurrentEntriesCommand();

        // Verify current_entry_data_id points to latest version
        $currentDataId = $this->getCurrentEntryDataId($entryId);
        $this->assertEquals($latestDataId, $currentDataId);
    }

    // ========================================
    // Test: Idempotency
    // ========================================

    public function testIdempotency(): void
    {
        // Create an entry
        $entryId = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Test'])
        ], $this->testUserId);

        // Run mapping twice
        $this->mapCurrentEntriesCommand();
        $current1 = $this->getCurrentEntryDataId($entryId);

        $this->mapCurrentEntriesCommand();
        $current2 = $this->getCurrentEntryDataId($entryId);

        // Should have same result (idempotent)
        $this->assertEquals($current1, $current2);
    }

    // ========================================
    // Test: Empty Database
    // ========================================

    public function testMapWithNoEntries(): void
    {
        // Delete all entries
        $db = \Config\Database::connect();
        $db->table('entries')->truncate();

        // Run mapping - should not cause errors
        $this->mapCurrentEntriesCommand();

        // No assertion needed - just verify no exception
        $this->assertTrue(true);
    }

    // ========================================
    // Test: Soft-Deleted Entries
    // ========================================

    public function testSoftDeletedEntriesNotMapped(): void
    {
        // Create an entry
        $entryId = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'To Delete'])
        ], $this->testUserId);

        // Soft delete it
        $this->entriesManager->deleteEntries([$entryId], $this->testUserId);

        // Clear current mapping first
        $db = \Config\Database::connect();
        $db->table('entries')
            ->where('id', $entryId)
            ->update(['current_entry_data_id' => null]);

        // Run mapping
        $this->mapCurrentEntriesCommand();

        // Soft-deleted entry should not have mapping updated
        // (command WHERE clause filters out deleted_at IS NOT NULL)
        $currentDataId = $this->getCurrentEntryDataId($entryId);

        // Should still be null since it's soft-deleted
        $this->assertNull($currentDataId);
    }

    // ========================================
    // Test: Model Mapping
    // ========================================

    public function testMapModelCurrentVersion(): void
    {
        // Update the model to create history
        $this->modelsManager->update($this->testModelId, [
            'name' => 'Updated Model',
            'fields' => json_encode([
                ['id' => 'name', 'type' => 'text', 'label' => 'Name'],
                ['id' => 'extra', 'type' => 'text', 'label' => 'Extra']
            ])
        ], $this->testUserId);

        // Run mapping
        $this->mapCurrentEntriesCommand();

        // Verify current_model_data_id was set
        $db = \Config\Database::connect();
        $model = $db->table('models')
            ->where('id', $this->testModelId)
            ->get()
            ->getRowArray();

        $this->assertNotNull($model['current_model_data_id']);
        $this->assertGreaterThan(0, $model['current_model_data_id']);
    }

    // ========================================
    // Test: Both Entries and Models Mapped
    // ========================================

    public function testBothEntriesAndModelsMapped(): void
    {
        // Create entry
        $entryId = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Test Entry'])
        ], $this->testUserId);

        // Update both entry and model
        $this->entriesManager->update($entryId, [
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Updated Entry'])
        ], $this->testUserId);

        $this->modelsManager->update($this->testModelId, [
            'name' => 'Updated Model',
            'fields' => json_encode([
                ['id' => 'name', 'type' => 'text', 'label' => 'Name']
            ])
        ], $this->testUserId);

        // Run mapping
        $this->mapCurrentEntriesCommand();

        // Verify both are mapped
        $db = \Config\Database::connect();

        $entry = $db->table('entries')->where('id', $entryId)->get()->getRowArray();
        $model = $db->table('models')->where('id', $this->testModelId)->get()->getRowArray();

        $this->assertNotNull($entry['current_entry_data_id']);
        $this->assertNotNull($model['current_model_data_id']);
    }
}
