<?php

namespace StarDust\Jobs;

use CodeIgniter\Queue\BaseJob;
use CodeIgniter\Queue\Interfaces\JobInterface;
use StarDust\Services\EntriesManager;
use StarDust\Services\ModelsManager;

/**
 * Class PurgeDeletedJob
 *
 * Asynchronously purges deleted models or entries.
 * Self-chains if more items remain to handle large datasets safely.
 */
class PurgeDeletedJob extends BaseJob implements JobInterface
{
    /**
     * @var int Number of items to purge per execution
     */
    protected int $limit = 1000;

    /**
     * Process the job.
     *
     * @param array $data Expected keys: 'type' ('entries'|'models')
     * @return void
     */
    public function process()
    {
        $config = config('StarDust');
        $this->limit = $config->purgeLimit ?? 1000;

        $data = $this->data;
        $type = $data['type'] ?? 'entries';
        $stuckCount = $data['stuck_count'] ?? 0;
        $totalPurged = $data['total_purged'] ?? 0;

        $manager = null;
        $purged = 0;

        if ($type === 'entries') {
            /** @var EntriesManager $manager */
            $manager = service('entriesManager');
            $purged = $manager->purgeDeleted($this->limit);
        } elseif ($type === 'models') {
            /** @var ModelsManager $manager */
            $manager = service('modelsManager');
            $purged = $manager->purgeDeleted($this->limit);
        }

        $totalPurged += $purged;

        // Success Case: We purged items.
        // Assume there is more work to do or we are making progress.
        // Re-queue immediately to keep the pipeline moving rapidly.
        if ($purged > 0) {
            service('queue')->push(
                'default',
                PurgeDeletedJob::class,
                [
                    'type' => $type,
                    'stuck_count' => 0, // Reset stuck count
                    'total_purged' => $totalPurged
                ]
            );
            return;
        }

        // Potential Stall Case: We purged 0 items.
        // Only NOW do we check if any *ACTIONABLE* deleted items actually remain.
        // This saves expensive count() queries on the "happy path".

        $remainingPurgeable = 0;

        if ($type === 'entries') {
            $remainingPurgeable = $manager->countDeleted();
        } elseif ($type === 'models') {
            // For models, we only care if there are models WITHOUT children.
            // If models exist but have children, we are BLOCKED, not STUCK.
            // Blocked = Success/Wait (Lazy). Stuck = Error.
            $remainingPurgeable = $manager->countPurgeableDeleted();
        }

        if ($remainingPurgeable > 0) {
            // We have work ($remainingPurgeable > 0) but made no progress ($purged == 0).
            // We are likely stuck (locked rows, FK constraints, etc).
            $stuckCount++;

            if ($stuckCount < 3) {
                // Backoff and Retry
                $queue = service('queue');
                $jobClass = PurgeDeletedJob::class;
                $jobData = [
                    'type' => $type,
                    'stuck_count' => $stuckCount,
                    'total_purged' => $totalPurged
                ];

                if (method_exists($queue, 'later')) {
                    $queue->later(5, 'default', $jobClass, $jobData);
                } else {
                    sleep(5); // Blocking backoff as fallback
                    $queue->push('default', $jobClass, $jobData);
                }
            } else {
                // Abort
                log_message(
                    'error',
                    "PurgeDeletedJob Aborted. Type: {$type}. Total Purged: {$totalPurged}. Remaining Purgeable: {$remainingPurgeable}. Job appeared stuck."
                );
            }
        } else {
            // All done (Remaining Purgeable == 0).
            // Note: For Models, this might mean models still exist (deleted), but they are blocked by entries.
            // This is valid behavior (Lazy Purge), so we exit successfully.
            // Optionally log success summary
            // log_message('info', "Purge Complete/Succeeded. Type: {$type}. Total Purged: {$totalPurged}.");
        }
    }
}
