<?php

namespace StarDust\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAuthTablesPolyfill extends Migration
{
    public function up()
    {
        $config = config('StarDust');
        $tableName = $config->usersTable;

        // Diagnostic info
        if ($this->db->tableExists($tableName)) {
            return;
        }

        // ===============================================
        // Polyfill "users" table (Minimal)
        // ===============================================
        $this->forge->addField([
            $config->usersIdColumn => [
                'type'           => 'INT',
                'constraint'     => 11,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            $config->usersUsernameColumn => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addPrimaryKey($config->usersIdColumn);

        $attributes = [
            'ENGINE' => 'InnoDB',
        ];

        $this->forge->createTable($tableName, true, $attributes);
    }

    public function down()
    {
        // We generally DO NOT drop this table in down() because it might 
        // have been assumed to exist or shared with other apps.
        // This is a "safety net" creation only.
    }
}
