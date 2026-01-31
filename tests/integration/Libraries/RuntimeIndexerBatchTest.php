<?php

namespace StarDust\Tests\Integration\Libraries;

use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;
use StarDust\Libraries\RuntimeIndexer;

class RuntimeIndexerBatchTest extends CIUnitTestCase
{
    protected $indexer;
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the Database Connection
        $this->db = $this->createMock(\CodeIgniter\Database\BaseConnection::class);

        // Inject mock into RuntimeIndexer via reflection or partial mock
        // Since RuntimeIndexer uses \Config\Database::connect() in constructor,
        // we need to set the internal property via Reflection.
        try {
            $this->indexer = new RuntimeIndexer();
        } catch (\Throwable $e) {
            $this->indexer = (new \ReflectionClass(RuntimeIndexer::class))->newInstanceWithoutConstructor();
        }

        $reflection = new \ReflectionClass($this->indexer);
        $property = $reflection->getProperty('db');
        $property->setAccessible(true);
        $property->setValue($this->indexer, $this->db);
    }

    public function testSyncIndexesBatchesDDL()
    {
        // define 3 fields
        $fields = [
            ['id' => 'field1', 'type' => 'text'],
            ['id' => 'field2', 'type' => 'number'],
            ['id' => 'field3', 'type' => 'date'],
        ];

        // We expect ONE call to ALTER TABLE .. ADD COLUMN
        // and ONE call to ALTER TABLE .. ADD INDEX

        // Mock DB behavior: method 'query' should be called.
        // Since MockConnection is a simple mock, we might need to inspect its history or extend it.
        // Assuming we can't easily mock the exact query string validation without a proper mocking library like Mockery,
        // we rely on the specific implementation of MockConnection or create a dynamic mock.

        // Let's use PHPUnit's MockObject for the DB connection to be precise
        $mockDb = $this->createMock(\CodeIgniter\Database\BaseConnection::class);

        // Expectation: 
        // 1. ALTER TABLE ADD COLUMN ... ADD COLUMN ... ADD COLUMN
        // 2. ALTER TABLE ADD INDEX ... ADD INDEX ... ADD INDEX
        $mockDb->expects($this->exactly(2))->method('query')->with($this->callback(function ($sql) {
            // Simple validation that it looks like a batch statement
            if (strpos($sql, 'ADD COLUMN') !== false) {
                return substr_count($sql, 'ADD COLUMN') === 3 && strpos($sql, 'ALGORITHM=INSTANT') !== false;
            }
            if (strpos($sql, 'ADD INDEX') !== false) {
                return substr_count($sql, 'ADD INDEX') === 3 && strpos($sql, 'LOCK=NONE') !== false;
            }
            return false;
        }));

        // Re-inject the PHPUnit mock
        $reflection = new \ReflectionClass($this->indexer);
        $property = $reflection->getProperty('db');
        $property->setAccessible(true);
        $property->setValue($this->indexer, $mockDb);

        $this->indexer->syncIndexes($fields);
    }

    public function testSyncIndexesHandlesPartialOverlap()
    {
        // Scenario: field1 exists, field2 is new.
        // The RuntimeIndexer logic relies on IF NOT EXISTS in the SQL, 
        // OR the $existingColumns cache. 
        // If we don't pass $existingColumns, it should try to add BOTH but rely on IF NOT EXISTS.

        $fields = [
            ['id' => 'field1', 'type' => 'text'], // Assume this exists in DB but we don't know
            ['id' => 'field2', 'type' => 'number'],
        ];

        $mockDb = $this->createMock(\CodeIgniter\Database\BaseConnection::class);

        // It should still generate a batch for ALL fields because we didn't provide a cache saying field1 exists.
        // The SQL 'ADD COLUMN IF NOT EXISTS' handles the safety.
        $mockDb->expects($this->exactly(2))->method('query')->with($this->callback(function ($sql) {
            if (strpos($sql, 'ADD COLUMN') !== false) {
                return substr_count($sql, 'ADD COLUMN') === 2 &&
                    strpos($sql, 'IF NOT EXISTS') !== false &&
                    strpos($sql, 'ALGORITHM=INSTANT') !== false;
            }
            return true;
        }));

        $reflection = new \ReflectionClass($this->indexer);
        $property = $reflection->getProperty('db');
        $property->setAccessible(true);
        $property->setValue($this->indexer, $mockDb);

        $this->indexer->syncIndexes($fields);
    }

    public function testSyncIndexesWithCacheSkipsExisting()
    {
        $fields = [
            ['id' => 'field1', 'type' => 'text'],
            ['id' => 'field2', 'type' => 'number'],
        ];

        // Cache says field1 exists
        $existingColumns = ['v_field1_str' => true];

        $mockDb = $this->createMock(\CodeIgniter\Database\BaseConnection::class);

        // Should only try to add field2
        $mockDb->expects($this->exactly(2))->method('query')->with($this->callback(function ($sql) {
            if (strpos($sql, 'ADD COLUMN') !== false) {
                return substr_count($sql, 'ADD COLUMN') === 1 &&
                    strpos($sql, 'v_field2_num') !== false &&
                    strpos($sql, 'ALGORITHM=INSTANT') !== false;
            }
            return true;
        }));

        $reflection = new \ReflectionClass($this->indexer);
        $property = $reflection->getProperty('db');
        $property->setValue($this->indexer, $mockDb);

        $this->indexer->syncIndexes($fields, $existingColumns);

        // And cache should be updated
        $this->assertArrayHasKey('v_field2_num', $existingColumns);
    }
}
