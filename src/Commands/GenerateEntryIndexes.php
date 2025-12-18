<?php

namespace StarDust\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Command to generate virtual columns and indexes for all models.
 *
 * This command utilizes the RuntimeIndexer service to process all
 * active models and ensure their fields are properly indexed in the
 * database for efficient querying.
 *
 * @package StarDust\Commands
 */
class GenerateEntryIndexes extends BaseCommand
{
    protected $group       = 'StarDust';
    protected $name        = 'stardust:generate-indexes';
    protected $description = 'Generates virtual columns and indexes for all models based on their fields.';

    public function run(array $params)
    {
        CLI::write("Generating Virtual Column Indexes...", 'white', 'blue');

        $indexer = \StarDust\Config\Services::runtimeIndexer();

        try {
            $indexer->indexAllModels();
            CLI::write("Indexing Complete.", 'green');
        } catch (\Throwable $e) {
            CLI::error("Indexing Failed: " . $e->getMessage());
        }
    }
}
