<?php

namespace StarDust\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration to add performance-enhancing indexes.
 *
 * This migration adds composite indexes to `entries`, `entry_data`, and `model_data`
 * tables to optimize common queries.
 *
 * Updates:
 * - Refactored to support both MySQL and SQLite (standard CREATE INDEX).
 * - Removed ad-hoc retry logic for file locking (handled by infrastructure/safe-traits).
 *
 * @property \CodeIgniter\Database\BaseConnection $db
 * @property \CodeIgniter\Database\Forge $forge
 */
class AddPerformanceIndexes extends Migration
{
    public function up()
    {
        $indexes = [
            'entries'    => [
                'idx_entries_model_history' => ['model_id', 'deleted_at', 'id'],
                'idx_entries_deleted_at'    => ['deleted_at'],
            ],
            'entry_data' => [
                'idx_entry_data_history'    => ['entry_id', 'deleted_at', 'id'],
            ],
            'model_data' => [
                'idx_model_data_history'    => ['model_id', 'deleted_at', 'id'],
            ],
        ];

        foreach ($indexes as $table => $keys) {
            foreach ($keys as $keyName => $fields) {
                // Check if index exists to ensure idempotency
                if ($this->indexExists($table, $keyName)) {
                    continue;
                }

                // Standard SQL for creating indexes works on both MySQL and SQLite
                $cols = implode(',', $fields);
                $sql  = "CREATE INDEX $keyName ON $table ($cols)";

                try {
                    $this->db->query($sql);
                } catch (\Throwable $e) {
                    // If creation fails (e.g. race condition), log warning but don't crash
                    log_message('warning', "Index creation failed for $keyName: " . $e->getMessage());
                }
            }
        }
    }

    public function down()
    {
        $indexes = [
            'entries'    => ['idx_entries_model_history', 'idx_entries_deleted_at'],
            'entry_data' => ['idx_entry_data_history'],
            'model_data' => ['idx_model_data_history'],
        ];

        foreach ($indexes as $table => $keys) {
            foreach ($keys as $keyName) {
                try {
                    // Handle syntax differences for dropping indexes
                    if ($this->db->DBDriver === 'SQLite3') {
                        $this->db->query("DROP INDEX IF EXISTS $keyName");
                    } else {
                        // MySQL/MariaDB
                        $this->db->query("DROP INDEX $keyName ON $table");
                    }
                } catch (\Throwable $e) {
                    // Ignore errors during rollback (e.g. index doesn't exist)
                }
            }
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

        // getIndexData is supported by CI4 for MySQL and SQLite
        $indexes = $this->db->getIndexData($table);

        foreach ($indexes as $idx) {
            if ($idx->name === $indexName) {
                return true;
            }
        }

        return false;
    }
}
