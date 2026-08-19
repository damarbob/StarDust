<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Slot;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use StarDust\Bootstrap\Bootstrapper;
use StarDust\Clock\SystemClock;
use StarDust\Logging\StdoutNdjsonLogger;
use StarDust\Page\PageProvisioner;
use StarDust\Slot\SlotAssignment;
use StarDust\Slot\SlotReserver;
use StarDust\Watcher\SpreadSampler;

/**
 * ADR 0032 model-affine slot reservation.
 *
 * Reservation biases toward pages already hosting a live slot of the
 * same model, and falls back to global-oldest when no affine candidate
 * of the family exists. Two properties matter more than the bias itself:
 *
 *   1. **It is a bias, never a constraint.** Affinity must not fail a
 *      reservation that would otherwise succeed (ADR 0007 liveness).
 *   2. **It must not lock the model's live sibling slots.** Those are
 *      read by the write path and mutated by relocations; that
 *      contention does not exist today and must not be introduced.
 *
 * This class owns its PDO rather than extending the Phase 5 case,
 * because it needs sibling connections for the locking test and forced
 * multi-page placement that `SlotReserver` cannot produce on its own —
 * it packs onto the oldest page first, which is exactly the behaviour
 * being changed.
 */
final class SlotAffinityTest extends TestCase
{
    private const PHASE_1_TABLES = [
        'stardust_slot_assignments',
        'stardust_pages',
        'stardust_fields',
        'stardust_models',
        'stardust_sync_queue',
        'entry_data',
        'stardust_schema_version',
        'stardust_export_jobs',
        'stardust_import_jobs',
        'stardust_reconciler_dlq',
        'backfill_checkpoints',
    ];

    private PDO $pdo;

    protected function setUp(): void
    {
        $dsn  = getenv('STARDUST_TEST_DSN') ?: '';
        $user = getenv('STARDUST_TEST_USER') ?: '';

        if ($dsn === '' || $user === '') {
            self::markTestSkipped('STARDUST_TEST_DSN and STARDUST_TEST_USER must be set for smoke tests.');
        }

        $this->pdo = $this->newConnection();
        $this->dropEverything();
        (new Bootstrapper($this->pdo))->run();
    }

    protected function tearDown(): void
    {
        if (! isset($this->pdo)) {
            return;
        }
        try {
            $this->dropEverything();
        } catch (\Throwable) {
            // best-effort
        }
    }

    // ---------------------------------------------------------------
    // The bias
    // ---------------------------------------------------------------

    /**
     * The headline behaviour: a newer page already hosting the model
     * wins over an older page with a free slot of the same family.
     * Pre-ADR-0032 this reserved on the oldest page every time.
     */
    public function testReservationPrefersAPageAlreadyHostingTheModel(): void
    {
        [$older, $affine] = $this->twoIndexedPages();
        $modelId = $this->createModel();
        $this->bindSlot($affine, 'i_str_01', $this->createField($modelId), 'assigned');

        $assignment = $this->newReserver()->reserve($this->createField($modelId));

        self::assertNotNull($assignment);
        self::assertSame($affine, $assignment->pageId, 'Affinity must beat global-oldest.');
        self::assertSame(SlotAssignment::AFFINITY_CO_LOCATED, $assignment->affinity);
        self::assertNotSame($older, $assignment->pageId);
    }

    /** A model with no live slot yet has no affine set — global-oldest, as before. */
    public function testFreshModelFallsBackToGlobalOldest(): void
    {
        [$older, $_newer] = $this->twoIndexedPages();
        $modelId = $this->createModel();

        $assignment = $this->newReserver()->reserve($this->createField($modelId));

        self::assertNotNull($assignment);
        self::assertSame($older, $assignment->pageId);
        self::assertSame(SlotAssignment::AFFINITY_FALLBACK, $assignment->affinity);
    }

    /**
     * ADR 0032's load-bearing constraint: when the affine page has no
     * free slot of the required family, reservation spills to
     * global-oldest rather than failing. Affinity can never fail a
     * reservation that would otherwise succeed (ADR 0007).
     */
    public function testSaturatedAffinePageSpillsInsteadOfFailing(): void
    {
        [$older, $affine] = $this->twoIndexedPages();
        $modelId = $this->createModel();
        $this->bindSlot($affine, 'i_str_01', $this->createField($modelId), 'assigned');
        $this->saturate($affine, 'str');

        $assignment = $this->newReserver()->reserve($this->createField($modelId));

        self::assertNotNull($assignment, 'Affinity must never fail a reservation that would succeed.');
        self::assertSame($older, $assignment->pageId);
        self::assertSame(SlotAssignment::AFFINITY_FALLBACK, $assignment->affinity);
    }

    /**
     * A `backfilling` sibling makes a page affine. This deliberately
     * differs from `SpreadSampler`, which counts only `assigned|ready`:
     * spread measures join cost and a `backfilling` slot serves no
     * query, whereas affinity asks where the model is going to live.
     */
    public function testBackfillingSiblingCountsAsAffine(): void
    {
        [$_older, $affine] = $this->twoIndexedPages();
        $modelId = $this->createModel();
        $this->bindSlot($affine, 'i_str_01', $this->createField($modelId), 'backfilling');

        $assignment = $this->newReserver()->reserve($this->createField($modelId));

        self::assertNotNull($assignment);
        self::assertSame($affine, $assignment->pageId);
        self::assertSame(SlotAssignment::AFFINITY_CO_LOCATED, $assignment->affinity);
    }

    /** A tombstoned slot is not live, so it confers no affinity. */
    public function testTombstonedSiblingConfersNoAffinity(): void
    {
        [$older, $other] = $this->twoIndexedPages();
        $modelId = $this->createModel();
        $fieldId = $this->createField($modelId);
        $this->bindSlot($other, 'i_str_01', $fieldId, 'assigned');
        $this->pdo->exec(
            "UPDATE stardust_slot_assignments SET status='tombstoned', field_id=NULL"
            . " WHERE page_id={$other} AND slot_column='i_str_01'"
        );

        $assignment = $this->newReserver()->reserve($this->createField($modelId));

        self::assertNotNull($assignment);
        self::assertSame($older, $assignment->pageId);
        self::assertSame(SlotAssignment::AFFINITY_FALLBACK, $assignment->affinity);
    }

    /** Another model's slots confer no affinity — the pool stays shared. */
    public function testAnotherModelsSlotsDoNotConferAffinity(): void
    {
        [$older, $other] = $this->twoIndexedPages();
        $mine    = $this->createModel();
        $theirs  = $this->createModel();
        $this->bindSlot($other, 'i_str_01', $this->createField($theirs), 'assigned');

        $assignment = $this->newReserver()->reserve($this->createField($mine));

        self::assertNotNull($assignment);
        self::assertSame($older, $assignment->pageId, 'Affinity is per-model, not per-page-occupancy.');
    }

    /**
     * `requireIndexed` narrows *eligibility*; affinity only reorders.
     * So an unindexed affine page loses to an indexed non-affine one —
     * otherwise ADR 0004 would be violated by a filterable field landing
     * on a column with no index.
     */
    public function testRequireIndexedOutranksAffinity(): void
    {
        $indexed   = $this->provisionPage(['i_str_01', 'i_str_02']);
        $unindexed = $this->provisionPage();
        $modelId   = $this->createModel();
        $this->bindSlot($unindexed, 'i_str_01', $this->createField($modelId), 'assigned');

        $assignment = $this->newReserver()->reserveForExhaustionBackfill($this->createField($modelId));

        self::assertNotNull($assignment);
        self::assertSame($indexed, $assignment->pageId, 'Eligibility must outrank affinity ordering.');
        self::assertSame(SlotAssignment::AFFINITY_FALLBACK, $assignment->affinity);
    }

    /** Affine AND indexed is preferred when both are available. */
    public function testAffineAndIndexedIsPreferred(): void
    {
        $older  = $this->provisionPage(['i_str_01', 'i_str_02']);
        $affine = $this->provisionPage(['i_str_01', 'i_str_02']);
        $modelId = $this->createModel();
        $this->bindSlot($affine, 'i_str_01', $this->createField($modelId), 'assigned');

        $assignment = $this->newReserver()->reserveForExhaustionBackfill($this->createField($modelId));

        self::assertNotNull($assignment);
        self::assertSame($affine, $assignment->pageId);
        self::assertSame(SlotAssignment::AFFINITY_CO_LOCATED, $assignment->affinity);
        self::assertNotSame($older, $assignment->pageId);
    }

    /** Ordering stays deterministic — no randomness was introduced. */
    public function testOrderingIsDeterministic(): void
    {
        [$_older, $affine] = $this->twoIndexedPages();
        $modelId = $this->createModel();
        $this->bindSlot($affine, 'i_str_01', $this->createField($modelId), 'assigned');

        $columns = [];
        for ($i = 0; $i < 3; $i++) {
            $assignment = $this->newReserver()->reserve($this->createField($modelId));
            self::assertNotNull($assignment);
            self::assertSame($affine, $assignment->pageId);
            $columns[] = $assignment->slotColumn;
        }

        // Successive reservations walk the affine page in slot order.
        self::assertSame(['i_str_02', 'i_str_03', 'i_str_04'], $columns);
    }

    /** The outcome rides the existing `slot_reserved` event, no new name. */
    public function testSlotReservedEventCarriesAffinity(): void
    {
        [$_older, $affine] = $this->twoIndexedPages();
        $modelId = $this->createModel();
        $this->bindSlot($affine, 'i_str_01', $this->createField($modelId), 'assigned');

        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $this->newReserver(new StdoutNdjsonLogger(new SystemClock(), $stream))
            ->reserve($this->createField($modelId));

        rewind($stream);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($stream))));
        $event = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('slot_reserved', $event['event']);
        self::assertSame('co_located', $event['affinity']);
    }

    /**
     * ADR 0032 applies to relocations too, not just first assignments —
     * a retype re-reserves through `reserveForBackfillWithinTransaction()`,
     * and that is the entry point most able to scatter a model, since
     * ADR 0016 re-rolls page placement on every type change.
     */
    public function testRetypeRelocationIsAffineToTheModelsSiblings(): void
    {
        $older = $this->provisionPage(['i_str_01', 'i_int_01']);
        $home  = $this->provisionPage(['i_str_01', 'i_int_01']);
        $modelId = $this->createModel();
        $this->bindSlot($home, 'i_str_01', $this->createField($modelId), 'assigned');

        $assignment = $this->reserveWithinTransaction($this->createField($modelId, 'int'));

        self::assertNotNull($assignment);
        self::assertSame($home, $assignment->pageId, 'A relocation must stay with its model.');
        self::assertSame(SlotAssignment::AFFINITY_CO_LOCATED, $assignment->affinity);
        self::assertNotSame($older, $assignment->pageId);
    }

    /**
     * The one shape affinity cannot help, pinned so it is not mistaken
     * for a bug later: retyping a model's **only** field.
     *
     * `RetypeInitiator` tombstones the current slot in the same
     * transaction that reserves the replacement, so by then the model
     * has no live slot anywhere and the affine set is empty — the
     * replacement goes to global-oldest and the model can move pages.
     * Harmless (a one-field model occupies one page either way, so
     * `excess_pages` stays 0), but it means a relocation is not
     * guaranteed to be page-stable. ADR 0033 compaction pins its target
     * page explicitly rather than relying on affinity for exactly this
     * reason.
     */
    public function testSingleFieldModelLosesAffinityOnRelocation(): void
    {
        $older = $this->provisionPage(['i_str_01', 'i_int_01']);
        $home  = $this->provisionPage(['i_str_01', 'i_int_01']);
        $modelId = $this->createModel();
        $onlyField = $this->createField($modelId);
        $this->bindSlot($home, 'i_str_01', $onlyField, 'assigned');

        // What RetypeInitiator does first, in the same transaction.
        $this->pdo->exec(
            "UPDATE stardust_slot_assignments SET status='tombstoned', field_id=NULL"
            . " WHERE page_id={$home} AND slot_column='i_str_01'"
        );

        $assignment = $this->reserveWithinTransaction($this->createField($modelId, 'int'));

        self::assertNotNull($assignment);
        self::assertSame($older, $assignment->pageId);
        self::assertSame(SlotAssignment::AFFINITY_FALLBACK, $assignment->affinity);
    }

    // ---------------------------------------------------------------
    // The non-locking invariant (ADR 0032, normative)
    // ---------------------------------------------------------------

    /**
     * The affinity page-set read must take no locks on the model's live
     * sibling slots. Proven directly: session A holds an open
     * reservation transaction while session B — on a 1 s lock timeout —
     * mutates one of those live siblings. If the affinity read ever
     * becomes a correlated `EXISTS` inside the `FOR UPDATE` statement,
     * this test starts timing out.
     */
    public function testHeldReservationDoesNotLockTheModelsLiveSiblings(): void
    {
        [$_older, $affine] = $this->twoIndexedPages();
        $modelId  = $this->createModel();
        $siblingField = $this->createField($modelId);
        $this->bindSlot($affine, 'i_str_01', $siblingField, 'assigned');

        $siblingRowId = (int) $this->pdo->query(
            "SELECT id FROM stardust_slot_assignments"
            . " WHERE page_id={$affine} AND slot_column='i_str_01'"
        )->fetchColumn();

        $sessionA = $this->newConnection();
        $sessionB = $this->newConnection();
        $sessionB->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $sessionA->beginTransaction();
        try {
            (new SlotReserver($sessionA, new SystemClock(), new NullLogger()))
                ->reserveForBackfillWithinTransaction($this->createField($modelId));

            // Bump a counter rather than `updated_at`: that column is
            // `ON UPDATE CURRENT_TIMESTAMP`, so re-setting it inside the
            // same second is a matched-but-unchanged row and reports
            // rowCount 0 for reasons that have nothing to do with locks.
            $update = $sessionB->prepare(
                'UPDATE stardust_slot_assignments SET sweep_gap_count = sweep_gap_count + 1 WHERE id = ?'
            );
            $update->execute([$siblingRowId]);

            self::assertSame(1, $update->rowCount(), 'The live sibling must remain writable.');
        } finally {
            // Guarded, matching `ExportJobSubmitterCapConcurrencyTest`:
            // an unguarded rollBack() throws "no active transaction" if
            // the transaction has already ended, which would surface as
            // an error during teardown and abort the cleanup below.
            if ($sessionA->inTransaction()) {
                $sessionA->rollBack();
            }
            // Release both siblings promptly rather than waiting for GC.
            // They are extra connections held for the length of a
            // 460-test suite otherwise, and a lingering session that has
            // touched these tables can stall a later class's DROP TABLE
            // on a metadata lock.
            unset($sessionA, $sessionB);
        }
    }

    // ---------------------------------------------------------------
    // The outcome affinity actually claims (measured with ADR 0031)
    // ---------------------------------------------------------------

    /**
     * The acceptance test for the whole stage, measured with ADR 0031
     * rather than by asserting a code branch was taken.
     *
     * The fixture is the case affinity exists for and **fails without
     * it**: a model whose slots already sit on a *newer* page (because
     * the oldest was full when it was created), grown later once the
     * oldest page has capacity again — a Liberator sweep, or simply a
     * second model churning. Global-oldest sends every new field to the
     * old page and splits the model across two; affinity keeps it whole.
     *
     * Verified to be discriminating: with the reservation ordering
     * reverted to global-oldest this reports `excess_pages = 1`.
     */
    public function testGrowingAModelKeepsItOnThePageItAlreadyOccupies(): void
    {
        $oldest = $this->provisionPage(['i_str_01', 'i_str_02', 'i_str_03', 'i_str_04']);
        $home   = $this->provisionPage(['i_str_01', 'i_str_02', 'i_str_03', 'i_str_04']);

        // The model already lives on the NEWER page, and the oldest page
        // has free capacity — so global-oldest would pull it apart.
        $modelId = $this->createModel();
        $this->bindSlot($home, 'i_str_01', $this->createField($modelId), 'assigned');
        $this->bindSlot($home, 'i_str_02', $this->createField($modelId), 'ready');

        $reserver = $this->newReserver();
        for ($i = 0; $i < 2; $i++) {
            $assignment = $reserver->reserve($this->createField($modelId));
            self::assertNotNull($assignment);
            self::assertSame($home, $assignment->pageId);
        }

        self::assertGreaterThan(
            0,
            $this->countFreeStringSlots($oldest),
            'Sanity: the oldest page must still have capacity, or nothing was proven.',
        );

        $samples = (new SpreadSampler($this->pdo, new NullLogger(), 2))->report(1, $modelId);

        self::assertCount(1, $samples);
        self::assertSame(4, $samples[0]->liveSlotCount);
        self::assertSame(1, $samples[0]->pagesOccupied, 'The model must not have been split.');
        self::assertSame(1, $samples[0]->theoreticalMinPages);
        self::assertSame(0, $samples[0]->excessPages(), 'That is what affinity buys.');
    }

    /**
     * The negative control. Without free capacity on the affine page the
     * same workload spreads, so the test above is measuring affinity
     * rather than a fixture that could not have failed.
     */
    public function testModelSpreadsWhenAffinePagesCannotAbsorbIt(): void
    {
        // Each page offers exactly ONE free string slot, so every
        // reservation after the first is forced onto a new page.
        $p1 = $this->provisionPage(['i_str_01']);
        $p2 = $this->provisionPage(['i_str_01']);
        $p3 = $this->provisionPage(['i_str_01']);
        foreach ([$p1, $p2, $p3] as $page) {
            $this->pdo->exec(
                "UPDATE stardust_slot_assignments SET status='assigned'"
                . " WHERE page_id={$page} AND slot_type='str' AND slot_column <> 'i_str_01'"
            );
        }

        $modelId = $this->createModel();
        $reserver = $this->newReserver();
        for ($i = 0; $i < 3; $i++) {
            $reserver->reserve($this->createField($modelId));
        }

        $samples = (new SpreadSampler($this->pdo, new NullLogger(), 2))->report(1, $modelId);

        self::assertSame(3, $samples[0]->pagesOccupied);
        self::assertSame(1, $samples[0]->theoreticalMinPages);
        self::assertSame(2, $samples[0]->excessPages(), 'Affinity cannot beat structural capacity limits.');
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    private function newConnection(): PDO
    {
        return new PDO(
            (string) getenv('STARDUST_TEST_DSN'),
            (string) getenv('STARDUST_TEST_USER'),
            (string) (getenv('STARDUST_TEST_PASS') ?: ''),
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );
    }

    private function newReserver(?\Psr\Log\LoggerInterface $logger = null): SlotReserver
    {
        return new SlotReserver($this->pdo, new SystemClock(), $logger ?? new NullLogger());
    }

    /**
     * Drive the caller-owns-the-transaction entry point without leaving
     * `$this->pdo` mid-transaction if the reserver throws — `tearDown()`
     * issues DDL on that same shared connection immediately afterwards.
     */
    private function reserveWithinTransaction(int $fieldId): ?SlotAssignment
    {
        $this->pdo->beginTransaction();
        try {
            $assignment = $this->newReserver()
                ->reserveForBackfillWithinTransaction($fieldId, requireIndexed: true);
            $this->pdo->commit();

            return $assignment;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @return array{0: int, 1: int} [older page, newer page], both str-indexed */
    private function twoIndexedPages(): array
    {
        return [
            $this->provisionPage(['i_str_01', 'i_str_02', 'i_str_03', 'i_str_04']),
            $this->provisionPage(['i_str_01', 'i_str_02', 'i_str_03', 'i_str_04']),
        ];
    }

    /** @param list<string> $filterableSlots */
    private function provisionPage(array $filterableSlots = []): int
    {
        return (new PageProvisioner(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: new NullLogger(),
            provisionerIdentity: 'phpunit/0',
        ))->provision($filterableSlots);
    }

    private function createModel(int $tenantId = 1): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO stardust_models (tenant_id, name, created_at) VALUES (?, ?, UTC_TIMESTAMP())'
        );
        $stmt->execute([$tenantId, 'model_' . bin2hex(random_bytes(4))]);
        return (int) $this->pdo->lastInsertId();
    }

    private function createField(int $modelId, string $declaredType = 'string'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO stardust_fields (model_id, name, declared_type, is_filterable, created_at, updated_at)'
            . ' VALUES (?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([$modelId, 'field_' . bin2hex(random_bytes(4)), $declaredType]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Place a field on one exact page + column by direct registry UPDATE.
     * `SlotReserver` packs onto the oldest page first, so a deliberately
     * multi-page model cannot be built through it — the same bypass
     * precedent as `Phase6aTestCase::seedSlotValues()`.
     */
    private function bindSlot(int $pageId, string $slotColumn, int $fieldId, string $status): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE stardust_slot_assignments SET field_id = ?, status = ?, updated_at = UTC_TIMESTAMP()'
            . ' WHERE page_id = ? AND slot_column = ?'
        );
        $stmt->execute([$fieldId, $status, $pageId, $slotColumn]);
    }

    private function countFreeStringSlots(int $pageId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM stardust_slot_assignments"
            . " WHERE page_id = ? AND slot_type = 'str' AND status = 'free'"
        );
        $stmt->execute([$pageId]);

        return (int) $stmt->fetchColumn();
    }

    private function saturate(int $pageId, string $family): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE stardust_slot_assignments SET status = 'assigned'"
            . " WHERE page_id = ? AND slot_type = ? AND status = 'free'"
        );
        $stmt->execute([$pageId, $family]);
    }

    private function dropEverything(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        $pages = $this->pdo->query(
            'SELECT table_name FROM information_schema.TABLES'
            . " WHERE table_schema = DATABASE() AND table_name LIKE 'entry_slots_page_%'"
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($pages as $pageTable) {
            try {
                $this->pdo->exec("DROP TABLE IF EXISTS {$pageTable}");
            } catch (\Throwable) {
                // ignored
            }
        }
        foreach (self::PHASE_1_TABLES as $t) {
            try {
                $this->pdo->exec("DROP TABLE IF EXISTS {$t}");
            } catch (\Throwable) {
                // ignored
            }
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
