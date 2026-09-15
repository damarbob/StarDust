<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Liberator;

use PDO;
use Psr\Log\NullLogger;
use StarDust\Clock\SystemClock;
use StarDust\Daemon\CombinedTick;
use StarDust\Daemon\ShutdownSignal;
use StarDust\Daemon\TickBudget;
use StarDust\Daemon\TickStopReason;
use StarDust\Logging\StdoutNdjsonLogger;
use StarDust\Tests\Smoke\Phase6aTestCase;

/**
 * ADR 0049: multi-worker Liberator exclusion at page-table granularity
 * via `GET_LOCK('stardust_sweep_page_{pageId}', 0)`.
 *
 * Two-session shape mirrors
 * `Chronicler\ChroniclerMultiWorkerClaimTest` — a sibling PDO session
 * plays the role of a second, concurrent Liberator worker. Tests that
 * hold a page's advisory lock manually always release it in `finally`,
 * since `GET_LOCK` only releases on `RELEASE_LOCK` or connection death
 * and PHPUnit keeps the sibling connection open for the rest of the
 * process otherwise.
 */
final class LiberatorMultiWorkerTest extends Phase6aTestCase
{
    private string $pidDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pidDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stardust-liberator-mw-' . bin2hex(random_bytes(4));
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

    public function testHeldPageLockMakesTheSweeperSkipThatSlot(): void
    {
        [$modelId, $fieldId, $pageId, $_fieldName] = $this->setupModelWithReservedField(1, 'string');
        $tableName = $this->pageTableNameFor($pageId);
        $slotAssignmentId = $this->slotAssignmentIdFor($fieldId);
        $slotColumn = $this->slotColumnFor($slotAssignmentId);

        $this->seedSlotValues($modelId, $tableName, $slotColumn, 5);
        $this->tombstoneSlotAssignment($slotAssignmentId);
        $before = $this->fetchSlotAssignment($slotAssignmentId);

        $sibling = $this->makeSiblingPdo();
        $lockName = self::pageLockName($pageId);

        try {
            self::assertSame(1, $this->rawGetLock($sibling, $lockName), 'Fixture: sibling must actually hold the lock.');

            $stream = fopen('php://memory', 'r+');
            self::assertNotFalse($stream);
            $logger = new StdoutNdjsonLogger(new SystemClock(), $stream);

            $swept = $this->makeLiberator(logger: $logger)->sweepBatch();

            self::assertSame(0, $swept, 'The whole batch was contended; nothing was actually swept.');
            self::assertSame(
                [],
                $this->readNdjsonStream($stream),
                'A fully-contended batch must emit nothing — AC#13 extended to "everything already spoken for."',
            );

            $after = $this->fetchSlotAssignment($slotAssignmentId);
            self::assertSame('tombstoned', $after['status']);
            self::assertSame($before['sweep_cursor_id'], $after['sweep_cursor_id']);
            self::assertSame(5, $this->countNonNullValues($tableName, $slotColumn));
        } finally {
            $this->rawReleaseLock($sibling, $lockName);
        }
    }

    public function testExclusionIsPerPageNotGlobal(): void
    {
        // Field A lands naturally on the only page that exists yet.
        [$modelIdA, $fieldIdA, $pageIdA, $_a] = $this->setupModelWithReservedField(1, 'string');

        // Field B is forced onto a SECOND, distinct page: SlotReserver
        // packs onto the oldest page with free capacity first, so a
        // second natural reservation would land back on page A rather
        // than proving anything about cross-page independence — the
        // same bypass precedent `forceGrandfatheredSlotFor()` and
        // `Watcher\SlotAffinityTest` already document.
        $modelIdB = $this->createModel(1, 'liberator_mw_b');
        $fieldIdB = $this->createField($modelIdB, 'string', true, 'liberator_mw_b_field');
        $pageIdB = $this->provisionLegacyPage();
        self::assertNotSame($pageIdA, $pageIdB, 'Fixture: the two fields must land on different pages.');
        $slotAssignmentIdB = $this->assignSlotOnPage($fieldIdB, $pageIdB);

        $tableA = $this->pageTableNameFor($pageIdA);
        $slotAssignmentIdA = $this->slotAssignmentIdFor($fieldIdA);
        $slotColumnA = $this->slotColumnFor($slotAssignmentIdA);
        $this->seedSlotValues($modelIdA, $tableA, $slotColumnA, 3);
        $this->tombstoneSlotAssignment($slotAssignmentIdA);

        $tableB = $this->pageTableNameFor($pageIdB);
        $slotColumnB = $this->slotColumnFor($slotAssignmentIdB);
        $this->seedSlotValues($modelIdB, $tableB, $slotColumnB, 3);
        $this->tombstoneSlotAssignment($slotAssignmentIdB);

        $sibling = $this->makeSiblingPdo();
        $lockNameA = self::pageLockName($pageIdA);

        try {
            self::assertSame(1, $this->rawGetLock($sibling, $lockNameA), 'Fixture: sibling must hold page A.');

            $swept = $this->makeLiberator()->sweepBatch();

            self::assertSame(1, $swept, 'Exactly the unlocked page (B) is swept — this is the throughput proof.');

            $rowA = $this->fetchSlotAssignment($slotAssignmentIdA);
            self::assertSame('tombstoned', $rowA['status'], 'Page A stays untouched while its lock is held.');
            self::assertSame(3, $this->countNonNullValues($tableA, $slotColumnA));

            $rowB = $this->fetchSlotAssignment($slotAssignmentIdB);
            self::assertSame('free', $rowB['status'], 'Page B has no contention and reclaims normally.');
            self::assertSame(0, $this->countNonNullValues($tableB, $slotColumnB));
        } finally {
            $this->rawReleaseLock($sibling, $lockNameA);
        }
    }

    public function testTheLockIsReleasedAfterASweep(): void
    {
        [$modelId, $fieldId, $pageId, $_fieldName] = $this->setupModelWithReservedField(1, 'string');
        $tableName = $this->pageTableNameFor($pageId);
        $slotAssignmentId = $this->slotAssignmentIdFor($fieldId);
        $slotColumn = $this->slotColumnFor($slotAssignmentId);

        $this->seedSlotValues($modelId, $tableName, $slotColumn, 3);
        $this->tombstoneSlotAssignment($slotAssignmentId);

        $swept = $this->makeLiberator()->sweepBatch();
        self::assertSame(1, $swept);
        self::assertSame('free', $this->fetchSlotAssignment($slotAssignmentId)['status']);

        $sibling = $this->makeSiblingPdo();
        try {
            self::assertSame(
                1,
                $this->rawGetLock($sibling, self::pageLockName($pageId)),
                'The page lock must be free again once the Liberator returns.',
            );
        } finally {
            $this->rawReleaseLock($sibling, self::pageLockName($pageId));
        }
    }

    public function testTwoLiberatorsOnSiblingConnectionsNeverSweepTheSamePage(): void
    {
        [$modelId, $fieldId, $pageId, $_fieldName] = $this->setupModelWithReservedField(1, 'string');
        $tableName = $this->pageTableNameFor($pageId);
        $slotAssignmentId = $this->slotAssignmentIdFor($fieldId);
        $slotColumn = $this->slotColumnFor($slotAssignmentId);

        // Exactly one row with chunkSize=1 gives exactly one pause point
        // — the sweeper's sleepFn call between the one real chunk and
        // the empty final chunk that reclaims — while worker A's
        // connection still holds the page's GET_LOCK (release happens
        // only once sweepBatch() returns). Interleaving a real, second
        // Liberator on a sibling connection from inside that pause is
        // the only way to prove genuine cross-connection exclusion
        // rather than two calls that merely never overlap in time.
        $this->seedSlotValues($modelId, $tableName, $slotColumn, 1);
        $this->tombstoneSlotAssignment($slotAssignmentId);

        $sibling = $this->makeSiblingPdo();
        $interleavedResult = null;

        $liberatorA = $this->makeLiberator(
            chunkSize: 1,
            workerIdentity: 'worker-A',
            sleepFn: function (int $_micros) use ($sibling, &$interleavedResult): void {
                if ($interleavedResult !== null) {
                    return;
                }
                $liberatorB = $this->makeLiberator(pdo: $sibling, workerIdentity: 'worker-B');
                $interleavedResult = $liberatorB->sweepBatch();
            },
        );

        $sweptByA = $liberatorA->sweepBatch();

        self::assertSame(1, $sweptByA, 'A must sweep the one slot end to end.');
        self::assertSame(
            0,
            $interleavedResult,
            'B ran while A still held the page lock — B must have found it contended and swept nothing.',
        );

        $row = $this->fetchSlotAssignment($slotAssignmentId);
        self::assertSame('free', $row['status']);
        self::assertSame(0, $this->countNonNullValues($tableName, $slotColumn));
    }

    public function testAFullyContendedTickReportsZero(): void
    {
        [$modelId, $fieldId, $pageId, $_fieldName] = $this->setupModelWithReservedField(1, 'string');
        $tableName = $this->pageTableNameFor($pageId);
        $slotAssignmentId = $this->slotAssignmentIdFor($fieldId);
        $slotColumn = $this->slotColumnFor($slotAssignmentId);

        $this->seedSlotValues($modelId, $tableName, $slotColumn, 5);
        $this->tombstoneSlotAssignment($slotAssignmentId);

        $sibling = $this->makeSiblingPdo();
        $lockName = self::pageLockName($pageId);

        try {
            self::assertSame(1, $this->rawGetLock($sibling, $lockName));

            $tick = new CombinedTick(
                watcher: $this->makeWatcher(),
                liberator: $this->makeLiberator(),
                reconciler: $this->makeReconciler(),
                logger: new NullLogger(),
                clock: new SystemClock(),
                shutdown: new class implements ShutdownSignal {
                    public function isRequested(): bool
                    {
                        return false;
                    }
                },
                pidFileDir: $this->pidDir,
            );

            $report = $tick->run(TickBudget::resolve(50, 5));

            // Liberator::sweepBatch() must return the CLAIMED count
            // (zero here), not the batch size, or CombinedTick's
            // idle-exit (ADR 0048) would never trigger on a fully
            // contended round.
            self::assertSame(TickStopReason::IDLE, $report->stopReason);
            self::assertSame(1, $report->rounds);

            $row = $this->fetchSlotAssignment($slotAssignmentId);
            self::assertSame('tombstoned', $row['status'], 'The contended slot must remain untouched.');
            self::assertSame(5, $this->countNonNullValues($tableName, $slotColumn));
        } finally {
            $this->rawReleaseLock($sibling, $lockName);
        }
    }

    private static function pageLockName(int $pageId): string
    {
        return 'stardust_sweep_page_' . $pageId;
    }

    private function rawGetLock(PDO $pdo, string $name): mixed
    {
        $stmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->execute([$name]);
        return (int) $stmt->fetchColumn();
    }

    private function rawReleaseLock(PDO $pdo, string $name): void
    {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$name]);
    }

    /**
     * Assigns a field to a free slot on a SPECIFIC page, bypassing
     * `SlotReserver`'s oldest-page-first packing — the only way to
     * land two fields' slots on two different pages deliberately, on
     * the same precedent as `WritePathTestCase::forceGrandfatheredSlotFor()`.
     */
    private function assignSlotOnPage(int $fieldId, int $pageId, string $slotType = 'str'): int
    {
        $select = $this->pdo->prepare(
            'SELECT id FROM stardust_slot_assignments'
            . " WHERE page_id = ? AND status = 'free' AND slot_type = ?"
            . ' ORDER BY id LIMIT 1'
        );
        $select->execute([$pageId, $slotType]);
        $assignmentId = $select->fetchColumn();
        self::assertNotFalse($assignmentId, "No free {$slotType} slot on page {$pageId} to assign field {$fieldId} onto.");

        $update = $this->pdo->prepare(
            'UPDATE stardust_slot_assignments'
            . " SET status = 'assigned', field_id = ?, updated_at = UTC_TIMESTAMP()"
            . ' WHERE id = ?'
        );
        $update->execute([$fieldId, $assignmentId]);

        $this->pdo->exec(
            'UPDATE stardust_schema_version'
            . ' SET version = version + 1, updated_at = UTC_TIMESTAMP()'
            . ' WHERE id = 1'
        );

        return (int) $assignmentId;
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
