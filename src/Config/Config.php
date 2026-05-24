<?php

declare(strict_types=1);

namespace StarDust\Config;

use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Clock\SystemClock;
use StarDust\Logging\StdoutNdjsonLogger;

/**
 * Construction-time configuration object per ADR 0026.
 *
 * The constructor is append-only: every new optional field arrives
 * after every existing one and defaults to a value that preserves
 * Phase-N-1 behaviour. Existing fields are never reordered, renamed,
 * or repurposed.
 */
final class Config
{
    public readonly LoggerInterface $logger;
    public readonly ClockInterface $clock;
    public readonly string $artifactDir;
    public readonly int $watcherPollIntervalSeconds;
    public readonly float $watcherCapacityThreshold;
    public readonly int $watcherProvisionLockTimeoutSeconds;
    public readonly int $cardinalityIntervalSeconds;
    public readonly float $cardinalitySelectivityThreshold;
    public readonly int $cardinalityRowFloor;
    public readonly int $cardinalityDistinctFloor;
    public readonly int $reconcilerChunkSize;
    public readonly int $reconcilerInterChunkDelayMicros;
    public readonly int $reconcilerCapacityWaitMillis;
    public readonly string $pidFileDir;

    public function __construct(
        public readonly PDO $pdo,
        ?LoggerInterface $logger = null,
        ?ClockInterface $clock = null,
        ?string $artifactDir = null,
        ?int $watcherPollIntervalSeconds = null,
        ?float $watcherCapacityThreshold = null,
        ?int $watcherProvisionLockTimeoutSeconds = null,
        ?int $cardinalityIntervalSeconds = null,
        ?float $cardinalitySelectivityThreshold = null,
        ?int $cardinalityRowFloor = null,
        ?int $cardinalityDistinctFloor = null,
        ?int $reconcilerChunkSize = null,
        ?int $reconcilerInterChunkDelayMicros = null,
        ?int $reconcilerCapacityWaitMillis = null,
        ?string $pidFileDir = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->logger = $logger ?? new StdoutNdjsonLogger($this->clock);
        // ADR 0011 async bulk-ingest artifacts (Phase 3) and ADR 0010
        // export artifacts (Phase 7) land here. Directory creation is
        // deferred to the consumer that actually writes — Config stays
        // side-effect-free per ADR 0026.
        $this->artifactDir = $artifactDir ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stardust');

        // Phase 5 daemon tuning. Blueprint defaults: 60 s poll, 20 %
        // capacity threshold, 500-row reconciler chunks, no inter-chunk
        // delay, 5 s sleep after a capacity_wait. Cardinality advisory
        // runs once per 24 h per ADR 0019 with thresholds matching the
        // ADR's worked example.
        $this->watcherPollIntervalSeconds         = $watcherPollIntervalSeconds         ?? 60;
        $this->watcherCapacityThreshold           = $watcherCapacityThreshold           ?? 0.20;
        // Default 10 s per blueprint AC#2 — `GET_LOCK('stardust_page_provision', 10)`.
        $this->watcherProvisionLockTimeoutSeconds = $watcherProvisionLockTimeoutSeconds ?? 10;
        $this->cardinalityIntervalSeconds         = $cardinalityIntervalSeconds         ?? 86_400;
        $this->cardinalitySelectivityThreshold = $cardinalitySelectivityThreshold ?? 0.01;
        $this->cardinalityRowFloor             = $cardinalityRowFloor             ?? 10_000;
        $this->cardinalityDistinctFloor        = $cardinalityDistinctFloor        ?? 10;
        $this->reconcilerChunkSize             = $reconcilerChunkSize             ?? 500;
        $this->reconcilerInterChunkDelayMicros = $reconcilerInterChunkDelayMicros ?? 0;
        $this->reconcilerCapacityWaitMillis    = $reconcilerCapacityWaitMillis    ?? 5_000;
        $this->pidFileDir = $pidFileDir ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stardust');
    }
}
