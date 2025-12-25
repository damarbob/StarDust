<?php

namespace StarDust\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Command to clean up orphaned virtual columns that no longer correspond
 * to any field in ANY model definition (including soft-deleted models).
 *
 * This command identifies virtual columns (v_*_num, v_*_str, v_*_dt) that
 * exist in the entry_data table but are not referenced in any model's
 * field definitions. Soft-deleted models are checked to prevent false
 * positives - a column is only considered orphaned if it matches NO model
 * at all, whether active or soft-deleted.
 *
 * @package StarDust\Commands
 */
class CleanupOrphanedColumns extends BaseCommand
{
    protected $group       = 'StarDust';
    protected $name        = 'stardust:cleanup-columns';
    protected $description = 'Removes orphaned virtual columns that no longer match any model field definitions.';

    public function run(array $params)
    {
        // Check for dry-run flag (supports both terminal and programmatic invocation)
        $dryRun = in_array('--dry-run', $params)
            || in_array('-d', $params)
            || CLI::getOption('dry-run')
            || CLI::getOption('d');

        if ($dryRun) {
            CLI::write("DRY-RUN MODE: No changes will be made", 'black', 'yellow');
            CLI::newLine();
        }

        CLI::write("Analyzing orphaned virtual columns...", 'white', 'blue');

        $indexer = \StarDust\Config\Services::runtimeIndexer();

        try {
            $orphanedColumns = $indexer->findOrphanedColumns();

            if (empty($orphanedColumns)) {
                CLI::write("No orphaned columns found. Database is clean!", 'green');
                return;
            }

            // Get total virtual column count for suspicious deletion detection
            $allColumns = $indexer->getAllVirtualColumns();
            $totalVirtualColumns = count($allColumns);
            $orphanedCount = count($orphanedColumns);
            $orphanedPercentage = $totalVirtualColumns > 0
                ? ($orphanedCount / $totalVirtualColumns) * 100
                : 0;

            CLI::write("Found " . $orphanedCount . " orphaned column(s) out of {$totalVirtualColumns} total virtual columns (" . round($orphanedPercentage, 2) . "%):", 'yellow');
            foreach ($orphanedColumns as $column) {
                CLI::write("  - {$column}", 'cyan');
            }

            // Warn if suspicious percentage
            if ($orphanedPercentage > 50) {
                CLI::newLine();
                CLI::write("⚠ WARNING: More than 50% of virtual columns would be deleted!", 'black', 'yellow');
                CLI::write("  This seems suspicious. Please verify your model definitions are correct.", 'yellow');
                CLI::write("  Consider running with --dry-run first if unsure.", 'yellow');
            }

            if ($dryRun) {
                CLI::newLine();
                CLI::write("Dry-run complete. No changes were made.", 'green');
                CLI::write("To actually delete these columns, run without --dry-run flag.", 'white');
                return;
            }

            CLI::newLine();
            $confirm = CLI::prompt('Do you want to proceed with deletion?', ['y', 'n']);

            if ($confirm !== 'y') {
                CLI::write("Operation cancelled.", 'yellow');
                return;
            }

            CLI::newLine();
            $finalConfirm = CLI::prompt('Are you absolutely sure? This cannot be undone!', ['y', 'n']);

            if ($finalConfirm !== 'y') {
                CLI::write("Operation cancelled.", 'yellow');
                return;
            }

            CLI::newLine();
            CLI::write("Removing orphaned columns...", 'white');

            $results = $indexer->removeOrphanedColumns($orphanedColumns);

            CLI::newLine();

            // Display success statistics
            if (!empty($results['success'])) {
                CLI::write("✓ Successfully removed " . count($results['success']) . " column(s):", 'green');
                foreach ($results['success'] as $column) {
                    CLI::write("  • {$column}", 'green');
                }
            }

            // Display failure statistics
            if (!empty($results['failed'])) {
                CLI::newLine();
                CLI::write("✗ Failed to remove " . count($results['failed']) . " column(s):", 'red');
                foreach ($results['failed'] as $column => $error) {
                    CLI::write("  • {$column}", 'red');
                    CLI::write("    Reason: {$error}", 'red');
                }
            }

            CLI::newLine();
            if (empty($results['failed'])) {
                CLI::write("Cleanup Complete!", 'green');
            } else {
                CLI::write("Cleanup Completed with errors. Check logs for details.", 'yellow');
            }
        } catch (\Throwable $e) {
            CLI::error("Cleanup Failed: " . $e->getMessage());
            CLI::error("Check logs for more details.");
        }
    }
}
