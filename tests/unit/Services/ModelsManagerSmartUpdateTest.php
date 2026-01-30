<?php

namespace StarDust\Tests\Unit\Services;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use StarDust\Services\ModelsManager;

/**
 * Test suite specifically for the new "Smart Update" (Merge) logic
 */
class ModelsManagerSmartUpdateTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $migrateOnce = false;
    protected $refresh = true;
    protected $namespace = 'StarDust';

    private ModelsManager $modelsManager;
    private int $testUserId = 1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->modelsManager = ModelsManager::getInstance();
    }

    public function testPartialUpdateNamePreservesFields(): void
    {
        // 1. Create a model with fields
        $modelId = $this->modelsManager->create([
            'name' => 'Original Name',
            'fields' => json_encode([
                ['id' => 'field1', 'type' => 'text']
            ])
        ], $this->testUserId);

        // 2. Update ONLY the name
        $this->modelsManager->update($modelId, [
            'name' => 'New Name'
        ], $this->testUserId);

        // 3. Verify
        $model = $this->modelsManager->find($modelId);

        // Name should be updated
        $this->assertEquals('New Name', $model['name']);

        // Fields should be PRESERVED (not null, not empty)
        $this->assertNotEmpty($model['fields']);
        $fields = json_decode($model['fields'], true);
        $this->assertCount(1, $fields);
        $this->assertEquals('field1', $fields[0]['id']);
    }

    public function testPartialUpdateFieldsPreservesName(): void
    {
        // 1. Create a model
        $modelId = $this->modelsManager->create([
            'name' => 'Preserve Me',
            'fields' => json_encode([
                ['id' => 'field1', 'type' => 'text']
            ])
        ], $this->testUserId);

        // 2. Update ONLY the fields
        $this->modelsManager->update($modelId, [
            'fields' => json_encode([
                ['id' => 'field1', 'type' => 'text'],
                ['id' => 'field2', 'type' => 'number']
            ])
        ], $this->testUserId);

        // 3. Verify
        $model = $this->modelsManager->find($modelId);

        // Name should be preserved
        $this->assertEquals('Preserve Me', $model['name']);

        // Fields should be updated
        $fields = json_decode($model['fields'], true);
        $this->assertCount(2, $fields);
    }

    public function testSmartUpdateCreatesNewVersion(): void
    {
        $modelId = $this->modelsManager->create([
            'name' => 'v1',
            'fields' => json_encode([])
        ], $this->testUserId);

        $v1Model = $this->modelsManager->find($modelId);
        $v1DataId = $v1Model['data_id'];

        // Update
        $this->modelsManager->update($modelId, ['name' => 'v2'], $this->testUserId);

        $v2Model = $this->modelsManager->find($modelId);
        $v2DataId = $v2Model['data_id'];

        $this->assertNotEquals($v1DataId, $v2DataId);
    }

    public function testPaginateReturnsArray(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->modelsManager->create(['name' => "Model $i", 'fields' => json_encode([])], $this->testUserId);
        }

        // Test Page 1 (default 20)
        $page1 = $this->modelsManager->paginate(1, 20);
        $this->assertCount(20, $page1);

        // Test Page 2 (remaining 5)
        $page2 = $this->modelsManager->paginate(2, 20);
        $this->assertCount(5, $page2);
    }

    public function testInvalidFieldsThrowsException(): void
    {
        $modelId = $this->modelsManager->create(['name' => 'Valid', 'fields' => json_encode([])], $this->testUserId);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid field definition. Each field must have an 'id' and 'type'.");

        $this->modelsManager->update($modelId, [
            'fields' => json_encode([
                ['id' => 'missing_type'] // Invalid: missing 'type'
            ])
        ], $this->testUserId);
    }
}
