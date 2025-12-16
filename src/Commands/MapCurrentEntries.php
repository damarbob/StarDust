<?php

namespace StarDust\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class MapCurrentEntries extends BaseCommand
{
    /**
     * The Command's Group
     *
     * @var string
     */
    protected $group = 'StarDust';

    /**
     * The Command's Name
     *
     * @var string
     */
    protected $name = 'stardust:map-current';

    /**
     * The Command's Description
     *
     * @var string
     */
    protected $description = 'Maps the latest history ID to the parent table for O(1) joins.';

    /**
     * The Command's Usage
     *
     * @var string
     */
    protected $usage = 'stardust:map-current';

    /**
     * Actually execute a command.
     *
     * @param array $params
     */
    public function run(array $params)
    {
        CLI::write('Starting Materialization of Current Versions...', 'yellow');

        $db = \Config\Database::connect();

        // ----------------------------------------------------
        // 1. Map Entries
        // ----------------------------------------------------
        CLI::write('Mapping Entries...', 'light_blue');

        // We use a direct UPDATE with JOIN to do this in one massive, efficient query 
        // instead of iterating row-by-row in PHP.
        // This leverages the "Group-wise Max" logic we already know, but uses it to Update.

        $sqlEntries = "
            UPDATE entries
            INNER JOIN (
                SELECT entry_id, MAX(id) as max_id
                FROM entry_data
                WHERE deleted_at IS NULL
                GROUP BY entry_id
            ) as latest_history ON entries.id = latest_history.entry_id
            SET entries.current_entry_data_id = latest_history.max_id
            WHERE entries.deleted_at IS NULL
        ";

        $db->query($sqlEntries);
        $affectedEntries = $db->affectedRows();
        CLI::write("  - Updated {$affectedEntries} entries.", 'green');

        // ----------------------------------------------------
        // 2. Map Models (same logic)
        // ----------------------------------------------------
        CLI::write('Mapping Models...', 'light_blue');

        $sqlModels = "
            UPDATE models
            INNER JOIN (
                SELECT model_id, MAX(id) as max_id
                FROM model_data
                WHERE deleted_at IS NULL
                GROUP BY model_id
            ) as latest_history ON models.id = latest_history.model_id
            SET models.current_model_data_id = latest_history.max_id
            WHERE models.deleted_at IS NULL
        ";

        $db->query($sqlModels);
        $affectedModels = $db->affectedRows();
        CLI::write("  - Updated {$affectedModels} models.", 'green');

        CLI::write('Materialization Complete!', 'white', 'green');
    }
}
