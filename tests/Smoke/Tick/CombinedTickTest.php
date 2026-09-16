<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Tick;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use StarDust\Clock\SystemClock;
use StarDust\Daemon\CombinedTick;
use StarDust\Daemon\PidFileGuard;
use StarDust\Daemon\ShutdownSignal;
use StarDust\Daemon\TickBudget;
use StarDust\Daemon\TickStopReason;
use StarDust\Export\ExportJobRequest;
use StarDust\Logging\StdoutNdjsonLogger;
use StarDust\Reconciler\Reconciler;
use StarDust\Tests\Smoke\Phase7TestCase;

/**
 * ADR 0048 combined tick: Watcher + Liberator + Reconciler (and, opt-in
 * via `$exports`, the Chronicler per ADR 0050) bounded into one
 * budget-limited run over one connection, for the shared-hosting
 * deployment mode.
 *
 * Extends `Phase7TestCase` (not just `Phase6aTestCase`) so the exports
 * tests below can reuse the Chronicler fixture helpers alongside the
 * Watcher/Liberator/Reconciler ones every other test here already used.
 */
final class CombinedTickTest extends Phase7TestCase
{
    private string $pidDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pidDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stardust-tick-' . bin2hex(random_bytes(4));
        mkdir($this->pidDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->pidDir) && is_dir($this->pidDir)) {
            foreach (glob($this->pidDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->pidDir);
        }
        parent::tearDown();
    }

    public function testIdleRunStopsAfterOneRound(): void
    {
        $stream = fopen('php://memory', 'w+');
        $logger = new StdoutNdjsonLogger(new SystemClock(), $stream);

        $tick = $this->makeCombinedTick(logger: $logger);
        $report = $tick->run(TickBudget::resolve(50, 5));

        self::assertSame(1, $report->rounds);
        self::assertSame(TickStopReason::IDLE, $report->stopReason);

        $events = array_map(static fn (array $e) => $e['event'], $this->readNdjsonStream($stream));
        self::assertContains('tick_started', $events);
        self::assertContains('tick_complete', $events);
        // The Watcher's own poll_started/poll_complete fire too — this
        // run is one process, so all of it lands on the same stream.
        self::assertContains('poll_started', $events);
    }

    public function testSyncQueueDrainsWithNoSeparateReconcilerProcess(): void
    {
        [$modelId, $_fieldId, $pageId, $fieldName] = $this->setupModelWithReservedField(1, 'string');

        $entryIds = [];
        for ($i = 0; $i < 3; $i++) {
            $entryIds[] = $this->seedEntry(1, $modelId, [$fieldName => 'value-' . $i]);
        }

        // Clear the slot rows so the Reconciler has real work to do,
        // mirroring SyncQueueDrainTest::testDrainsAllQueuedRows.
        $tableName = $this->pageTableNameFor($pageId);
        $this->pdo->exec("DELETE FROM {$tableName}");

        foreach ($entryIds as $id) {
            $this->enqueueSyncRow($id);
        }

        $report = $this->makeCombinedTick()->run(TickBudget::resolve(50, 5));

        self::assertSame(TickStopReason::IDLE, $report->stopReason);
        self::assertSame(0, $this->countQueueRows(), 'The combined run must drain the queue on its own — no separate reconciler process is running.');
        self::assertSame(3, $this->countSlotRows($tableName));
    }

    public function testTombstonedSlotReclaimsInsideTheSameRun(): void
    {
        [$modelId, $fieldId, $pageId, $_fieldName] = $this->setupModelWithReservedField(1, 'string');
        $tableName = $this->pageTableNameFor($pageId);
        $slotAssignmentId = $this->slotAssignmentIdFor($fieldId);
        $slotColumn = $this->slotColumnFor($slotAssignmentId);

        $this->seedSlotValues($modelId, $tableName, $slotColumn, 10, 'before-sweep');
        $this->tombstoneSlotAssignment($slotAssignmentId);

        $report = $this->makeCombinedTick()->run(TickBudget::resolve(50, 5));

        self::assertSame(TickStopReason::IDLE, $report->stopReason);
        self::assertSame(0, $this->countNonNullValues($tableName, $slotColumn));
        self::assertSame('free', $this->fetchSlotAssignment($slotAssignmentId)['status']);
    }

    public function testBudgetSpentStopsABusyRunBeforeItDrains(): void
    {
        [$modelId, $_fieldId, $pageId, $fieldName] = $this->setupModelWithReservedField(1, 'string');

        $entryIds = [];
        for ($i = 0; $i < 5; $i++) {
            $entryIds[] = $this->seedEntry(1, $modelId, [$fieldName => 'value-' . $i]);
        }
        $tableName = $this->pageTableNameFor($pageId);
        $this->pdo->exec("DELETE FROM {$tableName}");
        foreach ($entryIds as $id) {
            $this->enqueueSyncRow($id);
        }

        // chunkSize 1 — one queue row drained per round, so the round
        // count is observable and controllable.
        $reconciler = new Reconciler(
            workSources: [$this->makeSyncQueueWorkSource(chunkSize: 1)],
            capacityWaitMillis: 0,
            interChunkDelayMicros: 0,
            sleepFn: static fn (int $_micros) => null,
        );

        // Advances by exactly 1 simulated second on every now() call.
        // CombinedTick reads it once per round (the elapsed check) plus
        // once at the top and once at the end, so a budget of 3 stops
        // the loop after exactly 3 rounds regardless of wall-clock time.
        $clock = $this->advancingClock();

        $tick = $this->makeCombinedTick(reconciler: $reconciler, clock: $clock);
        $report = $tick->run(TickBudget::resolve(3, 0));

        self::assertSame(TickStopReason::BUDGET_SPENT, $report->stopReason);
        self::assertSame(3, $report->rounds);
        self::assertSame(2, $this->countQueueRows(), 'Only 3 of 5 rows should have drained before the budget stopped the run.');
    }

    public function testHeldWatcherPidFileReportsLockContendedWithoutTouchingTheDatabase(): void
    {
        $guard = PidFileGuard::acquire($this->pidDir, 'watcher');

        try {
            $report = $this->makeCombinedTick()->run(TickBudget::resolve(50, 5));

            self::assertSame(TickStopReason::LOCK_CONTENDED, $report->stopReason);
            // rounds stays 0 because CombinedTick returns before ever
            // calling into the Watcher, Liberator or Reconciler — the
            // only place any of them touches the database.
            self::assertSame(0, $report->rounds);
        } finally {
            $guard->release();
        }
    }

    private function makeCombinedTick(
        ?Reconciler $reconciler = null,
        ?\Psr\Log\LoggerInterface $logger = null,
        ?ClockInterface $clock = null,
        ?string $artifactDir = null,
        int $diskProbeBytes = 0,
    ): CombinedTick {
        $log = $logger ?? new NullLogger();

        return new CombinedTick(
            watcher: $this->makeWatcher($log),
            liberator: $this->makeLiberator($log),
            reconciler: $reconciler ?? $this->makeReconciler($log),
            // Most tests here never pass `exports: true` to run(), so
            // this Chronicler's tickRound() is never actually invoked —
            // it exists only to satisfy the constructor. The exports
            // tests below pass their own `$artifactDir`.
            chronicler: $this->makeChronicler(
                $log,
                artifactDir: $artifactDir,
                pageSize: 4,
                diskProbeBytes: $diskProbeBytes,
            ),
            logger: $log,
            clock: $clock ?? new SystemClock(),
            shutdown: $this->neverShuttingDown(),
            pidFileDir: $this->pidDir,
        );
    }

    /**
     * A large (multi-chunk) export composed into a real, budget-bounded
     * `tick` run must yield at a chunk boundary once the run's own
     * budget is spent, reporting `BUDGET_SPENT` rather than `IDLE` —
     * proving `CombinedTick`'s own budget check (between rounds only)
     * is not what bounds it, since a single round's Chronicler call
     * would otherwise run the whole job to completion regardless of
     * `$budget`.
     */
    public function testExportYieldsWhenTheRunsOwnBudgetIsSpent(): void
    {
        $modelId = $this->createModel(1, 'tick_export_budget');
        $this->createFieldNamed($modelId, 'idx', 'int');
        $this->seedEntryDataBatch(1, $modelId, 20); // 5 chunks at pageSize 4
        $artifactDir = $this->makeTempArtifactDir();

        $jobId = $this->makeExportSubmitter()
            ->submit(new ExportJobRequest(1, $modelId, 'csv'))
            ->jobId;

        // Deadline in the past from the very first check: the run's
        // budget is already spent before the Chronicler round even
        // opens its first chunk, so exactly one committed chunk lands
        // before the yield.
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('@1000000000');
            }
        };

        $tick = $this->makeCombinedTick(clock: $clock, artifactDir: $artifactDir);
        $report = $tick->run(TickBudget::resolve(0, 0), exports: true);

        self::assertSame(TickStopReason::BUDGET_SPENT, $report->stopReason);

        $row = $this->fetchExportJob($jobId);
        self::assertSame('pending', $row['status'], 'The export must have yielded, not completed, in one round.');
        self::assertNotNull($row['artifact_path']);
    }

    /**
     * The same export, driven across several INDEPENDENT `run()`
     * calls — each with its own zero-second budget, so each call's
     * Chronicler round can commit at most one chunk before yielding —
     * must converge to `completed` over more than one invocation,
     * exactly as two separate cron firings would drive it. A weaker
     * "eventually completed" assertion with a generous budget would
     * pass trivially in a single call and prove nothing about
     * cross-invocation resumption.
     */
    public function testExportConvergesAcrossSeparateTickInvocations(): void
    {
        $modelId = $this->createModel(1, 'tick_export_converge');
        $this->createFieldNamed($modelId, 'idx', 'int');
        $this->seedEntryDataBatch(1, $modelId, 20); // 5 chunks at pageSize 4
        $artifactDir = $this->makeTempArtifactDir();

        $jobId = $this->makeExportSubmitter()
            ->submit(new ExportJobRequest(1, $modelId, 'csv'))
            ->jobId;

        $status = null;
        $attempts = 0;
        for (; $attempts < 10; $attempts++) {
            // A fresh CombinedTick and a fresh zero-second TickBudget
            // every call — an independent invocation in every sense
            // that matters, the same as two separate cron firings.
            $report = $this->makeCombinedTick(artifactDir: $artifactDir)
                ->run(TickBudget::resolve(0, 0), exports: true);
            $status = $this->fetchExportJob($jobId)['status'];
            if ($status === 'completed') {
                break;
            }
            self::assertSame('pending', $status, "Unexpected status on attempt {$attempts}.");
        }

        self::assertSame('completed', $status);
        self::assertGreaterThan(1, $attempts, 'A zero-second budget per call must force more than one invocation.');
        $rows = $this->readArtifactCsv((string) $this->fetchExportJob($jobId)['artifact_path']);
        self::assertCount(20, $rows);
        self::assertSame(range(0, 19), array_map(static fn (array $r): int => (int) $r['idx'], $rows));
    }

    public function testExportsFalseNeverInvokesTheChronicler(): void
    {
        $modelId = $this->createModel(1, 'tick_export_off');
        $this->createFieldNamed($modelId, 'idx', 'int');
        $this->seedEntryDataBatch(1, $modelId, 3);
        $jobId = $this->makeExportSubmitter()
            ->submit(new ExportJobRequest(1, $modelId, 'csv'))
            ->jobId;

        $report = $this->makeCombinedTick()->run(TickBudget::resolve(50, 5)); // exports defaults false

        self::assertSame(TickStopReason::IDLE, $report->stopReason);
        self::assertSame('pending', $this->fetchExportJob($jobId)['status'], 'Untouched — exports were never composed into this run.');
    }

    /**
     * A path whose parent is a regular file, so `mkdir` can never
     * succeed — the cheapest genuine "artifact directory is
     * unwritable" condition, with no stream wrapper involved.
     */
    private function unwritableArtifactDir(): string
    {
        $blocker = $this->makeTempArtifactDir() . DIRECTORY_SEPARATOR . 'blocker.txt';
        file_put_contents($blocker, 'not a directory');
        return $blocker . DIRECTORY_SEPARATOR . 'artifacts';
    }

    /**
     * ADR 0051's fail-closed regression test, and the reason the probe
     * defaults to on.
     *
     * With the probe enabled, an unwritable `artifactDir` makes the
     * gate decline to claim: the run stays `IDLE`, exits cleanly, and
     * the registry daemons that ran before the Chronicler keep their
     * round loop.
     */
    public function testUnwritableArtifactDirWithProbeStaysIdleRatherThanCrashing(): void
    {
        $modelId = $this->createModel(1, 'tick_probe_guard');
        $this->createFieldNamed($modelId, 'idx', 'int');
        $this->seedEntryDataBatch(1, $modelId, 3);
        $jobId = $this->makeExportSubmitter()
            ->submit(new ExportJobRequest(1, $modelId, 'csv'))
            ->jobId;

        $report = $this->makeCombinedTick(
            artifactDir: $this->unwritableArtifactDir(),
            diskProbeBytes: 4096,
        )->run(TickBudget::resolve(50, 5), exports: true);

        self::assertSame(TickStopReason::IDLE, $report->stopReason);
        $row = $this->fetchExportJob($jobId);
        self::assertSame('pending', $row['status'], 'declined, not claimed-then-crashed');
        self::assertNull($row['worker_identity']);
    }

    /**
     * The counterfactual that makes the test above non-vacuous.
     *
     * Without the probe the gate cannot see the problem, so the job is
     * claimed and flipped to `processing` — and then
     * `ExportJobProcessor` builds its stream OUTSIDE any try block, so
     * the `RuntimeException` propagates through `tickRound()` and out
     * of `CombinedTick::run()`, which has a `finally` but no `catch`.
     * On a cron-only host that is a nonzero exit every invocation,
     * with the whole round's registry work truncated (ADR 0048's
     * one-failure-domain rule).
     */
    public function testUnwritableArtifactDirWithoutProbeCrashesTheWholeRun(): void
    {
        $modelId = $this->createModel(1, 'tick_probe_absent');
        $this->createFieldNamed($modelId, 'idx', 'int');
        $this->seedEntryDataBatch(1, $modelId, 3);
        $this->makeExportSubmitter()->submit(new ExportJobRequest(1, $modelId, 'csv'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/artifact directory .* is not writable/');

        $this->makeCombinedTick(
            artifactDir: $this->unwritableArtifactDir(),
            diskProbeBytes: 0, // the pre-ADR-0051 gate
        )->run(TickBudget::resolve(50, 5), exports: true);
    }

    private function neverShuttingDown(): ShutdownSignal
    {
        return new class implements ShutdownSignal {
            public function isRequested(): bool
            {
                return false;
            }
        };
    }

    private function advancingClock(int $stepSeconds = 1, int $startTs = 1_700_000_000): ClockInterface
    {
        return new class($startTs, $stepSeconds) implements ClockInterface {
            public function __construct(private int $ts, private readonly int $step)
            {
            }

            public function now(): DateTimeImmutable
            {
                $current = $this->ts;
                $this->ts += $this->step;
                return new DateTimeImmutable('@' . $current);
            }
        };
    }

    private function countQueueRows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_sync_queue')->fetchColumn();
    }

    private function countSlotRows(string $tableName): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$tableName}")->fetchColumn();
    }

    private function slotAssignmentIdFor(int $fieldId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM stardust_slot_assignments WHERE field_id = ?');
        $stmt->execute([$fieldId]);
        return (int) $stmt->fetchColumn();
    }

    private function slotColumnFor(int $slotAssignmentId): string
    {
        $stmt = $this->pdo->prepare('SELECT slot_column FROM stardust_slot_assignments WHERE id = ?');
        $stmt->execute([$slotAssignmentId]);
        return (string) $stmt->fetchColumn();
    }
}
