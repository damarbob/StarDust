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
        if (!$this->db->fieldExists('current_entry_data_id', 'entries')) {
            $fieldsEntries = [
                'current_entry_data_id' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'null'       => true,
                    'after'      => 'model_id', // Place it logically near the other IDs
                ],
            ];
            $this->forge->addColumn('entries', $fieldsEntries);
        }

        // Index it for the projected O(1) joins (only if column exists)
        if ($this->db->fieldExists('current_entry_data_id', 'entries')) {
            $this->db->query("ALTER TABLE entries ADD INDEX IF NOT EXISTS idx_entries_current_data (current_entry_data_id)");
        }

        // 2. Add current_model_data_id to models (if not exists)
        if (!$this->db->fieldExists('current_model_data_id', 'models')) {
            $fieldsModels = [
                'current_model_data_id' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'null'       => true,
                    'after'      => 'id',
                ],
            ];
            $this->forge->addColumn('models', $fieldsModels);
        }

        // Index it (only if column exists)
        if ($this->db->fieldExists('current_model_data_id', 'models')) {
            $this->db->query("ALTER TABLE models ADD INDEX IF NOT EXISTS idx_models_current_data (current_model_data_id)");
        }
    }

    public function down()
    {
        // Drop columns (indexes will drop with them) - with conditional checks
        if ($this->db->fieldExists('current_model_data_id', 'models')) {
            $this->forge->dropColumn('models', 'current_model_data_id');
        }
        if ($this->db->fieldExists('current_entry_data_id', 'entries')) {
            $this->forge->dropColumn('entries', 'current_entry_data_id');
        }
    }
}
