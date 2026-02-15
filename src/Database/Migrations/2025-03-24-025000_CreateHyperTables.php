<?php

namespace StarDust\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Migration to create the core tables for the StarDust package.
 *
 * This migration establishes the foundational schema including:
 * - `entries`: The main table for storing entry records.
 * - `entry_data`: Stores the versioned data for each entry.
 * - `models`: The main table for storing model definitions.
 * - `model_data`: Stores the versioned data for each model.
 *
 * It also applies necessary constraints and initial configurations.
 *
 * @package StarDust\Database\Migrations
 */
class CreateHyperTables extends Migration
{
    public function up()
    {
        // ===============================================
        // Create "entries" table
        // ===============================================
        $this->forge->addField([
            'id' => [
                'type'            => 'INT',
                'constraint'      => 11,
                'auto_increment'  => true,
            ],
            'model_id' => [
                'type'            => 'INT',
                'constraint'      => 11,
            ],
            'creator_id' => [
                'type'            => 'INT',
                'constraint'      => 11,
            ],
            'deleter_id' => [
                'type'            => 'INT',
                'constraint'      => 11,
                'null'            => true,
            ],
            'created_at' => [
                'type'            => 'DATETIME',
                'null'            => false,
            ],
            'updated_at' => [
                'type'            => 'DATETIME',
                'null'            => false,
            ],
            'deleted_at' => [
                'type'            => 'DATETIME',
                'null'            => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $attributesEntries = [];
        if ($this->db->DBDriver === 'MySQLi') {
            $attributesEntries = [
                'ENGINE'  => 'InnoDB',
                'CHARSET' => 'utf8mb4',
                'COLLATE' => 'utf8mb4_general_ci',
            ];
        }
        $this->forge->createTable('entries', true, $attributesEntries);

        // ===============================================
        // Create "entry_data" table
        // ===============================================
        $this->forge->addField([
            'id' => [
                'type'            => 'INT',
                'constraint'      => 11,
                'auto_increment'  => true,
            ],
            'entry_id' => [
                'type'            => 'INT',
                'constraint'      => 11,
            ],
            // Using LONGTEXT because MariaDB aliases JSON to this, and we apply strict constraints manually.
            // We use utf8mb4_bin for strict JSON case sensitivity.
            'fields' => [
                'type'            => 'LONGTEXT',
                'null'            => false,
            ],
            'creator_id' => [
                'type'            => 'INT',
                'constraint'      => 11,
            ],
            'deleter_id' => [
                'type'            => 'INT',
                'constraint'      => 11,
                'null'            => true,
            ],
            'created_at' => [
                'type'            => 'DATETIME',
                'null'            => false,
            ],
            'updated_at' => [
                'type'            => 'DATETIME',
                'null'            => false,
            ],
            'deleted_at' => [
                'type'            => 'DATETIME',
                'null'            => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $attributesEntryData = [];
        if ($this->db->DBDriver === 'MySQLi') {
            $attributesEntryData = [
                'ENGINE'  => 'InnoDB',
                'CHARSET' => 'utf8mb4',
                'COLLATE' => 'utf8mb4_general_ci',
            ];
        }
        $this->forge->createTable('entry_data', true, $attributesEntryData);

        // ===============================================
        // Create "models" table
        // ===============================================
        $this->forge->addField([
            'id' => [
                'type'            => 'INT',
                'constraint'      => 11,
                'auto_increment'  => true,
            ],
            'creator_id' => [
                'type'            => 'INT',
                'constraint'      => 11,
            ],
            'deleter_id' => [
                'type'            => 'INT',
                'constraint'      => 11,
                'null'            => true,
            ],
            'created_at' => [
                'type'            => 'DATETIME',
                'null'            => false,
            ],
            'updated_at' => [
                'type'            => 'DATETIME',
                'null'            => false,
            ],
            'deleted_at' => [
                'type'            => 'DATETIME',
                'null'            => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $attributesModels = [];
        if ($this->db->DBDriver === 'MySQLi') {
            $attributesModels = [
                'ENGINE'  => 'InnoDB',
                'CHARSET' => 'utf8mb4',
                'COLLATE' => 'utf8mb4_general_ci',
            ];
        }
        $this->forge->createTable('models', true, $attributesModels);

        // ===============================================
        // Create "model_data" table
        // ===============================================
        $this->forge->addField([
            'id' => [
                'type'            => 'INT',
                'constraint'      => 11,
                'auto_increment'  => true,
            ],
            'model_id' => [
                'type'            => 'INT',
                'constraint'      => 11,
            ],
            'name' => [
                'type'            => 'VARCHAR',
                'constraint'      => 255,
            ],
            // Using LONGTEXT because MariaDB aliases JSON to this, and we apply strict constraints manually.
            // We use utf8mb4_bin for strict JSON case sensitivity.
            'fields' => [
                'type'            => 'LONGTEXT',
                'null'            => false,
            ],
            'group' => [
                'type'            => 'TEXT',
                'null'            => true,
            ],
            'user_groups' => [
                'type'            => 'TEXT',
                'null'            => true,
            ],
            'icon' => [
                'type'            => 'TEXT',
                'null'            => true,
            ],
            'creator_id' => [
                'type'            => 'INT',
                'constraint'      => 11,
            ],
            'deleter_id' => [
                'type'            => 'INT',
                'constraint'      => 11,
                'null'            => true,
            ],
            'created_at' => [
                'type'            => 'DATETIME',
                'null'            => false,
            ],
            'updated_at' => [
                'type'            => 'DATETIME',
                'null'            => false,
            ],
            'deleted_at' => [
                'type'            => 'DATETIME',
                'null'            => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $attributesModelData = [];
        if ($this->db->DBDriver === 'MySQLi') {
            $attributesModelData = [
                'ENGINE'  => 'InnoDB',
                'CHARSET' => 'utf8mb4',
                'COLLATE' => 'utf8mb4_general_ci',
            ];
        }
        $this->forge->createTable('model_data', true, $attributesModelData);

        // ===============================================
        // Update "fields" columns to add character set/collation and JSON check constraint
        // ===============================================
        // Only run this on MySQL/MariaDB
        if ($this->db->DBDriver === 'MySQLi') {
            $db = \Config\Database::connect();

            // Check and apply JSON validation for entry_data.fields (if not already applied)
            $entryDataResult = $db->query("SHOW CREATE TABLE `entry_data`")->getRow();
            if ($entryDataResult && isset($entryDataResult->{'Create Table'})) {
                $entryDataCreate = $entryDataResult->{'Create Table'};
                if (strpos($entryDataCreate, 'json_valid') === false) {
                    $db->query("ALTER TABLE `entry_data` MODIFY `fields` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`fields`))");
                }
            }

            // Check and apply JSON validation for model_data.fields (if not already applied)
            $modelDataResult = $db->query("SHOW CREATE TABLE `model_data`")->getRow();
            if ($modelDataResult && isset($modelDataResult->{'Create Table'})) {
                $modelDataCreate = $modelDataResult->{'Create Table'};
                if (strpos($modelDataCreate, 'json_valid') === false) {
                    $db->query("ALTER TABLE `model_data` MODIFY `fields` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`fields`))");
                }
            }
        }
    }

    public function down()
    {
        $this->forge->dropTable('model_data', true);
        $this->forge->dropTable('models', true);
        $this->forge->dropTable('entry_data', true);
        $this->forge->dropTable('entries', true);
    }
}
