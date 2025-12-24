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
        if (!$this->indexExists($table, $indexName)) {
            $cols = implode(',', $columns);
            $this->db->query("ALTER TABLE `$table` ADD INDEX `$indexName` ($cols)");
        }
    }

    private function dropIndex(string $table, string $indexName)
    {
        if ($this->indexExists($table, $indexName)) {
            $this->db->query("ALTER TABLE `$table` DROP INDEX `$indexName`");
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        // Check if table exists first using raw SQL to avoid method dependencies
        $tableExists = $this->db->query("SHOW TABLES LIKE ?", [$table])->getResult();
        if (empty($tableExists)) {
            return false;
        }
        $result = $this->db->query("SHOW INDEX FROM `$table` WHERE Key_name = ?", [$indexName])->getResult();
        return !empty($result);
    }
}
