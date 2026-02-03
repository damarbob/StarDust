<?php

namespace StarDust\Tests\Integration;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Base test class for StarDust Integration Tests.
 *
 * Enforces:
 * 1. Database refreshing between tests.
 * 2. Seeding of a default User (ID 1) to satisfy Foreign Key constraints.
 */
abstract class StarDustTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $migrateOnce = true;
    protected $refresh = true;
    protected $namespace = 'StarDust';

    /**
     * @var int Default User ID for tests
     */
    protected int $testUserId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        // Polyfill migration does not drop the table in down(), so data persists.
        // We must manually clear tables to avoid "Duplicate entry" errors and test pollution.
        // Usage of truncate() (DDL) causes InnoDB Error 168 on Windows due to file locking.
        // We use emptyTable() (DML DELETE) instead, which is safer and works with $migrateOnce = true.
        $this->db->disableForeignKeyChecks();

        // Clear tables in reverse dependency order (though FK checks are off, it's good practice)
        $this->db->table('entry_data')->emptyTable();
        $this->db->table('entries')->emptyTable();
        $this->db->table('model_data')->emptyTable();
        $this->db->table('models')->emptyTable();
        $this->db->table('users')->emptyTable();

        $this->db->enableForeignKeyChecks();

        // Seed the default user
        // We use the Builder directly to bypass any Service logic/events that might trigger unrelated side effects
        $this->db->table('users')->insert([
            'id' => $this->testUserId,
            'username' => 'TestUser',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Reset Singletons to avoid test pollution
        \StarDust\Services\ModelsManager::resetInstance();
        \StarDust\Services\EntriesManager::resetInstance();
    }
}
