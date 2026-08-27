<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Reconciler;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use StarDust\Clock\SystemClock;
use StarDust\Daemon\PollLoop;
use StarDust\Daemon\ShutdownSignal;
use StarDust\Daemon\SleepFunction;
use StarDust\Reconciler\ImportJobWorkSource;
use StarDust\Reconciler\Reconciler;
use StarDust\Reconciler\SyncQueueWorkSource;
use StarDust\Reconciler\TickOutcome;
use StarDust\Retype\RetypeBackfillExecutor;
use StarDust\Retype\RetypeBackfillWorkSource;
use StarDust\Retype\RetypeCheckpointRepository;
use StarDust\Slot\SlotReserver;
use StarDust\Tests\Smoke\Phase6bTestCase;
use StarDust\Tests\Smoke\Support\LockFailureInjectingPdo;
use StarDust\Watcher\CardinalitySampler;
use StarDust\Write\BackfillExecutor;
use StarDust\Write\EntryWriter;
use StarDust\Write\SlotRowUpserter;

/**
 * ROADMAP item 14: a transient InnoDB lock failure must cost a retry and
 * a back-off — never a dead daemon, never a quarantined entry, never a
 * failed import.
 *
 * Item 11 established the shape for the Liberator: `PollLoop`
 * deliberately does not catch tick exceptions, so an unretried errno
 * 1205 exits the process, which then crash-loops for as long as the
 * contending sweep runs. Five of the Reconciler's six work sources had
 * no handling at all, and they failed three *different* ways — hence the
 * three distinct assertions here rather than one parameterised sweep.
 *
 * All injection is errno **1205**, not 40001. Measured under ADR 0038,
 * the collision this engine actually produces gives the loser 1205 in
 * both directions; a fixture injecting only deadlocks would pass against
 * a handler that misses the real case, which is exactly how the gap ADR
 * 0038 closed for the Liberator survived its own test.
 */
final class WorkSourceLockRetryTest extends Phase6bTestCase
{
    /** The slot UPSERT every backfill chunk ends in. */
    private const SLOT_UPSERT = 'INSERT INTO entry_slots_page_';

    /** ---------------------------------------------------------------
     * Retype backfill — the daemon-killer case.
     * --------------------------------------------------------------- */

    public function testAChunkThatLosesALockOnceRetriesAndCommits(): void
    {
        [$fieldId] = $this->seedRunningRetype();

        $logger = $this->makeRecordingLogger();
        $pdo = LockFailureInjectingPdo::wrap($this->pdo, self::SLOT_UPSERT, 1);

        $outcome = $this->retypeWorkSource($pdo, $logger, budget: 3)->tickOne('lock-retry-1');

        self::assertSame(TickOutcome::WORK_DONE, $outcome);
        self::assertCount(1, $this->recordsWithEvent($logger->records(), 'deadlock_retry'));
        self::assertSame([], $this->recordsWithEvent($logger->records(), 'lock_wait'));
        self::assertSame(
            'completed',
            $this->fetchCheckpointForField($fieldId)['status'],
            'A retried chunk must still finish the backfill.',
        );
    }

    /**
     * The assertion the whole item exists for: exhaustion **returns**.
     *
     * Before this change the `PDOException` escaped `tickOne()`, and
     * nothing between it and the process boundary catches.
     */
    public function testAnExhaustedBudgetReturnsLockWaitInsteadOfThrowing(): void
    {
        [$fieldId] = $this->seedRunningRetype();

        $before = $this->fetchCheckpointForField($fieldId);
        $logger = $this->makeRecordingLogger();
        $pdo = LockFailureInjectingPdo::wrap($this->pdo, self::SLOT_UPSERT, 3);

        $outcome = $this->retypeWorkSource($pdo, $logger, budget: 3)->tickOne('lock-retry-2');

        self::assertSame(TickOutcome::LOCK_WAIT, $outcome);
        self::assertSame(0, $pdo->remainingFailures(), 'The whole budget must have been spent.');

        $lockWait = $this->recordsWithEvent($logger->records(), 'lock_wait');
        self::assertCount(1, $lockWait);
        self::assertSame('reconciler', $lockWait[0]['context']['source']);
        self::assertSame(3, $lockWait[0]['context']['attempts']);
        self::assertSame(1205, $lockWait[0]['context']['errno']);

        // Two retries precede the give-up: attempts 1 and 2 log
        // `deadlock_retry`, attempt 3 logs `lock_wait` instead.
        self::assertCount(2, $this->recordsWithEvent($logger->records(), 'deadlock_retry'));

        self::assertSame(
            $before,
            $this->fetchCheckpointForField($fieldId),
            'The checkpoint must be byte-identical — nothing skipped, nothing failed.',
        );
    }

    /**
     * The failure item 11 was really about, at the daemon boundary.
     *
     * Drives the real `Reconciler` under the real `PollLoop`: `run()`
     * must return rather than let the exception through to the CLI's
     * fatal handler.
     */
    public function testThePollLoopSurvivesAnExhaustedBudget(): void
    {
        $this->seedRunningRetype();

        $pdo = LockFailureInjectingPdo::wrap($this->pdo, self::SLOT_UPSERT, 3);
        $reconciler = new Reconciler(
            workSources: [$this->retypeWorkSource($pdo, new NullLogger(), budget: 3)],
            capacityWaitMillis: 0,
            interChunkDelayMicros: 0,
            sleepFn: static fn (int $micros) => null,
        );

        (new PollLoop(new class() implements SleepFunction {
            public function sleepSeconds(int $seconds): void
            {
            }
        }))->run($reconciler, new StopsAfterOneTick(), 0);

        // Reaching here at all is the assertion; state it so the test
        // cannot be mistaken for one that asserts nothing.
        self::assertTrue(true, 'PollLoop::run() returned instead of the daemon dying.');
    }

    /** ---------------------------------------------------------------
     * Sync queue — must not quarantine a good entry.
     * --------------------------------------------------------------- */

    public function testASyncQueueLockFailureDoesNotWriteADlqRow(): void
    {
        [$modelId, $fieldId, , $fieldName] = $this->setupModelWithReservedField();
        $entryId = $this->seedEntry(1, $modelId, [$fieldName => 'value']);
        $this->enqueueSyncRow($entryId);

        $logger = $this->makeRecordingLogger();
        $pdo = LockFailureInjectingPdo::wrap($this->pdo, self::SLOT_UPSERT, 1);

        $outcome = (new SyncQueueWorkSource(
            pdo: $pdo,
            logger: $logger,
            backfillExecutor: new BackfillExecutor($pdo, new SlotRowUpserter($pdo), new SystemClock(), $logger),
            dlqWriter: $this->makeDlqWriter($logger),
            unmappedFieldReserver: $this->makeUnmappedFieldReserver($logger),
            chunkSize: 500,
            lockRetryBudget: 1,   // give up immediately, so one injection suffices
        ))->tickOne('lock-retry-sync');

        self::assertSame(TickOutcome::LOCK_WAIT, $outcome);
        self::assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_reconciler_dlq')->fetchColumn(),
            'Transient contention must never quarantine an entry: nothing replays the DLQ unprompted.',
        );
        self::assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_sync_queue')->fetchColumn(),
            'The queue row must stay claimable for the next tick.',
        );
        self::assertNotSame($fieldId, null);
    }

    /** ---------------------------------------------------------------
     * Import jobs — must not destroy a consumer's import.
     * --------------------------------------------------------------- */

    public function testAnImportLockFailureDoesNotFailTheJob(): void
    {
        [$modelId, , , $fieldName] = $this->setupModelWithReservedField();
        $artifactDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stardust';
        [$jobId] = $this->writePendingImportJob(1, [
            ['tenant_id' => 1, 'model_id' => $modelId, 'fields' => [$fieldName => 'a']],
        ], $artifactDir);

        $logger = $this->makeRecordingLogger();
        $pdo = LockFailureInjectingPdo::wrap($this->pdo, 'INSERT INTO entry_data', 1);

        $outcome = (new ImportJobWorkSource(
            pdo: $pdo,
            clock: new SystemClock(),
            logger: $logger,
            entryWriter: new EntryWriter($pdo, new SystemClock(), $logger, new SlotRowUpserter($pdo)),
            dlqWriter: $this->makeDlqWriter($logger),
            artifactDir: $artifactDir,
            chunkSize: 500,
            lockRetryBudget: 1,   // give up immediately
        ))->tickOne('lock-retry-import');

        self::assertSame(TickOutcome::LOCK_WAIT, $outcome);

        $job = $this->pdo->query("SELECT status, failed_reason FROM stardust_import_jobs WHERE id = {$jobId}")
            ->fetch(PDO::FETCH_ASSOC);
        self::assertSame(
            'processing',
            $job['status'],
            'A transient lock must not mark the import failed — nothing retries a failed job.',
        );
        self::assertNull($job['failed_reason']);
        self::assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_reconciler_dlq')->fetchColumn(),
        );
    }

    /** ---------------------------------------------------------------
     * Fixtures
     * --------------------------------------------------------------- */

    /**
     * A field mid-relocation, so the retype work source has exactly one
     * claimable chunk with one row to write.
     *
     * @return array{0: int, 1: int}
     */
    private function seedRunningRetype(): array
    {
        $pageId  = $this->provisionPage(['i_str_01', 'i_str_02']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'headline');
        $this->reserveSlotFor($fieldId);
        $this->seedEntry(1, $modelId, ['headline' => 'a-value']);

        $this->makeRetypeInitiator()->initiateRelocation(1, $fieldId, $pageId);

        self::assertSame(
            'running',
            (new RetypeCheckpointRepository($this->pdo))->statusForField($fieldId),
            'Fixture must leave a claimable checkpoint or every assertion below is vacuous.',
        );

        return [$fieldId, $modelId];
    }

    private function retypeWorkSource(PDO $pdo, LoggerInterface $logger, int $budget): RetypeBackfillWorkSource
    {
        return new RetypeBackfillWorkSource(
            pdo: $pdo,
            clock: new SystemClock(),
            logger: $logger,
            repository: new RetypeCheckpointRepository($pdo),
            executor: new RetypeBackfillExecutor($pdo, new SlotRowUpserter($pdo)),
            slotReserver: new SlotReserver($pdo, new SystemClock(), $logger),
            cardinalitySampler: new CardinalitySampler($pdo, $logger, 0.01, 10_000, 10),
            spreadSampler: $this->makeSpreadSampler($logger),
            chunkSize: 500,
            lockRetryBudget: $budget,
        );
    }
}

/** Lets `PollLoop::run()` execute exactly one tick, then return. */
final class StopsAfterOneTick implements ShutdownSignal
{
    private int $calls = 0;

    public function isRequested(): bool
    {
        return $this->calls++ > 0;
    }
}
