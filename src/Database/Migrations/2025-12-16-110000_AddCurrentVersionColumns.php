<?php

namespace StarDust\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration to add current version reference columns.
 *
 * This migration adds `current_entry_data_id` to the `entries` table and
 * `current_model_data_id` to the `models` table. These columns store a direct
 * reference to the current version of the data, allowing for O(1) retrieval
 * performance without needing complex joins or subqueries.
 *
 * @package StarDust\Database\Migrations
 */
class AddCurrentVersionColumns extends Migration
{
    public function up()
    {
        // 1. Add current_entry_data_id to entries
        $fieldsEntries = [
            'current_entry_data_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
                'after'      => 'model_id', // Place it logically near the other IDs
            ],
        ];
        $this->forge->addColumn('entries', $fieldsEntries);

        // Index it for the projected O(1) joins
        $this->db->query("ALTER TABLE entries ADD INDEX idx_entries_current_data (current_entry_data_id)");

        // 2. Add current_model_data_id to models
        $fieldsModels = [
            'current_model_data_id' => [
                'type'       => 'INT',
                'constraint' => 11,
                'null'       => true,
                'after'      => 'id',
            ],
        ];
        $this->forge->addColumn('models', $fieldsModels);

        // Index it
        $this->db->query("ALTER TABLE models ADD INDEX idx_models_current_data (current_model_data_id)");
    }

    public function down()
    {
        // Drop columns (indexes will drop with them)
        $this->forge->dropColumn('models', 'current_model_data_id');
        $this->forge->dropColumn('entries', 'current_entry_data_id');
    }
}
