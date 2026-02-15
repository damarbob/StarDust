<?php

namespace StarDust\Tests\Integration\Database;

use StarDust\Tests\Integration\StarDustTestCase;

class MigrationTest extends StarDustTestCase
{
    // Inherits SafeMigrationTrait from StarDustTestCase

    protected $migrate = true;
    protected $migrateOnce = false; // Always migrate for this test to verify structure
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
    /**
     * @testdox The migration successfully creates performance indexes.
     */
    public function testPerformanceIndexesExist(): void
    {
        $db = \Config\Database::connect();
        $db->resetDataCache();


        $expectedIndexes = [
            'entries'    => ['idx_entries_model_history', 'idx_entries_deleted_at'],
            'entry_data' => ['idx_entry_data_history'],
            'model_data' => ['idx_model_data_history'],
        ];

        foreach ($expectedIndexes as $table => $indexes) {
            $existingIndexes = $db->getIndexData($table);
            $existingIndexNames = array_map(function ($index) {
                return $index->name;
            }, $existingIndexes);

            foreach ($indexes as $indexName) {
                $this->assertContains(
                    $indexName,
                    $existingIndexNames,
                    sprintf('Index "%s" is missing from table "%s".', $indexName, $table)
                );
            }
        }
    }

    /**
     * @testdox The migration successfully creates current version columns and indexes.
     */
    public function testCurrentVersionColumnsExist(): void
    {
        $db = \Config\Database::connect();
        $db->resetDataCache();

        $expectedColumns = [
            'entries' => 'current_entry_data_id',
            'models'  => 'current_model_data_id',
        ];

        foreach ($expectedColumns as $table => $column) {
            $this->assertTrue($db->fieldExists($column, $table), sprintf('Column "%s" is missing from table "%s".', $column, $table));
        }

        $expectedIndexes = [
            'entries' => ['idx_entries_current_data'],
            'models'  => ['idx_models_current_data'],
        ];

        foreach ($expectedIndexes as $table => $indexes) {
            $existingIndexes = $db->getIndexData($table);
            $existingIndexNames = array_map(function ($index) {
                return $index->name;
            }, $existingIndexes);

            foreach ($indexes as $indexName) {
                $this->assertContains(
                    $indexName,
                    $existingIndexNames,
                    sprintf('Index "%s" is missing from table "%s".', $indexName, $table)
                );
            }
        }
    }
}
