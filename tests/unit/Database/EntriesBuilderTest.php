<?php

namespace StarDust\Tests\Unit\Database;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use StarDust\Models\EntriesModel;
use StarDust\Services\EntriesManager;
use StarDust\Services\ModelsManager;

/**
 * Test suite for EntriesBuilder class
 *
 * Tests query building methods including:
 * - Join methods (single and chained)
 * - Select methods
 * - Where conditions (active/deleted)
 * - Default method combinations
 * - Custom likeFields method (including SQL injection protection)
 *
 * @internal
 */
class EntriesBuilderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $migrateOnce = false;
    protected $refresh = true;
    protected $namespace = 'StarDust';

    private EntriesModel $entriesModel;
    private EntriesManager $entriesManager;
    private ModelsManager $modelsManager;
    private int $testModelId;
    private int $testUserId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entriesModel = new EntriesModel();
        $this->entriesManager = EntriesManager::getInstance();
        $this->modelsManager = ModelsManager::getInstance();

        // Create a test model for entries to use
        $this->testModelId = $this->modelsManager->create([
            'name' => 'Test Model',
            'fields' => json_encode([
                ['id' => 'name', 'type' => 'text', 'label' => 'Name'],
                ['id' => 'email', 'type' => 'email', 'label' => 'Email'],
                ['id' => 'phone', 'type' => 'text', 'label' => 'Phone']
            ])
        ], $this->testUserId);
    }

    // ========================================
    // DEFAULT Method Tests
    // ========================================

    public function testDefault(): void
    {
        $builder = $this->entriesModel->builder()->default();
        $query = $builder->getCompiledSelect();

        // Should include all selects and joins
        $this->assertStringContainsStringIgnoringCase('`entries`.`id`', $query);
        $this->assertStringContainsStringIgnoringCase('entry_data', $query);
        $this->assertStringContainsStringIgnoringCase('models', $query);
    }

    // ========================================
    // SELECT Methods Tests
    // ========================================

    public function testSelectEntry(): void
    {
        $builder = $this->entriesModel->builder()->selectEntry();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('`entries`.`id`', $query);
        $this->assertStringContainsStringIgnoringCase('`entries`.`created_at`', $query);
        $this->assertStringContainsStringIgnoringCase('`deleted_at` AS `date_deleted`', $query);
    }

    public function testSelectModelData(): void
    {
        $builder = $this->entriesModel->builder()->selectModelData();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('`model_data`.`model_id`', $query);
        $this->assertStringContainsStringIgnoringCase('`model_data`.`name` as `model_name`', $query);
        $this->assertStringContainsStringIgnoringCase('`model_data`.`fields` AS `model_fields`', $query);
        $this->assertStringContainsStringIgnoringCase('`model_data`.`user_groups`', $query);
    }

    public function testSelectEntryData(): void
    {
        $builder = $this->entriesModel->builder()->selectEntryData();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('`entry_data`.`fields`', $query);
        $this->assertStringContainsStringIgnoringCase('`created_at` AS `date_modified`', $query);
        $this->assertStringContainsStringIgnoringCase('`entry_data`.`id` as `data_id`', $query);
    }

    public function testSelectUsers(): void
    {
        $builder = $this->entriesModel->builder()->selectUsers();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('`users`.`username` AS `created_by`', $query);
        $this->assertStringContainsStringIgnoringCase('`editors`.`username` AS `edited_by`', $query);
        $this->assertStringContainsStringIgnoringCase('`deleters`.`username` AS `deleted_by`', $query);
    }

    public function testSelectDefault(): void
    {
        $builder = $this->entriesModel->builder()->selectDefault();
        $query = $builder->getCompiledSelect();

        // Should include all select methods
        $this->assertStringContainsStringIgnoringCase('`entries`.`id`', $query);
        $this->assertStringContainsStringIgnoringCase('`model_data`.`name`', $query);
        $this->assertStringContainsStringIgnoringCase('`entry_data`.`fields`', $query);
        $this->assertStringContainsStringIgnoringCase('`users`.`username` AS `created_by`', $query);
    }

    // ========================================
    // JOIN Methods Tests
    // ========================================

    public function testJoinEntryData(): void
    {
        $builder = $this->entriesModel->builder()->joinEntryData();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('LEFT JOIN', $query);
        $this->assertStringContainsStringIgnoringCase('entry_data', $query);
        $this->assertStringContainsStringIgnoringCase('current_entry_data_id', $query);
    }

    public function testJoinModel(): void
    {
        $builder = $this->entriesModel->builder()->joinModel();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('LEFT JOIN', $query);
        $this->assertStringContainsStringIgnoringCase('models', $query);
        $this->assertStringContainsStringIgnoringCase('model_id', $query);
    }

    public function testJoinModelData(): void
    {
        $builder = $this->entriesModel->builder()->joinModelData();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('LEFT JOIN', $query);
        $this->assertStringContainsStringIgnoringCase('model_data', $query);
        $this->assertStringContainsStringIgnoringCase('current_model_data_id', $query);
    }

    public function testJoinCreator(): void
    {
        $builder = $this->entriesModel->builder()->joinCreator();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('LEFT JOIN', $query);
        $this->assertStringContainsStringIgnoringCase('users', $query);
        $this->assertStringContainsStringIgnoringCase('creator_id', $query);
    }

    public function testJoinEditor(): void
    {
        $builder = $this->entriesModel->builder()->joinEditor();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('LEFT JOIN', $query);
        $this->assertStringContainsStringIgnoringCase('editors', $query);
        $this->assertStringContainsStringIgnoringCase('`entry_data`.`creator_id`', $query);
    }

    public function testJoinDeleter(): void
    {
        $builder = $this->entriesModel->builder()->joinDeleter();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('LEFT JOIN', $query);
        $this->assertStringContainsStringIgnoringCase('deleters', $query);
        $this->assertStringContainsStringIgnoringCase('deleter_id', $query);
    }

    public function testJoinDefault(): void
    {
        $builder = $this->entriesModel->builder()->joinDefault();
        $query = $builder->getCompiledSelect();

        // Should include all join methods
        $this->assertStringContainsStringIgnoringCase('entry_data', $query);
        $this->assertStringContainsStringIgnoringCase('models', $query);
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
        $builder = $this->entriesModel->builder()->whereActive();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('WHERE', $query);
        $this->assertStringContainsStringIgnoringCase('deleted_at', $query);
        $this->assertStringContainsStringIgnoringCase('IS NULL', $query);
    }

    public function testWhereDeleted(): void
    {
        $builder = $this->entriesModel->builder()->whereDeleted();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('WHERE', $query);
        $this->assertStringContainsStringIgnoringCase('deleted_at IS NOT NULL', $query);
    }

    // ========================================
    // Method Chaining Tests
    // ========================================

    public function testMethodChainingReturnsBuilder(): void
    {
        $builder = $this->entriesModel->builder();

        $result = $builder->selectEntry();
        $this->assertSame($builder, $result);

        $result = $builder->joinEntryData();
        $this->assertSame($builder, $result);

        $result = $builder->whereActive();
        $this->assertSame($builder, $result);
    }

    public function testComplexMethodChaining(): void
    {
        $builder = $this->entriesModel->builder()
            ->selectEntry()
            ->selectEntryData()
            ->joinEntryData()
            ->joinModel()
            ->whereActive();

        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('`entries`.`id`', $query);
        $this->assertStringContainsStringIgnoringCase('`entry_data`.`fields`', $query);
        $this->assertStringContainsStringIgnoringCase('entry_data', $query);
        $this->assertStringContainsStringIgnoringCase('models', $query);
        $this->assertStringContainsStringIgnoringCase('deleted_at', $query);
    }

    // ========================================
    // likeFields() Method Tests
    // ========================================

    public function testLikeFieldsSingleCondition(): void
    {
        $builder = $this->entriesModel->builder()->likeFields([
            ['field' => 'name', 'value' => 'John']
        ]);

        $query = $builder->getCompiledSelect();

        // Just verify the query compiles without errors and contains key SQL functions
        $this->assertStringContainsStringIgnoringCase('JSON_EXTRACT', $query);
        $this->assertStringContainsStringIgnoringCase('LIKE', $query);
        $this->assertStringContainsStringIgnoringCase('john', $query);
    }

    public function testLikeFieldsMultipleConditions(): void
    {
        $builder = $this->entriesModel->builder()->likeFields([
            ['field' => 'name', 'value' => 'John'],
            ['field' => 'email', 'value' => 'example.com']
        ]);

        $query = $builder->getCompiledSelect();

        // Verify it contains both search values (case-insensitive since we lowercase them)
        $this->assertStringContainsStringIgnoringCase('john', $query);
        $this->assertStringContainsStringIgnoringCase('example.com', $query);
    }

    public function testLikeFieldsCaseInsensitive(): void
    {
        // Create test entries
        $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'JOHN DOE', 'email' => 'john@example.com'])
        ], $this->testUserId);

        $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'jane smith', 'email' => 'jane@example.com'])
        ], $this->testUserId);

        // Search with lowercase
        $results = $this->entriesModel->builder()
            ->default()
            ->whereActive()
            ->likeFields([['field' => 'name', 'value' => 'john']])
            ->get()
            ->getResultArray();

        $this->assertCount(1, $results);
        $this->assertStringContainsString('JOHN DOE', json_decode($results[0]['fields'], true)['name']);
    }

    public function testLikeFieldsWithSpecialCharacters(): void
    {
        // Create entry with special characters
        $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => "O'Brien", 'email' => 'obrien@example.com'])
        ], $this->testUserId);

        // Search should handle quotes properly
        $results = $this->entriesModel->builder()
            ->default()
            ->whereActive()
            ->likeFields([['field' => 'name', 'value' => "O'Brien"]])
            ->get()
            ->getResultArray();

        $this->assertCount(1, $results);
    }

    public function testLikeFieldsEmptyValue(): void
    {
        $builder = $this->entriesModel->builder()->likeFields([
            ['field' => 'name', 'value' => '']
        ]);

        $query = $builder->getCompiledSelect();
        // Just verify it compiles without errors
        $this->assertIsString($query);
        $this->assertStringContainsStringIgnoringCase('LIKE', $query);
    }

    /**
     * Test SQL injection protection in likeFields
     * 
     * This test verifies that malicious input is properly escaped
     * and doesn't break the query or allow SQL injection.
     */
    public function testLikeFieldsSQLInjectionProtection(): void
    {
        // Create a normal entry
        $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Normal User', 'email' => 'normal@example.com'])
        ], $this->testUserId);

        // Attempt SQL injection in value
        $maliciousValue = "' OR '1'='1";

        $results = $this->entriesModel->builder()
            ->default()
            ->whereActive()
            ->likeFields([['field' => 'name', 'value' => $maliciousValue]])
            ->get()
            ->getResultArray();

        // Should return no results (not all entries)
        $this->assertCount(0, $results, 'SQL injection attempt should not return results');
    }

    public function testLikeFieldsWithDifferentFieldNames(): void
    {
        // Create entries with different field values
        $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Alice', 'email' => 'alice@example.com', 'phone' => '123-456'])
        ], $this->testUserId);

        $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Bob', 'email' => 'bob@test.com', 'phone' => '789-012'])
        ], $this->testUserId);

        // Search by email field
        $results = $this->entriesModel->builder()
            ->default()
            ->whereActive()
            ->likeFields([['field' => 'email', 'value' => 'example.com']])
            ->get()
            ->getResultArray();

        $this->assertCount(1, $results);
        $this->assertStringContainsString('Alice', json_decode($results[0]['fields'], true)['name']);
    }

    // ========================================
    // Integration Tests with Real Data
    // ========================================

    public function testDefaultMethodWithActiveEntries(): void
    {
        // Create test entries
        $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Active Entry 1'])
        ], $this->testUserId);

        $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Active Entry 2'])
        ], $this->testUserId);

        $results = $this->entriesModel->builder()
            ->default()
            ->whereActive()
            ->get()
            ->getResultArray();

        $this->assertCount(2, $results);
        $this->assertArrayHasKey('id', $results[0]);
        $this->assertArrayHasKey('fields', $results[0]);
        $this->assertArrayHasKey('model_name', $results[0]);
        $this->assertArrayHasKey('created_by', $results[0]);
    }

    public function testDefaultMethodWithDeletedEntries(): void
    {
        // Create and delete an entry
        $entryId = $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Deleted Entry'])
        ], $this->testUserId);

        $this->entriesManager->deleteEntries([$entryId], $this->testUserId);

        // Query deleted entries
        $results = $this->entriesModel->builder()
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
        // Test that joinEditor requires joinEntryData
        // This should work since entries already has entry_data reference
        $builder = $this->entriesModel->builder()
            ->joinEntryData()
            ->joinEditor();

        $query = $builder->getCompiledSelect();
        $this->assertStringContainsString('LEFT JOIN `entry_data`', $query);
        $this->assertStringContainsString('LEFT JOIN `users` as `editors`', $query);
    }

    public function testSelectWithoutJoinStillCompiles(): void
    {
        // Selecting from tables without joining should still compile
        // (though it won't return meaningful data)
        $builder = $this->entriesModel->builder()
            ->selectEntry()
            ->selectModelData();

        $query = $builder->getCompiledSelect();
        $this->assertStringContainsStringIgnoringCase('SELECT', $query);
        $this->assertStringContainsStringIgnoringCase('`entries`.`id`', $query);
        $this->assertStringContainsStringIgnoringCase('`model_data`.`name`', $query);
    }

    // ========================================
    // Edge Cases and Error Handling
    // ========================================

    public function testLikeFieldsWithEmptyArray(): void
    {
        $builder = $this->entriesModel->builder()->likeFields([]);
        $query = $builder->getCompiledSelect();

        // Should still compile without errors
        $this->assertIsString($query);
    }

    public function testMultipleWhereConditions(): void
    {
        $builder = $this->entriesModel->builder()
            ->whereActive()
            ->where('entries.id >', 0);

        $query = $builder->getCompiledSelect();
        $this->assertStringContainsStringIgnoringCase('WHERE', $query);
        $this->assertStringContainsStringIgnoringCase('deleted_at', $query);
        $this->assertStringContainsStringIgnoringCase('`entries`.`id`', $query);
    }

    public function testBuilderCanBeReused(): void
    {
        $builder = $this->entriesModel->builder();

        // First query
        $query1 = $builder->selectEntry()->getCompiledSelect(false);

        // Second query with additional conditions
        $query2 = $builder->where('entries.id', 1)->getCompiledSelect();

        $this->assertNotEquals($query1, $query2);
        $this->assertStringContainsString('`entries`.`id`', $query1);
        $this->assertStringContainsString('WHERE', $query2);
    }
}
