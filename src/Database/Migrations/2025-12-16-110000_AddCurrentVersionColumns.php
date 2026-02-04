<?php

namespace StarDust\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\BaseConnection;

/**
 * Migration to add current version reference columns.
 *
 * This migration adds `current_entry_data_id` to the `entries` table and
 * `current_model_data_id` to the `models` table. These columns store a direct
 * reference to the current version of the data, allowing for O(1) retrieval
 * performance without needing complex joins or subqueries.
 *
 * @package StarDust\Database\Migrations
 * @property BaseConnection $db
 */
class AddCurrentVersionColumns extends Migration
{
    public function up()
    {
        // Reset cache to ensure we see the current schema state, especially if tables were just created/modified
        $this->db->resetDataCache();

        // 1. Add current_entry_data_id to entries (if not exists)
        if (!$this->fieldExists('current_entry_data_id', 'entries')) {
            // Replaced Forge with Raw SQL to allow wrapping in retry logic (Windows file locking fix)
            $this->tryExec("ALTER TABLE `entries` ADD `current_entry_data_id` INT(11) NULL AFTER `model_id`");
        }

        // Index it for the projected O(1) joins (only if column exists)
        if ($this->fieldExists('current_entry_data_id', 'entries')) {
            $this->tryExec("ALTER TABLE entries ADD INDEX IF NOT EXISTS idx_entries_current_data (current_entry_data_id)");
        }

        // 2. Add current_model_data_id to models (if not exists)
        if (!$this->fieldExists('current_model_data_id', 'models')) {
            $this->tryExec("ALTER TABLE `models` ADD `current_model_data_id` INT(11) NULL AFTER `id`");
        }

        // Index it (only if column exists)
        if ($this->tableExists('models') && $this->fieldExists('current_model_data_id', 'models')) {
            $this->tryExec("ALTER TABLE models ADD INDEX IF NOT EXISTS idx_models_current_data (current_model_data_id)");
        }
    }

    public function down()
    {
        // Drop columns (indexes will drop with them) - with conditional checks
        if ($this->tableExists('models') && $this->fieldExists('current_model_data_id', 'models')) {
            $this->forge->dropColumn('models', 'current_model_data_id');
        }
        if ($this->tableExists('entries') && $this->fieldExists('current_entry_data_id', 'entries')) {
            $this->forge->dropColumn('entries', 'current_entry_data_id');
        }
    }

    private function tableExists(string $table): bool
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
                    // If we can't verify it exists, assume false or rethrow?
                    // Rethrowing is safer to surface the error if it persists.
                    // But for tableExists check, maybe returning false is what we want? 
                    // No, if the engine is broken, we can't assume false.
                    throw $e;
                }
                sleep(1);
            }
        }
        return false;
    }

    private function fieldExists(string $field, string $table): bool
    {
        $attempts = 0;
        $maxAttempts = 3;

        while ($attempts < $maxAttempts) {
            try {
                $this->db->resetDataCache();
                return $this->db->fieldExists($field, $table);
            } catch (\Throwable $e) {
                $attempts++;
                if ($attempts >= $maxAttempts) {
                    throw $e;
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
                if ($attempts >= $maxAttempts) {
                    throw $e;
                }
                sleep(1); // Wait for lock release
            }
        }
    }
}
