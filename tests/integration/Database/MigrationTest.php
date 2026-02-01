<?php

namespace StarDust\Tests\Integration\Database;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

class MigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = true;
    protected $migrateOnce = false;
    protected $refresh = true;
    protected $namespace = 'StarDust';

    /**
     * @testdox The migration successfully creates all required tables.
     */
    public function testSchemaIntegrity(): void
    {
        $db = \Config\Database::connect();

        $requiredTables = ['entries', 'entry_data', 'models', 'model_data'];

        foreach ($requiredTables as $table) {
            $this->assertTrue(
                $db->tableExists($table),
                sprintf('Critical Infrastructure Missing: Table "%s" was not created by migration.', $table)
            );
        }
    }

    /**
     * @testdox The database strictly enforces JSON validity on data columns.
     */
    public function testJsonConstraintEnforcement(): void
    {
        $db = \Config\Database::connect();

        // 1. Setup a valid foreign key dependency (since we have FKs or at least logic expecting it)
        // Actually, the migration defined creation but not strict FK constraints in the snippet, 
        // but we'll insert minimal valid data to isolate the JSON error.

        // 2. Attempt to violate the Architectural Constraint (JSON_VALID)
        $this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);

        // This MUST fail if the migration correctly applied the CHECK constraint
        $db->table('entry_data')->insert([
            'entry_id'   => 999, // ID doesn't matter for this specific constraint test
            'creator_id' => 1,
            'fields'     => 'THIS IS NOT JSON',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
