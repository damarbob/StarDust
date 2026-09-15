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
use StarDust\Logging\StdoutNdjsonLogger;
use StarDust\Reconciler\Reconciler;
use StarDust\Tests\Smoke\Phase6aTestCase;

/**
 * ADR 0048 combined tick: Watcher + Liberator + Reconciler bounded into
 * one budget-limited run over one connection, for the shared-hosting
 * deployment mode.
 */
final class CombinedTickTest extends Phase6aTestCase
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
    ): CombinedTick {
        $log = $logger ?? new NullLogger();

        return new CombinedTick(
            watcher: $this->makeWatcher($log),
            liberator: $this->makeLiberator($log),
            reconciler: $reconciler ?? $this->makeReconciler($log),
            logger: $log,
            clock: $clock ?? new SystemClock(),
            shutdown: $this->neverShuttingDown(),
            pidFileDir: $this->pidDir,
        );
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
