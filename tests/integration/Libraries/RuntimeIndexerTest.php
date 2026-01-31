<?php

namespace StarDust\Tests\Integration\Libraries;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Config\Factories;
use StarDust\Libraries\RuntimeIndexer;
use CodeIgniter\Database\BaseConnection;
use StarDust\Models\ModelsModel;
// We don't mock ModelsBuilder anymore, we use the real one implicitly.

class RuntimeIndexerTest extends CIUnitTestCase
{
    protected $dbMock;
    protected $indexer;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the Database Connection
        $this->dbMock = $this->createMock(BaseConnection::class);

        // Instantiate RuntimeIndexer without calling __construct (to avoid real DB connection)
        $this->indexer = $this->getMockBuilder(RuntimeIndexer::class)
            ->disableOriginalConstructor()
            ->onlyMethods([]) // Don't mock any methods of the class itself
            ->getMock();

        // Inject the mock DB into the protected $db property using Reflection
        $reflection = new \ReflectionClass($this->indexer);
        $property = $reflection->getProperty('db');
        $property->setValue($this->indexer, $this->dbMock);
    }

    public function testSyncIndexesCreatesColumnsForValidFields()
    {
        $fields = [
            ['id' => 'age', 'type' => 'number'],
            ['id' => 'name', 'type' => 'text'],
        ];

        // 1. Expect NO fieldExists checks (optimized out)
        $this->dbMock->expects($this->never())->method('fieldExists');

        // 2. Expect BATCH SQL queries (1 for columns, 1 for indexes)
        $callCount = 0;
        $this->dbMock->expects($this->exactly(2))
            ->method('query')
            ->willReturnCallback(function ($sql) use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    // Batch Column Creation
                    $this->assertStringContainsString('ALTER TABLE `entry_data`', $sql);
                    $this->assertStringContainsString('ADD COLUMN IF NOT EXISTS `v_age_num`', $sql);
                    $this->assertStringContainsString('ADD COLUMN IF NOT EXISTS `v_name_str`', $sql);
                } elseif ($callCount === 2) {
                    // Batch Index Creation
                    $this->assertStringContainsString('ALTER TABLE `entry_data`', $sql);
                    $this->assertStringContainsString('ADD INDEX IF NOT EXISTS `idx_v_age_num`', $sql);
                    $this->assertStringContainsString('ADD INDEX IF NOT EXISTS `idx_v_name_str`', $sql);
                }
                return true;
            });

        // 3. Transactions are NOT used in batch DDL (DDL = implicit commit)
        $this->dbMock->expects($this->never())->method('transStart');
        $this->dbMock->expects($this->never())->method('transComplete');

        $this->indexer->syncIndexes($fields);
    }

    public function testSyncIndexesSkipsExistingColumns()
    {
        $fields = [
            ['id' => 'age', 'type' => 'number'],
        ];

        // 1. Simulate column ALREADY exists in DB (passed via cache mostly, or blindly trusted)
        // Since the code says "We skip this check here and rely on IF NOT EXISTS", we expect NO fieldExists calls
        $this->dbMock->expects($this->never())->method('fieldExists');

        // 2. Expect queries because we don't check DB anymore, we just run IF NOT EXISTS
        // So this test confirms that it attempts to create it safely.
        $this->dbMock->expects($this->atLeastOnce())->method('query');

        $this->indexer->syncIndexes($fields);
    }

    public function testSyncIndexesUsesCacheIdeally()
    {
        $fields = [
            ['id' => 'age', 'type' => 'number'],
        ];

        $cache = ['v_age_num' => true]; // Cache says it exists

        // 1. Should NOT call DB fieldExists because cache has it
        $this->dbMock->expects($this->never())->method('fieldExists');
        $this->dbMock->expects($this->never())->method('query');

        $this->indexer->syncIndexes($fields, $cache);
    }

    public function testSyncIndexesUpdatesCache()
    {
        $fields = [
            ['id' => 'point', 'type' => 'number'],
        ];

        $cache = []; // Empty cache

        $this->dbMock->method('fieldExists')->willReturn(false);

        $this->indexer->syncIndexes($fields, $cache);

        $this->assertArrayHasKey('v_point_num', $cache);
        $this->assertTrue($cache['v_point_num']);
    }

    public function testSyncIndexesSkipsRestrictedTypes()
    {
        $fields = [
            ['id' => 'pass', 'type' => 'password'],
            ['id' => 'desc', 'type' => 'textarea'],
            ['id' => 'options', 'type' => 'checkboxes'],
            ['id' => 'attachment', 'type' => 'file'],
        ];

        // Should check NOTHING in DB because strict skip rules apply first
        $this->dbMock->expects($this->never())->method('fieldExists');
        $this->dbMock->expects($this->never())->method('query');

        $this->indexer->syncIndexes($fields);
    }

    public function testIndexAllModels()
    {
        // 1. Use REAL ModelsModel with injected Mock DB
        $realModelsModel = new ModelsModel();

        // Inject DB into Model
        $reflection = new \ReflectionClass($realModelsModel);
        $prop = $reflection->getProperty('db');
        $prop->setValue($realModelsModel, $this->dbMock);

        // Also inject into builder property if needed, but builder() method will use $this->db to create new builder.
        // And stardust() creates new ModelsBuilder($table, $this->db).
        // So injection into $this->db is sufficient.

        // Register in Factories
        Factories::injectMock('models', 'StarDust\Models\ModelsModel', $realModelsModel);

        // 2. Mock DB Queries
        // We have multiple query calls:
        // A. getFieldNames for initial columns.
        // B. ModelsBuilder query (SELECT * FROM models ...)
        // C. syncIndexes ALTER/CREATE calls.

        // Setup getFieldNames
        $this->dbMock->method('getFieldNames')
            ->with('entry_data')
            ->willReturn(['id', 'params', 'v_old_str']);

        // Setup Result for Models Query
        $mockResult = $this->createMock(\CodeIgniter\Database\ResultInterface::class);
        $mockResult->method('getResultArray')->willReturn([
            [
                'id' => 'model_users',
                'fields' => json_encode([
                    ['id' => 'age', 'type' => 'number']
                ])
            ]
        ]);

        // The ModelsBuilder will call $this->db->query() or compileSelect and run it.
        // We need to capture the SELECT query.
        $this->dbMock->method('escape')->willReturnCallback(function ($v) {
            return "'$v'";
        }); // Basic escape mock

        // We need to match calls.

        $this->dbMock->method('query')
            ->willReturnCallback(function ($sql) use ($mockResult) {
                // Return mock result for SELECT queries (from ModelsModel)
                if (stripos($sql, 'SELECT') !== false && stripos($sql, 'models') !== false) {
                    return $mockResult;
                }
                // For DDL, return true or null (void)
                return true;
            });

        // 3. We also need to mock protectIdentifiers if Builder uses it (it does).
        $this->dbMock->method('protectIdentifiers')->willReturnCallback(function ($item) {
            return "`$item`";
        });

        $stats = $this->indexer->indexAllModels();

        $this->assertEquals(1, $stats['models_processed']);
        $this->assertEquals(1, $stats['columns_created']);
    }

    public function testFindOrphanedColumns()
    {
        // 1. Real Model
        $realModelsModel = new ModelsModel();
        $reflection = new \ReflectionClass($realModelsModel);
        $prop = $reflection->getProperty('db');
        $prop->setValue($realModelsModel, $this->dbMock);
        Factories::injectMock('models', 'StarDust\Models\ModelsModel', $realModelsModel);

        // 2. Mock DB
        $this->dbMock->method('getFieldNames')
            ->with('entry_data')
            ->willReturn(['id', 'v_active_num', 'v_orphan_num', 'other_col']);

        $this->dbMock->method('escape')->willReturnCallback(function ($v) {
            return "'$v'";
        });
        $this->dbMock->method('protectIdentifiers')->willReturnCallback(function ($item) {
            return "`$item`";
        });

        $mockResult = $this->createMock(\CodeIgniter\Database\ResultInterface::class);
        $mockResult->method('getResultArray')->willReturn([
            [
                'id' => 'user',
                'fields' => json_encode([
                    ['id' => 'active', 'type' => 'number']
                ])
            ]
        ]);

        $this->dbMock->method('query')
            ->willReturnCallback(function ($sql) use ($mockResult) {
                if (stripos($sql, 'SELECT') !== false) {
                    return $mockResult;
                }
                return true;
            });

        $orphans = $this->indexer->findOrphanedColumns();

        $this->assertCount(1, $orphans);
        $this->assertContains('v_orphan_num', $orphans);
        $this->assertNotContains('v_active_num', $orphans);
    }

    public function testRemoveOrphanedColumns()
    {
        // 1. Valid columns
        $cols = ['v_old_num'];

        $this->dbMock->expects($this->once())->method('transStart');

        // SQLs: DROP INDEX ..., DROP COLUMN ...
        // We use query callback in other tests, but here we expect calls.
        // Ideally we should be consistent. If we configure 'query' method with willReturnCallback multiple times in setUp, it persists?
        // No, we configure in each test.

        $this->dbMock->expects($this->exactly(2))->method('query');

        $this->dbMock->expects($this->once())->method('transComplete');
        $this->dbMock->method('transStatus')->willReturn(true);

        $result = $this->indexer->removeOrphanedColumns($cols);

        $this->assertContains('v_old_num', $result['success']);
        $this->assertEmpty($result['failed']);
    }

    public function testRemoveOrphanedColumnsPreventsInjection()
    {
        $cols = ['v_old_num; DROP TABLE users;'];

        // Should NOT call DB
        $this->dbMock->expects($this->never())->method('query');

        $result = $this->indexer->removeOrphanedColumns($cols);

        $this->assertArrayHasKey('v_old_num; DROP TABLE users;', $result['failed']);
        $this->assertStringContainsString('Invalid column name', $result['failed']['v_old_num; DROP TABLE users;']);
    }

    public function testGetAllVirtualColumns()
    {
        $this->dbMock->method('getFieldNames')
            ->with('entry_data')
            ->willReturn(['id', 'v_active_num', 'created_at', 'v_orphan_str']);

        $columns = $this->indexer->getAllVirtualColumns();

        $this->assertCount(2, $columns);
        $this->assertContains('v_active_num', $columns);
        $this->assertContains('v_orphan_str', $columns);
        $this->assertNotContains('id', $columns);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Factories::reset();
    }
}
