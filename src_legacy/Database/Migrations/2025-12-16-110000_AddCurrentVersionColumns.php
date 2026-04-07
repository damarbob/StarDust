<?php

namespace StarDust\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration to add current version reference columns.
 *
 * This migration adds `current_entry_data_id` to the `entries` table and
 * `current_model_data_id` to the `models` table. These columns store a direct
 * reference to the current version of the data.
 *
 * Updates:
 * - Replaced raw SQL `ALTER TABLE` with `Forge->addColumn()`.
 * - Standardized index creation for cross-database compatibility (MySQL/SQLite).
 * - Removed raw nested try-catch blocks in favor of `indexExists` check.
 *
 * @property \CodeIgniter\Database\BaseConnection $db
 * @property \CodeIgniter\Database\Forge $forge
 */
class AddCurrentVersionColumns extends Migration
{
    public function up()
    {
        // Reset cache to ensure we see the current schema state
        $this->db->resetDataCache();

        // 1. Add current_entry_data_id to entries
        $fieldsEntries = [
            'current_entry_data_id' => [
                'type'       => 'INT', // Standard INT used across drivers
                'constraint' => 11,
                'null'       => true,
                'default'    => null,
            ],
        ];
        
        // Forge handles "Check if field exists" internally if we check first, 
        // but explicit check prevents errors on re-runs.
        if (! $this->db->fieldExists('current_entry_data_id', 'entries')) {
            $this->forge->addColumn('entries', $fieldsEntries);
        }

        // 2. Add current_model_data_id to models
        $fieldsModels = [
            'current_model_data_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
                'default'    => null,
            ],
        ];

        if (! $this->db->fieldExists('current_model_data_id', 'models')) {
            $this->forge->addColumn('models', $fieldsModels);
        }

        // 3. Create Indexes securely
        $indexes = [
            'entries' => ['idx_entries_current_data' => ['current_entry_data_id']],
            'models'  => ['idx_models_current_data'  => ['current_model_data_id']],
        ];

        foreach ($indexes as $table => $keys) {
            foreach ($keys as $keyName => $fields) {
                if ($this->indexExists($table, $keyName)) {
                    continue;
                }

                // Standard SQL compatible with both SQLite and MySQL
                $cols = implode(',', $fields);
                $sql  = "CREATE INDEX $keyName ON $table ($cols)";

                try {
                    $this->db->query($sql);
                } catch (\Throwable $e) {
                    log_message('warning', "Index creation failed for $keyName: " . $e->getMessage());
                }
            }
        }
    }

    public function down()
    {
        // Reset cache to ensure we see the current schema state
        $this->db->resetDataCache();

        // Drop columns (indexes will be dropped automatically with the column)
        if ($this->db->fieldExists('current_model_data_id', 'models')) {
            $this->forge->dropColumn('models', 'current_model_data_id');
        }

        if ($this->db->fieldExists('current_entry_data_id', 'entries')) {
            $this->forge->dropColumn('entries', 'current_entry_data_id');
        }
    }

    /**
     * Checks if an index exists strictly by name.
     */
    private function indexExists(string $table, string $indexName): bool
    {
        if (! $this->db->tableExists($table)) {
            return false;
        }

        $indexes = $this->db->getIndexData($table);

        foreach ($indexes as $idx) {
            if ($idx->name === $indexName) {
                return true;
            }
        }

        return false;
    }
}
