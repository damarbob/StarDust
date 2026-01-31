<?php

namespace StarDust\Tests\Integration\Database\Migrations;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use StarDust\Database\Migrations\CreateAuthTablesPolyfill;

class AuthPolyfillTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false; // We handle migration manually
    protected $migrateOnce = false;
    protected $refresh = true;

    protected function setUp(): void
    {
        parent::setUp();
        // Manually load the migration file because PSR-4 doesn't handle timestamped filenames
        $root = dirname(__DIR__, 4);
        $file = $root . '/src/Database/Migrations/2025-01-01-000000_CreateAuthTablesPolyfill.php';

        // Ensure we handle Windows paths if needed, though PHP handles / check usually
        $file = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file);

        if (file_exists($file)) {
            require_once $file;
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Ensure we clean up the users table so we don't pollute other tests
        // Why manual cleanup? Because we manually load the migration file
        // AND the migration down() method does not drop the table.
        $forge = \Config\Database::forge();
        $config = config('StarDust');
        $forge->dropTable($config->usersTable, true);
    }

    public function testUpCreatesTableWhenMissing()
    {
        // 1. Ensure table does not exist
        $forge = \Config\Database::forge();
        $config = config('StarDust');
        $tableName = $config->usersTable;

        $forge->dropTable($tableName, true);

        $this->assertFalse($this->db->tableExists($tableName), 'Table should not exist initially');

        // 2. Run Migration
        $migration = new CreateAuthTablesPolyfill($forge);
        $migration->up();

        // 3. Assert Created
        $this->assertTrue($this->db->tableExists($tableName), 'Table should exist after migration');
        $this->assertTrue($this->db->fieldExists($config->usersIdColumn, $tableName));
        $this->assertTrue($this->db->fieldExists($config->usersUsernameColumn, $tableName));
    }

    public function testUpDoesNothingWhenExists()
    {
        // 1. Create table manually with different schema
        $forge = \Config\Database::forge();
        $config = config('StarDust');
        $tableName = $config->usersTable;

        $forge->dropTable($tableName, true);

        $forge->addField([
            'custom_id' => ['type' => 'INT', 'auto_increment' => true],
            'custom_user' => ['type' => 'VARCHAR', 'constraint' => 10],
        ]);
        $forge->addPrimaryKey('custom_id');
        $forge->createTable($tableName);

        $this->db->resetDataCache();

        $this->assertTrue($this->db->tableExists($tableName));
        $this->assertTrue($this->db->fieldExists('custom_user', $tableName));

        // 2. Run Migration
        $migration = new CreateAuthTablesPolyfill($forge);
        $migration->up();

        // 3. Assert NOT Modified (Standard columns NOT added)
        // If the migration ran, it would fail or try to add fields depending on logic.
        // But our logic says "return if exists".
        // So checking that original schema persists is proof.
        $this->assertTrue($this->db->fieldExists('custom_user', $tableName));
        $this->assertFalse($this->db->fieldExists($config->usersUsernameColumn, $tableName), 'Should not have added new column');
    }
}
