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
        // Optimized for "Get all entries for Model X" (Condition Pushdown from Builder)
        // Composite index allows filtering by model & soft-delete status, and sorting by ID (insertion order)
        // without a filesort or extra table lookups for deleted rows.
        $this->db->query("ALTER TABLE entries ADD INDEX idx_entries_model_history (model_id, deleted_at, id)");
        $this->db->query("ALTER TABLE entries ADD INDEX idx_entries_deleted_at (deleted_at)");

        // Entry Data table (Optimized for history lookup)
        // This index allows the subquery "SELECT MAX(id) WHERE deleted_at IS NULL GROUP BY entry_id"
        // to be satisfied entirely from the index (Covering Index).
        $this->db->query("ALTER TABLE entry_data ADD INDEX idx_entry_data_history (entry_id, deleted_at, id)");

        // Model Data table (Optimized for history lookup)
        $this->db->query("ALTER TABLE model_data ADD INDEX idx_model_data_history (model_id, deleted_at, id)");
    }

    public function down()
    {
        // Drop in reverse order
        $this->db->query("ALTER TABLE model_data DROP INDEX idx_model_data_history");
        $this->db->query("ALTER TABLE entry_data DROP INDEX idx_entry_data_history");
        $this->db->query("ALTER TABLE entries DROP INDEX idx_entries_deleted_at");
        $this->db->query("ALTER TABLE entries DROP INDEX idx_entries_model_history");
    }
}
