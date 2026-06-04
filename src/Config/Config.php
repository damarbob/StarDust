<?php

declare(strict_types=1);

namespace StarDust\Config;

use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Chronicler\PdoConnector;
use StarDust\Clock\SystemClock;
use StarDust\Filter\Limits\FilterLimits;
use StarDust\Logging\StdoutNdjsonLogger;
use StarDust\Search\EntrySearchInterface;

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
    public readonly int $liberatorIdleIntervalSeconds;
    public readonly int $liberatorBatchSize;
    public readonly int $liberatorChunkSize;
    public readonly int $liberatorInterChunkDelayMicros;
    public readonly int $liberatorDeadlockRetryBudget;
    public readonly int $chroniclerIdleIntervalSeconds;
    public readonly int $chroniclerLeaseTimeoutSeconds;
    public readonly int $chroniclerPageSize;
    public readonly int $chroniclerInterChunkDelayMicros;
    public readonly int $chroniclerDeadlockRetryBudget;
    public readonly int $chroniclerSkipCountCap;
    public readonly int $chroniclerArtifactSizeCapBytes;
    public readonly int $chroniclerArtifactTtlSeconds;
    public readonly int $chroniclerOrphanedPartialTtlSeconds;
    public readonly float $chroniclerLowDiskThresholdPct;
    public readonly int $chroniclerPerTenantActiveCap;
    /** @var list<int> */
    public readonly array $chroniclerDbDisconnectBackoffSeconds;
    public readonly ?EntrySearchInterface $searchDriver;
    public readonly FilterLimits $queryFilterLimits;
    public readonly ?PdoConnector $pdoConnector;

    /**
     * @param list<int>|null $chroniclerDbDisconnectBackoffSeconds
     */
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
        ?int $liberatorIdleIntervalSeconds = null,
        ?int $liberatorBatchSize = null,
        ?int $liberatorChunkSize = null,
        ?int $liberatorInterChunkDelayMicros = null,
        ?int $liberatorDeadlockRetryBudget = null,
        ?int $chroniclerIdleIntervalSeconds = null,
        ?int $chroniclerLeaseTimeoutSeconds = null,
        ?int $chroniclerPageSize = null,
        ?int $chroniclerInterChunkDelayMicros = null,
        ?int $chroniclerDeadlockRetryBudget = null,
        ?int $chroniclerSkipCountCap = null,
        ?int $chroniclerArtifactSizeCapBytes = null,
        ?int $chroniclerArtifactTtlSeconds = null,
        ?int $chroniclerOrphanedPartialTtlSeconds = null,
        ?float $chroniclerLowDiskThresholdPct = null,
        ?int $chroniclerPerTenantActiveCap = null,
        ?array $chroniclerDbDisconnectBackoffSeconds = null,
        ?EntrySearchInterface $searchDriver = null,
        ?FilterLimits $queryFilterLimits = null,
        ?PdoConnector $pdoConnector = null,
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

        // Phase 6a Liberator tuning. Defaults pin ADR 0009's normative
        // parameters in production (chunk size 500, deadlock budget 3,
        // 10 s idle interval per blueprint AC#13); the fields exist so
        // tests can shorten them through the same code path, mirroring
        // the Watcher's `watcherProvisionLockTimeoutSeconds` rationale.
        $this->liberatorIdleIntervalSeconds   = $liberatorIdleIntervalSeconds   ?? 10;
        $this->liberatorBatchSize             = $liberatorBatchSize             ?? 50;
        $this->liberatorChunkSize             = $liberatorChunkSize             ?? 500;
        $this->liberatorInterChunkDelayMicros = $liberatorInterChunkDelayMicros ?? 0;
        $this->liberatorDeadlockRetryBudget   = $liberatorDeadlockRetryBudget   ?? 3;

        // Phase 7 Chronicler tuning. Defaults pin ADR 0025's normative
        // parameters and the chronicler blueprint's §2 caps. The fields
        // exist so tests can shorten timeouts/caps through the same code
        // path, mirroring the Liberator/Watcher rationale.
        $this->chroniclerIdleIntervalSeconds       = $chroniclerIdleIntervalSeconds       ?? 10;
        $this->chroniclerLeaseTimeoutSeconds       = $chroniclerLeaseTimeoutSeconds       ?? 30;
        $this->chroniclerPageSize                  = $chroniclerPageSize                  ?? 500;
        $this->chroniclerInterChunkDelayMicros     = $chroniclerInterChunkDelayMicros     ?? 0;
        $this->chroniclerDeadlockRetryBudget       = $chroniclerDeadlockRetryBudget       ?? 3;
        $this->chroniclerSkipCountCap              = $chroniclerSkipCountCap              ?? 1_000;
        $this->chroniclerArtifactSizeCapBytes      = $chroniclerArtifactSizeCapBytes      ?? (5 * 1024 * 1024 * 1024);
        $this->chroniclerArtifactTtlSeconds        = $chroniclerArtifactTtlSeconds        ?? 86_400;
        $this->chroniclerOrphanedPartialTtlSeconds = $chroniclerOrphanedPartialTtlSeconds ?? 3_600;
        $this->chroniclerLowDiskThresholdPct       = $chroniclerLowDiskThresholdPct       ?? 0.10;
        $this->chroniclerPerTenantActiveCap        = $chroniclerPerTenantActiveCap        ?? 3;
        // ADR 0025 pins the schedule at [1, 4, 16]; the field is
        // injectable so tests can shorten the cumulative wait.
        $this->chroniclerDbDisconnectBackoffSeconds = $chroniclerDbDisconnectBackoffSeconds ?? [1, 4, 16];

        // Phase 8 search driver injection (ADR 0026 construction-time
        // injection). `null` defaults to the engine's MysqlNativeDriver,
        // lazily instantiated in StarDust::searchService(). Filter
        // limits default to the QueryFilter wire-format blueprint §4.6
        // normative values; injectable for operators with tighter or
        // looser bounds.
        $this->searchDriver       = $searchDriver;
        $this->queryFilterLimits  = $queryFilterLimits ?? FilterLimits::defaults();

        // Optional construction-time reconnect seam (ADR 0025
        // Commitment 6). `null` leaves the Chronicler unable to recover
        // a dropped connection mid-export — it degrades to the terminal
        // `failed:query_failure` with `last_cursor` preserved.
        // `bin/stardust chronicler` injects a DsnPdoConnector built from
        // the same env vars as $pdo.
        $this->pdoConnector       = $pdoConnector;
    }
}
