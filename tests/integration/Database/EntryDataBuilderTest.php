<?php

namespace StarDust\Tests\Integration\Database;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use StarDust\Models\EntryDataModel;
use StarDust\Services\EntriesManager;
use StarDust\Services\ModelsManager;

/**
 * Test suite for EntryDataBuilder class
 *
 * Tests query building methods including:
 * - Join methods (single and chained)
 * - Select methods
 * - Where conditions (active)
 * - Default method combinations
 *
 * @internal
 */
class EntryDataBuilderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $migrateOnce = false;
    protected $refresh = true;
    protected $namespace = 'StarDust';

    private EntryDataModel $entryDataModel;
    private EntriesManager $entriesManager;
    private ModelsManager $modelsManager;
    private int $testModelId;
    private int $testUserId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entryDataModel = new EntryDataModel();
        $this->entriesManager = EntriesManager::getInstance();
        $this->modelsManager = ModelsManager::getInstance();

        // Create a test model for entries to use
        $this->testModelId = $this->modelsManager->create([
            'name' => 'Test Model',
            'fields' => json_encode([
                ['id' => 'name', 'type' => 'text', 'label' => 'Name'],
                ['id' => 'email', 'type' => 'email', 'label' => 'Email']
            ])
        ], $this->testUserId);
    }

    // ========================================
    // DEFAULT Method Tests
    // ========================================

    public function testDefault(): void
    {
        $builder = $this->entryDataModel->builder()->default();
        $query = $builder->getCompiledSelect();

        // Should include all selects and joins
        $this->assertStringContainsStringIgnoringCase('`entry_data`.`id`', $query);
        $this->assertStringContainsStringIgnoringCase('entries', $query);
        $this->assertStringContainsStringIgnoringCase('models', $query);
        $this->assertStringContainsStringIgnoringCase('model_data', $query);
    }

    // ========================================
    // SELECT Methods Tests
    // ========================================

    public function testSelectEntry(): void
    {
        $builder = $this->entryDataModel->builder()->selectEntry();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('`entries`.`id` as `entry_id`', $query);
    }

    public function testSelectEntryData(): void
    {
        $builder = $this->entryDataModel->builder()->selectEntryData();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('`entry_data`.`id`', $query);
        $this->assertStringContainsStringIgnoringCase('`entry_data`.`fields`', $query);
        $this->assertStringContainsStringIgnoringCase('`created_at` AS `date_created`', $query);
    }

    public function testSelectModelData(): void
    {
        $builder = $this->entryDataModel->builder()->selectModelData();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('`model_data`.`model_id` as `model_id`', $query);
        $this->assertStringContainsStringIgnoringCase('`model_data`.`name` as `model_name`', $query);
    }

    public function testSelectUsers(): void
    {
        $builder = $this->entryDataModel->builder()->selectUsers();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('`users`.`username` AS `created_by`', $query);
    }

    public function testSelectDefault(): void
    {
        $builder = $this->entryDataModel->builder()->selectDefault();
        $query = $builder->getCompiledSelect();

        // Should include all select methods
        $this->assertStringContainsStringIgnoringCase('`entries`.`id`', $query);
        $this->assertStringContainsStringIgnoringCase('`entry_data`.`fields`', $query);
        $this->assertStringContainsStringIgnoringCase('`model_data`.`name`', $query);
        $this->assertStringContainsStringIgnoringCase('`users`.`username` AS `created_by`', $query);
    }

    // ========================================
    // JOIN Methods Tests
    // ========================================

    public function testJoinEntries(): void
    {
        $builder = $this->entryDataModel->builder()->joinEntries();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('LEFT JOIN', $query);
        $this->assertStringContainsStringIgnoringCase('entries', $query);
        $this->assertStringContainsStringIgnoringCase('entry_id', $query);
    }

    public function testJoinCreator(): void
    {
        $builder = $this->entryDataModel->builder()->joinCreator();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('LEFT JOIN', $query);
        $this->assertStringContainsStringIgnoringCase('users', $query);
        $this->assertStringContainsStringIgnoringCase('creator_id', $query);
    }

    public function testJoinModels(): void
    {
        $builder = $this->entryDataModel->builder()->joinModels();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('LEFT JOIN', $query);
        $this->assertStringContainsStringIgnoringCase('models', $query);
        $this->assertStringContainsStringIgnoringCase('model_id', $query);
    }

    public function testJoinModelData(): void
    {
        $builder = $this->entryDataModel->builder()->joinModelData();
        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('LEFT JOIN', $query);
        $this->assertStringContainsStringIgnoringCase('model_data', $query);
        $this->assertStringContainsStringIgnoringCase('current_model_data_id', $query);
    }

    public function testJoinDefault(): void
    {
        $builder = $this->entryDataModel->builder()->joinDefault();
        $query = $builder->getCompiledSelect();

        // Should include all join methods
        $this->assertStringContainsStringIgnoringCase('entries', $query);
        $this->assertStringContainsStringIgnoringCase('users', $query);
        $this->assertStringContainsStringIgnoringCase('models', $query);
        $this->assertStringContainsStringIgnoringCase('model_data', $query);
    }

    // ========================================
    // WHERE Methods Tests
    // ========================================

    public function testWhereActive(): void
    {
        $builder = $this->entryDataModel->builder()->whereActive();
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
        $builder = $this->entryDataModel->builder();

        $result = $builder->selectEntry();
        $this->assertSame($builder, $result);

        $result = $builder->joinEntries();
        $this->assertSame($builder, $result);

        $result = $builder->whereActive();
        $this->assertSame($builder, $result);
    }

    public function testComplexMethodChaining(): void
    {
        $builder = $this->entryDataModel->builder()
            ->selectEntry()
            ->selectEntryData()
            ->joinEntries()
            ->joinModels()
            ->whereActive();

        $query = $builder->getCompiledSelect();

        $this->assertStringContainsStringIgnoringCase('`entries`.`id`', $query);
        $this->assertStringContainsStringIgnoringCase('`entry_data`.`fields`', $query);
        $this->assertStringContainsStringIgnoringCase('entries', $query);
        $this->assertStringContainsStringIgnoringCase('models', $query);
        $this->assertStringContainsStringIgnoringCase('deleted_at', $query);
    }

    // ========================================
    // Integration Tests with Real Data
    // ========================================

    public function testDefaultMethodWithActiveEntryData(): void
    {
        // Create test entries
        $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Test Entry 1'])
        ], $this->testUserId);

        $this->entriesManager->create([
            'model_id' => $this->testModelId,
            'fields' => json_encode(['name' => 'Test Entry 2'])
        ], $this->testUserId);

        $results = $this->entryDataModel->builder()
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

    public function testJoinOrderDependency(): void
    {
        // Test that joinModelData requires joinModels
        $builder = $this->entryDataModel->builder()
            ->joinEntries()
            ->joinModels()
            ->joinModelData();

        $query = $builder->getCompiledSelect();
        $this->assertStringContainsString('LEFT JOIN `entries`', $query);
        $this->assertStringContainsString('LEFT JOIN `models`', $query);
        $this->assertStringContainsString('LEFT JOIN `model_data`', $query);
    }

    // ========================================
    // Edge Cases and Error Handling
    // ========================================

    public function testMultipleWhereConditions(): void
    {
        $builder = $this->entryDataModel->builder()
            ->whereActive()
            ->where('entry_data.id >', 0);

        $query = $builder->getCompiledSelect();
        $this->assertStringContainsStringIgnoringCase('WHERE', $query);
        $this->assertStringContainsStringIgnoringCase('deleted_at', $query);
        $this->assertStringContainsStringIgnoringCase('`entry_data`.`id`', $query);
    }

    public function testBuilderCanBeReused(): void
    {
        $builder = $this->entryDataModel->builder();

        // First query
        $query1 = $builder->selectEntryData()->getCompiledSelect(false);

        // Second query with additional conditions
        $query2 = $builder->where('entry_data.id', 1)->getCompiledSelect();

        $this->assertNotEquals($query1, $query2);
        $this->assertStringContainsString('`entry_data`.`id`', $query1);
        $this->assertStringContainsString('WHERE', $query2);
    }
}
