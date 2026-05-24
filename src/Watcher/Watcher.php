<?php

declare(strict_types=1);

namespace StarDust\Watcher;

use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Daemon\AdvisoryLock;
use StarDust\Daemon\Tickable;
use StarDust\Exception\AdvisoryLockTimeoutException;
use StarDust\Page\PageProvisioner;
use StarDust\Support\UuidV4;
use Throwable;

/**
 * Singleton page-provisioning daemon (ADR 0008, ADR 0027).
 *
 * Each `tick()`:
 *   1. Emits `poll_started`.
 *   2. Asks {@see CapacityReporter} for the global free-ratio.
 *   3. If below threshold, acquires
 *      `GET_LOCK('stardust_page_provision', 10)`, emits
 *      `provision_started`, calls {@see PageProvisioner::provision([])},
 *      emits `provision_complete`, and releases the lock.
 *   4. If the 24 h cardinality timer has elapsed, runs
 *      {@see CardinalitySampler::sample()}.
 *   5. Emits `poll_complete`.
 *
 * Failure mapping:
 *   - {@see AdvisoryLockTimeoutException} → `lock_contention`.
 *   - any other `Throwable` from the provision path → `provision_failed`
 *     (re-thrown so the daemon loop terminates and the process exits).
 *
 * Process-level singleton enforcement is the CLI's job
 * ({@see \StarDust\Daemon\PidFileGuard}); this class assumes it.
 *
 * The Watcher passes `filterableSlots = []` because its trigger is
 * capacity, not field demand. Field-driven indexed slot provisioning
 * is Phase 6b's territory and runs through a different path.
 */
final class Watcher implements Tickable
{
    private ?int $lastCardinalitySampleAt = null;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly CapacityReporter $capacityReporter,
        private readonly PageProvisioner $pageProvisioner,
        private readonly CardinalitySampler $cardinalitySampler,
        private readonly float $capacityThreshold,
        private readonly int $cardinalityIntervalSeconds,
        private readonly int $provisionLockTimeoutSeconds = 10,
    ) {
    }

    public function tick(): void
    {
        $correlationId = UuidV4::generate();
        $snapshot = $this->capacityReporter->report();

        $this->logger->info('watcher poll started', [
            'event'             => 'poll_started',
            'source'            => 'watcher',
            'correlation_id'    => $correlationId,
            'free_ratio'        => round($snapshot->globalFreeRatio(), 4),
            'threshold'         => $this->capacityThreshold,
            'total_slots'       => $snapshot->totalSlots,
            'free_slots'        => $snapshot->totalFree,
        ]);

        $action = 'no_action';
        if ($snapshot->globalFreeRatio() < $this->capacityThreshold) {
            $action = $this->tryProvision($correlationId);
        }

        if ($this->shouldSampleCardinality()) {
            $this->cardinalitySampler->sample();
            $this->lastCardinalitySampleAt = $this->clock->now()->getTimestamp();
        }

        $this->logger->info('watcher poll complete', [
            'event'          => 'poll_complete',
            'source'         => 'watcher',
            'correlation_id' => $correlationId,
            'action'         => $action,
        ]);
    }

    private function tryProvision(string $correlationId): string
    {
        try {
            $lock = AdvisoryLock::acquire($this->pdo, 'stardust_page_provision', $this->provisionLockTimeoutSeconds);
        } catch (AdvisoryLockTimeoutException $e) {
            $this->logger->warning('page provision lock contention', [
                'event'          => 'lock_contention',
                'source'         => 'watcher',
                'correlation_id' => $correlationId,
                'message'        => $e->getMessage(),
            ]);
            return 'lock_contention';
        }

        try {
            $this->logger->info('page provision started', [
                'event'          => 'provision_started',
                'source'         => 'watcher',
                'correlation_id' => $correlationId,
            ]);

            $pageId = $this->pageProvisioner->provision([]);

            $this->logger->info('page provision complete', [
                'event'          => 'provision_complete',
                'source'         => 'watcher',
                'correlation_id' => $correlationId,
                'page_id'        => $pageId,
            ]);

            return 'provisioned';
        } catch (Throwable $e) {
            $this->logger->error('page provision failed', [
                'event'          => 'provision_failed',
                'source'         => 'watcher',
                'correlation_id' => $correlationId,
                'message'        => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    private function shouldSampleCardinality(): bool
    {
        if ($this->lastCardinalitySampleAt === null) {
            $this->lastCardinalitySampleAt = $this->clock->now()->getTimestamp();
            return false;
        }
        $now = $this->clock->now()->getTimestamp();
        $jitter = (int) round($this->cardinalityIntervalSeconds * 0.10);
        return ($now - $this->lastCardinalitySampleAt) >= ($this->cardinalityIntervalSeconds - $jitter);
    }
}
