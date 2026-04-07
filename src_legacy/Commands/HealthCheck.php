<?php

namespace StarDust\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class HealthCheck extends BaseCommand
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
    protected $name = 'stardust:health';

    /**
     * The Command's Description
     *
     * @var string
     */
    protected $description = 'Checks the health of StarDust data and identifies purge blockers.';

    /**
     * The Command's Usage
     *
     * @var string
     */
    protected $usage = 'stardust:health [options]';

    /**
     * The Command's Arguments
     *
     * @var array
     */
    protected $arguments = [];

    /**
     * The Command's Options
     *
     * @var array
     */
    protected $options = [
        '-days' => 'Filter "Stuck" models older than X days (default: 30)',
    ];

    /**
     * Database connection
     *
     * @var \CodeIgniter\Database\BaseConnection|null
     */
    protected $db;

    /**
     * Actually execute a command.
     *
     * @param array $params
     */
    public function run(array $params)
    {
        $days = $params['days'] ?? 30;
        $db   = $this->db ?? \Config\Database::connect();

        $this->writeLine('StarDust Health Check', 'green');
        $this->writeLine('---------------------');

        // 1. Overview Stats
        $this->showOverview($db);

        // 2. Stuck Models Analysis
        $this->analyzeBlockers($db, (int)$days);
    }

    protected function showOverview($db)
    {
        $modelsTotal   = $db->table('models')->countAllResults();
        $modelsDeleted = $db->table('models')->where('deleted_at IS NOT NULL')->countAllResults();
        $entriesTotal  = $db->table('entries')->countAllResults();
        $entriesDeleted = $db->table('entries')->where('deleted_at IS NOT NULL')->countAllResults();

        $data = [
            ['Metric', 'Total', 'Active', 'Soft Deleted'],
            ['Models', $modelsTotal, $modelsTotal - $modelsDeleted, $modelsDeleted],
            ['Entries', $entriesTotal, $entriesTotal - $entriesDeleted, $entriesDeleted],
        ];

        $this->showTable($data, ['Metric', 'Total', 'Active', 'Soft Deleted']);
        $this->writeLine('');
    }

    protected function analyzeBlockers($db, int $days)
    {
        $this->writeLine("Analyzing Models soft-deleted more than {$days} days ago...", 'yellow');
        $dateThreshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        // 1. Get Total Count of Stuck Models efficiently
        // We use INNER JOIN because we only care about models THAT HAVE entries.
        $countSql = "SELECT COUNT(DISTINCT models.id) as total 
                     FROM models 
                     INNER JOIN entries ON entries.model_id = models.id 
                     WHERE models.deleted_at <= ?";

        $totalStuck = $db->query($countSql, [$dateThreshold])->getRow()->total;

        if ($totalStuck == 0) {
            $this->writeLine('✅ No stuck models found!', 'green');
            return;
        }

        $this->writeLine("Found {$totalStuck} stuck models blocking the purge queue.", 'red');
        if ($totalStuck > 10) {
            $this->writeLine("Showing top 10 models with the most remaining entries:", 'yellow');
        }

        // 2. Fetch Top 10 Blockers
        // We join model_data to get the name.
        $query = $db->table('models')
            ->select('models.id, models.deleted_at, model_data.name, COUNT(entries.id) as remaining_entries')
            ->join('entries', 'entries.model_id = models.id', 'inner') // Only those with entries
            ->join('model_data', 'model_data.id = models.current_model_data_id', 'left')
            ->where('models.deleted_at <=', $dateThreshold)
            ->groupBy('models.id, models.deleted_at, model_data.name')
            ->orderBy('remaining_entries', 'DESC')
            ->limit(10);

        $results = $query->get()->getResultArray();

        $tableData = [];
        foreach ($results as $row) {
            $tableData[] = [
                $row['id'],
                $row['name'] ?? 'Unknown',
                $row['deleted_at'],
                $row['remaining_entries'],
            ];
        }

        $this->showTable($tableData, ['Model ID', 'Name', 'Deleted At', 'Remaining Entries']);

        $this->writeLine('');
        $this->writeLine('💡 Tip: These models cannot be purged because they contain child entries.', 'white');
        $this->writeLine('   Action: Use EntriesManager::purgeDeleted() or manually delete entries for these models.', 'white');
    }

    // --- Protected Wrappers for CLI static methods (Facilitates Testing) ---

    protected function writeLine(string $text, ?string $foreground = null)
    {
        CLI::write($text, $foreground);
    }

    protected function showTable(array $tbody, array $thead = [])
    {
        CLI::table($tbody, $thead);
    }
}
