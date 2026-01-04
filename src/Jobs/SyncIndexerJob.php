<?php

namespace StarDust\Jobs;

use CodeIgniter\Queue\BaseJob;
use CodeIgniter\Queue\Interfaces\JobInterface;
use StarDust\Libraries\RuntimeIndexer;

class SyncIndexerJob extends BaseJob implements JobInterface
{
    public function process()
    {
        $payload = $this->data;

        if (!isset($payload['modelDefinition']) || !is_array($payload['modelDefinition'])) {
            log_message('error', '[SyncIndexerJob] Invalid payload: ' . json_encode($payload));
            return; // Invalid payload
        }

        log_message('info', '[SyncIndexerJob] Processing model sync: ' . json_encode($payload));

        /** @var RuntimeIndexer $indexer */
        $indexer = service('runtimeIndexer');

        try {
            // We don't need to pass existingColumns here because the job runs in isolation
            // and we want fresh checks anyway.
            $indexer->syncIndexes($payload['modelDefinition']);
            log_message('info', '[SyncIndexerJob] Model sync completed.');
        } catch (\Throwable $e) {
            log_message('error', '[SyncIndexerJob] Error syncing indexes: ' . $e->getMessage());
            throw $e; // Re-throw to retry job if possible
        }
    }
}
