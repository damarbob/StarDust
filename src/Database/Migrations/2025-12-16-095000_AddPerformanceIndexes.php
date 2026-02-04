<?php

namespace StarDust\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration to add performance-enhancing indexes.
 *
 * This migration adds composite indexes to `entries`, `entry_data`, and `model_data`
 * tables to optimize common queries, specifically those involving filtering by
 * soft-delete status and ordering by ID or looking up history.
 *
 * @package StarDust\Database\Migrations
 */
class AddPerformanceIndexes extends Migration
{
    public function up()
    {
        // Entries table
        $this->ensureIndex('entries', 'idx_entries_model_history', ['model_id', 'deleted_at', 'id']);
        $this->ensureIndex('entries', 'idx_entries_deleted_at', ['deleted_at']);

        // Entry Data table
        $this->ensureIndex('entry_data', 'idx_entry_data_history', ['entry_id', 'deleted_at', 'id']);

        // Model Data table
        $this->ensureIndex('model_data', 'idx_model_data_history', ['model_id', 'deleted_at', 'id']);
    }

    public function down()
    {
        // Drop in reverse order
        $this->dropIndex('model_data', 'idx_model_data_history');
        $this->dropIndex('entry_data', 'idx_entry_data_history');
        $this->dropIndex('entries', 'idx_entries_deleted_at');
        $this->dropIndex('entries', 'idx_entries_model_history');
    }

    private function ensureIndex(string $table, string $indexName, array $columns)
    {
        // Check if table exists first
        if (!$this->tryTableExists($table)) {
            return;
        }

        if (!$this->indexExists($table, $indexName)) {
            $cols = implode(',', $columns);
            $this->tryExec("ALTER TABLE `$table` ADD INDEX `$indexName` ($cols)");
        }
    }

    private function dropIndex(string $table, string $indexName)
    {
        if ($this->indexExists($table, $indexName)) {
            $this->tryExec("ALTER TABLE `$table` DROP INDEX `$indexName`");
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        if (!$this->tryTableExists($table)) {
            return false;
        }

        try {
            $result = $this->db->query("SHOW INDEX FROM `$table` WHERE Key_name = ?", [$indexName])->getResult();
            return !empty($result);
        } catch (\Throwable $e) {
            // If table doesn't exist or other error, assume index doesn't exist (or can't be checked)
            return false;
        }
    }

    private function tryTableExists(string $table): bool
    {
        $attempts = 0;
        $maxAttempts = 3;

        while ($attempts < $maxAttempts) {
            try {
                // resetDataCache is sometimes needed if table was just dropped/created
                $this->db->resetDataCache();
                $result = $this->db->query("SHOW TABLES LIKE ?", [$table])->getResult();
                return !empty($result);
            } catch (\Throwable $e) {
                // If "doesn't exist in engine" (errno 1932) or other locks
                $attempts++;
                if ($attempts >= $maxAttempts) {
                    // If repeated failure, assume false or throw?
                    // Returning false is safer for migration checks (it just means we skip adding index)
                    return false;
                }
                sleep(1);
            }
        }
        return false;
    }

    /**
     * Executes a query with retry logic for Windows file locking issues.
     */
    private function tryExec(string $sql)
    {
        $attempts = 0;
        $maxAttempts = 3;

        while ($attempts < $maxAttempts) {
            try {
                $this->db->query($sql);
                return; // Success
            } catch (\Throwable $e) {
                $attempts++;
                // Check if it's a permission/locking error (errno 13 or similar)
                // However, we retry on any error during migration for robustness in tests
                if ($attempts >= $maxAttempts) {
                    throw $e;
                }
                sleep(1); // Wait for lock release
            }
        }
    }
}
