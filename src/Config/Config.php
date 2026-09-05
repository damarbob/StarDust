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
    public readonly int $cardinalityJitterSeconds;
    public readonly float $cardinalitySelectivityThreshold;
    public readonly int $cardinalityRowFloor;
    public readonly int $cardinalityDistinctFloor;
    public readonly int $spreadExcessPageThreshold;

    /** ADR 0042 — indexed columns per slot family on every newly provisioned page. */
    public readonly int $pageIndexHeadroom;

    /** ADR 0038 model purge — see the constructor for why it is not `reconcilerChunkSize`. */
    public readonly int $modelPurgeChunkSize;
    public readonly int $modelPurgeLockRetryBudget;
    public readonly int $reconcilerChunkSize;
    public readonly int $reconcilerInterChunkDelayMicros;
    public readonly int $reconcilerCapacityWaitMillis;
    public readonly int $reconcilerLockRetryBudget;
    public readonly int $reconcilerLockRetryDelayMicros;
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
    public readonly int $reconcilerImportLeaseTimeoutSeconds;

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
        ?int $reconcilerImportLeaseTimeoutSeconds = null,
        ?int $cardinalityJitterSeconds = null,
        ?int $spreadExcessPageThreshold = null,
        ?int $modelPurgeChunkSize = null,
        ?int $modelPurgeLockRetryBudget = null,
        ?int $reconcilerLockRetryBudget = null,
        ?int $reconcilerLockRetryDelayMicros = null,
        ?int $pageIndexHeadroom = null,
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
        // Randomized ± window around the periodic cardinality cadence. The
        // Watcher draws a fresh offset in [-jitter, +jitter] each cycle and
        // phase-randomizes the first sample across the whole interval, so a
        // fleet started in lockstep does not stampede on the same schedule.
        // Default 10 % of the interval (8 640 s for the 24 h default).
        $this->cardinalityJitterSeconds        = $cardinalityJitterSeconds
            ?? (int) round($this->cardinalityIntervalSeconds * 0.10);
        // ADR 0031: `high_spread_model` fires only when a model occupies
        // at least two more pages than it needs. The default deliberately
        // ignores one avoidable join — rarely worth a compaction's cost —
        // and only alerts once fragmentation has compounded. Tighten to 1
        // for latency-critical fleets.
        $this->spreadExcessPageThreshold       = $spreadExcessPageThreshold       ?? 2;
        $this->cardinalitySelectivityThreshold = $cardinalitySelectivityThreshold ?? 0.01;
        $this->cardinalityRowFloor             = $cardinalityRowFloor             ?? 10_000;
        $this->cardinalityDistinctFloor        = $cardinalityDistinctFloor        ?? 10;
        $this->reconcilerChunkSize             = $reconcilerChunkSize             ?? 500;
        $this->reconcilerInterChunkDelayMicros = $reconcilerInterChunkDelayMicros ?? 0;
        $this->reconcilerCapacityWaitMillis    = $reconcilerCapacityWaitMillis    ?? 5_000;

        // Bounded in-tick retry for InnoDB lock failures (errno 1205 /
        // 1213) across the Reconciler's work sources. Budget 3 matches
        // `liberatorDeadlockRetryBudget` and `modelPurgeLockRetryBudget`
        // — the same phenomenon deserves the same default. On exhaustion
        // the source returns `TickOutcome::LOCK_WAIT` rather than
        // throwing, and the back-off sleep borrows
        // `reconcilerCapacityWaitMillis` instead of a third knob.
        $this->reconcilerLockRetryBudget       = $reconcilerLockRetryBudget       ?? 3;
        $this->reconcilerLockRetryDelayMicros  = $reconcilerLockRetryDelayMicros  ?? 0;
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

        // Import-job abandoned-claim recovery (Gap 5). The Reconciler
        // re-claims a `processing` import job whose `heartbeat_at` lapsed
        // past this timeout and resumes from the manifest checkpoint.
        // Default 30 s mirrors $chroniclerLeaseTimeoutSeconds; injectable
        // so tests can shorten the lease through the same code path.
        $this->reconcilerImportLeaseTimeoutSeconds = $reconcilerImportLeaseTimeoutSeconds ?? 30;

        // ADR 0038 model purge. Deliberately NOT $reconcilerChunkSize,
        // and the reason is not "the purge is slower" — it is that 500
        // means something structurally different here. Every other work
        // source's chunk is N row updates on one table; a model-purge
        // chunk is N `entry_data` deletes that CASCADE into N × (pages
        // the model occupies) extension-table deletes, plus up to N
        // sync-queue deletes, all in one transaction, contending with the
        // Liberator over the same page rows. Tuning the shared knob down
        // to make a large purge survivable would simultaneously slow the
        // sync-queue drain, which is the ADR 0007 write-availability path.
        //
        // 200 rather than 500 because the cascade multiplies the row
        // count by (1 + pages occupied).
        $this->modelPurgeChunkSize = $modelPurgeChunkSize ?? 200;

        // Bounded retry for errno 1205 / 1213 on a purge chunk. 3 mirrors
        // $liberatorDeadlockRetryBudget and $chroniclerDeadlockRetryBudget,
        // which are the precedent for a per-subsystem budget rather than a
        // shared one. Note the purge has no gap path: on exhaustion it
        // rethrows with the cursor untouched rather than skipping rows.
        $this->modelPurgeLockRetryBudget = $modelPurgeLockRetryBudget ?? 3;

        // ADR 0042 index headroom: columns of EVERY slot family indexed on
        // a newly provisioned page, over and above current demand. Fixed
        // per page at creation (ADR 0012), so raising it never widens a
        // page that already exists.
        //
        // Not validated, and it does not need to be: ProvisioningPlanner
        // clamps to [0, family capacity] and applies its own floor of one
        // column per demanded family, so no value here can starve a waiter
        // or name a column that does not exist.
        $this->pageIndexHeadroom = $pageIndexHeadroom ?? 1;
    }
}
