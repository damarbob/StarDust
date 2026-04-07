<?php

namespace StarDust\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

class QueueWorker extends Controller
{
    /**
     * Process the queue via HTTP request.
     * 
     * This method acts as a wrapper around the CLI `queue:work` command logic
     * but adapted for a single-shot execution suitable for cron jobs (e.g., cron-job.org).
     *
     * @return ResponseInterface
     */
    /**
     * Process the queue via HTTP request.
     * 
     * This method acts as a wrapper around the CLI `queue:work` command logic
     * but adapted for a single-shot execution suitable for cron jobs (e.g., cron-job.org).
     *
     * @param string|null $token
     * @return ResponseInterface
     */
    public function work(?string $token = null)
    {
        // 1. Security: Validate Token
        $config = config('StarDust');

        // If no token is configured in .env, we DENY all requests by default for security
        // logic: empty/null config token means "feature disabled" or "insecure config"
        if (empty($config->workerToken)) {
            return $this->response->setStatusCode(403)->setBody('Forbidden: Worker token not configured.');
        }

        // Compare provided token (from URL) with configured token (safely)
        if (!hash_equals($config->workerToken, (string)$token)) {
            return $this->response->setStatusCode(403)->setBody('Forbidden: Invalid token.');
        }

        // 2. Check if Queue library is installed
        if (! $this->isQueueLibraryInstalled()) {
            return $this->response->setStatusCode(500)->setBody('Queue library not installed.');
        }

        $queueName = $config->queueName ?? 'stardust-indexes';

        // 3. Run the worker for a limited time/count to fit within HTTP timeout limits
        // We use the service directly.
        // NOTE: The Queue library's `work()` method typically loops. 
        // We need to fetch and run just a few jobs to avoid timeouts.

        try {
            /** @var \CodeIgniter\Queue\Queue $queue */
            $queue = service('queue');

            // We pop a job and execute it. 
            // Since `pop` and `fire` are lower level, we check if the library exposes a safe "work one" method.
            // CodeIgniter 4 Queue implementation details vary, but assuming standard behavior:

            // Allow work for up to 5 seconds or 5 jobs
            $endTime = time() + 5;
            $jobsProcessed = 0;

            while (time() < $endTime && $jobsProcessed < 5) {
                // Determine which method to use based on available API
                // Assuming we use the standard 'pop' mechanism
                $job = $queue->pop($queueName, ['high', 'low', 'default']);

                if ($job === null) {
                    break; // Queue empty
                }

                // Manual Job Processing
                try {
                    $payload = $job->payload;
                    $class   = $payload['job'];
                    $data    = $payload['data'];

                    if (!class_exists($class)) {
                        throw new \Exception("Job class {$class} not found");
                    }

                    $jobInstance = new $class($data);
                    $jobInstance->process();

                    // If successful, remove from queue
                    if (method_exists($queue, 'done')) {
                        $queue->done($job);
                    } elseif (method_exists($queue, 'delete')) {
                        $queue->delete($job);
                    } else {
                        // Fallback for custom handlers
                        $queue->done($job);
                    }
                } catch (\Throwable $e) {
                    log_message('error', '[QueueWorker] Job Invalid or Failed: ' . $e->getMessage());
                    // Don't delete, let it retry or timeout
                }
                $jobsProcessed++;
            }

            return $this->response->setBody("Processed {$jobsProcessed} jobs.");
        } catch (\Throwable $e) {
            log_message('error', '[QueueWorker] ' . $e->getMessage());
            return $this->response->setStatusCode(500)->setBody('Worker error: ' . $e->getMessage());
        }
    }
    /**
     * Check if the Queue library is installed.
     * extracted for testing purposes.
     *
     * @return bool
     */
    protected function isQueueLibraryInstalled(): bool
    {
        return class_exists('CodeIgniter\Queue\Queue');
    }
}
