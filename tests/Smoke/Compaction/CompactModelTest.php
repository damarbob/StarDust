<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Compaction;

use StarDust\Compaction\CompactionRepository;
use StarDust\Compaction\CompactionService;
use StarDust\Clock\SystemClock;
use StarDust\Exception\CompactionCapacityException;
use StarDust\Exception\RetypeInProgressException;
use StarDust\Reconciler\TickOutcome;
use StarDust\Retype\RetypeCheckpointRepository;
use StarDust\Tests\Smoke\Phase6bTestCase;
use StarDust\Watcher\SpreadSampler;

/**
 * ADR 0033 operator-initiated model compaction, end to end.
 *
 * The acceptance criterion is deliberately ADR 0031's metric rather than
 * an internal branch: a compaction succeeded when `excess_pages` reaches
 * zero and every value survived. That is the same population the planner
 * works from, so the cure and the signal that triggers it cannot drift.
 *
 * Compaction needs a Reconciler to make progress, so these tests drive
 * the retype work source by hand in place of a running daemon — the
 * service polls the checkpoint, which the work source is what advances.
 */
final class CompactModelTest extends Phase6bTestCase
{
    /**
     * The whole point: a model scattered across three pages ends up on
     * one, with its data intact.
     *
     * Proven to discriminate — the pre-condition assertion below fails
     * if the fixture is not actually fragmented, so this cannot pass on
     * a model that was already compact.
     */
    public function testCompactionCollapsesAFragmentedModelToOnePage(): void
    {
        [$modelId, $fields, $entryIds] = $this->seedFragmentedModel();

        $sampler = new SpreadSampler($this->pdo, $this->makeRecordingLogger(), 2);

        $before = $sampler->report(1, $modelId);
        self::assertSame(3, $before[0]->pagesOccupied, 'Fixture must start fragmented.');
        self::assertSame(2, $before[0]->excessPages(), 'Fixture must have avoidable pages to remove.');

        $plan = $this->makeCompactionService()->compact(1, $modelId);

        self::assertSame(3, $plan->pagesBefore);
        self::assertSame(1, $plan->pagesAfter());
        self::assertSame(2, $plan->relocationCount());
        self::assertSame(1, $plan->noopCount);

        $after = $sampler->report(1, $modelId);
        self::assertSame(1, $after[0]->pagesOccupied);
        self::assertSame(0, $after[0]->excessPages(), 'excess_pages -> 0 is the success criterion.');
        self::assertSame(3, $after[0]->liveSlotCount, 'No slot may be lost in the move.');

        // Every field is live and filterable, and every value survived.
        //
        // Note the two statuses are both correct and mean different
        // things: a *relocated* field went through the backfill and was
        // promoted to `ready`, while the field that was already on the
        // target page is a no-op and stays `assigned`, never having
        // moved. Asserting `ready` for all three would be asserting that
        // compaction pointlessly rewrote a field that was already home.
        $relocatedFieldIds = array_map(
            static fn ($relocation) => $relocation->fieldId,
            $plan->relocations,
        );

        foreach ($fields as $fieldName => $fieldId) {
            $slot = $this->fetchLiveSlotForField($fieldId);
            self::assertNotNull($slot, "{$fieldName} must still hold a live slot.");

            $expected = in_array($fieldId, $relocatedFieldIds, true) ? 'ready' : 'assigned';
            self::assertSame(
                $expected,
                (string) $slot['status'],
                "{$fieldName} should be '{$expected}' after compaction.",
            );

            self::assertSame(
                1,
                (int) $slot['page_id'] === $plan->targetPageIds[0] ? 1 : 0,
                "{$fieldName} must end up on the target page.",
            );

            $table = $this->pageTableNameFor((int) $slot['page_id']);
            self::assertSame(
                $fieldName . '-value',
                (string) $this->fetchSlotValue($table, $entryIds[0], (string) $slot['slot_column']),
                "{$fieldName} must keep its value across the relocation.",
            );
        }
    }

    /** Re-running on an already-compact model plans nothing and mutates nothing. */
    public function testReRunOnACompactModelIsANoop(): void
    {
        [$modelId] = $this->seedFragmentedModel();
        $service = $this->makeCompactionService();

        $service->compact(1, $modelId);
        $versionAfterFirst = $this->fetchSchemaVersion();

        $second = $service->compact(1, $modelId);

        self::assertTrue($second->isNoop(), 'A compact model must plan zero relocations.');
        self::assertSame(0, $second->relocationCount());
        self::assertSame(
            $versionAfterFirst,
            $this->fetchSchemaVersion(),
            'A no-op compaction must not bump the schema version.',
        );
    }

    /**
     * A model that fragments again is compactable again — which means a
     * field that already relocated once can relocate a second time.
     *
     * `testReRunOnACompactModelIsANoop()` above does not reach this: a
     * no-op plan short-circuits before `initiateRelocation()`, so it
     * never opens a second checkpoint for any field. Until
     * `RetypeCheckpointRepository::insertOrReset()` landed, that second
     * relocation died on `ux_backfill_job_name` with a raw
     * `PDOException` (errno 1062), because the field's first checkpoint
     * was still sitting there `completed` and nothing removes it. So the
     * documented "safe to re-run" held only for the case that never ran
     * anything.
     */
    public function testAFieldThatAlreadyRelocatedCanRelocateAgain(): void
    {
        [$modelId, $fields] = $this->seedFragmentedModel();
        $service = $this->makeCompactionService();

        $first = $service->compact(1, $modelId);
        $relocatedFieldIds = array_map(
            static fn ($relocation) => $relocation->fieldId,
            $first->relocations,
        );
        self::assertNotEmpty($relocatedFieldIds, 'The first run must actually move something.');

        $movedFieldId = $relocatedFieldIds[0];
        self::assertSame(
            'completed',
            $this->fetchCheckpointForField($movedFieldId)['status'],
            'The first relocation must leave a terminal checkpoint behind — the thing that used to collide.',
        );

        // Re-fragment by direct registry UPDATE. `SlotReserver` packs
        // onto the oldest page, so a deliberately fragmented model
        // cannot be rebuilt through the reservation path — the same
        // documented bypass `SlotAffinityTest` uses.
        $this->stripeFieldOntoItsOwnPage($movedFieldId);

        $second = $service->compact(1, $modelId);

        self::assertContains(
            $movedFieldId,
            array_map(static fn ($relocation) => $relocation->fieldId, $second->relocations),
            'The re-run must plan the already-relocated field, not skip it.',
        );
        self::assertSame(
            'completed',
            $this->fetchCheckpointForField($movedFieldId)['status'],
            'Its second relocation must have drained, not collided.',
        );

        $after = (new SpreadSampler($this->pdo, $this->makeRecordingLogger(), 2))->report(1, $modelId);
        self::assertSame(0, $after[0]->excessPages(), 'excess_pages -> 0 is the success criterion.');
        self::assertSame(count($fields), $after[0]->liveSlotCount, 'No slot may be lost across two runs.');
    }

    /** `--dry-run` plans without mutating or emitting. */
    public function testDryRunMutatesNothingAndEmitsNothing(): void
    {
        [$modelId] = $this->seedFragmentedModel();

        $logger = $this->makeRecordingLogger();
        $versionBefore = $this->fetchSchemaVersion();
        $slotsBefore = $this->slotFingerprint();

        $plan = $this->makeCompactionService($logger)->plan(1, $modelId);

        self::assertSame(2, $plan->relocationCount(), 'Dry run still produces a real plan.');
        self::assertSame($versionBefore, $this->fetchSchemaVersion());
        self::assertSame($slotsBefore, $this->slotFingerprint(), 'Dry run must not touch a single slot.');
        self::assertSame(
            [],
            $this->recordsWithEvent($logger->records(), 'compaction_planned'),
            'A plan that was never committed to must emit no event.',
        );
    }

    /**
     * An inadmissible plan fails before touching anything. The registry
     * must be byte-identical afterwards — no tombstones, no checkpoints,
     * no version bump — so a refused compaction is never a half-migration.
     */
    public function testInadmissiblePlanLeavesTheRegistryUntouched(): void
    {
        [$modelId] = $this->seedFragmentedModel();

        // Consume every remaining free string slot everywhere, so no
        // page can absorb another field.
        $this->pdo->exec(
            "UPDATE stardust_slot_assignments SET status = 'assigned'"
            . " WHERE status = 'free' AND slot_type = 'str'"
        );

        $versionBefore = $this->fetchSchemaVersion();
        $slotsBefore = $this->slotFingerprint();
        $checkpointsBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM backfill_checkpoints')->fetchColumn();

        try {
            $this->makeCompactionService()->compact(1, $modelId);
            self::fail('Expected CompactionCapacityException.');
        } catch (CompactionCapacityException $e) {
            self::assertStringContainsString('Nothing was mutated', $e->getMessage());
        }

        self::assertSame($versionBefore, $this->fetchSchemaVersion());
        self::assertSame($slotsBefore, $this->slotFingerprint());
        self::assertSame(
            $checkpointsBefore,
            (int) $this->pdo->query('SELECT COUNT(*) FROM backfill_checkpoints')->fetchColumn(),
        );
    }

    /** The two ADR 0033 events fire with the normative fields. */
    public function testEmitsPlannedAndCompleteEvents(): void
    {
        [$modelId] = $this->seedFragmentedModel();
        $logger = $this->makeRecordingLogger();

        $this->makeCompactionService($logger)->compact(1, $modelId);
        $records = $logger->records();

        $planned = $this->recordsWithEvent($records, 'compaction_planned');
        self::assertCount(1, $planned);
        self::assertSame('registry', $planned[0]['context']['source']);
        self::assertSame(1, $planned[0]['context']['tenant_id']);
        self::assertSame($modelId, $planned[0]['context']['model_id']);
        self::assertSame(3, $planned[0]['context']['pages_before']);
        self::assertSame(2, $planned[0]['context']['fields_to_relocate']);
        self::assertSame(1, $planned[0]['context']['noop_count']);

        $complete = $this->recordsWithEvent($records, 'compaction_complete');
        self::assertCount(1, $complete);
        self::assertSame(2, $complete[0]['context']['fields_relocated']);
        self::assertSame(3, $complete[0]['context']['pages_before']);
        self::assertSame(1, $complete[0]['context']['pages_after']);
    }

    // ---------------------------------------------------------------
    // ADR 0039 — refusing to plan around an in-flight relocation
    // ---------------------------------------------------------------

    /**
     * Proves the window fixture is genuinely mid-relocation.
     *
     * **Non-negotiable, and it must come first.** Every other assertion
     * in this group is "the operation refuses", which passes vacuously
     * if the fixture is not actually in the window — the same trap
     * `DeleteWindowTest::testFixtureLeavesResidueInStorage` exists to
     * close.
     */
    public function testWindowFixtureIsGenuinelyMidRelocation(): void
    {
        [, $fields, $strandedPageId] = $this->seedModelWithARelocationInFlight();
        $betaId = $fields['beta'];

        self::assertSame(
            'running',
            $this->fetchCheckpointForField($betaId)['status'] ?? null,
            'beta must hold a running retype checkpoint.',
        );

        $live = $this->fetchLiveSlotForField($betaId);
        self::assertNotNull($live, 'beta must hold a live slot.');
        self::assertSame('backfilling', $live['status'], 'That slot must still be backfilling.');
        self::assertSame(
            $strandedPageId,
            (int) $live['page_id'],
            'beta must be landing on the page the planner cannot see.',
        );

        // And the defect's precondition: the planner's population really
        // does omit beta, so it sees two pages where the truth is three.
        $visible = (new CompactionRepository($this->pdo))->loadModelSlots(1, $this->windowModelId);
        self::assertCount(2, $visible, 'The planner must see only alpha and gamma.');
        self::assertNotContains(
            $betaId,
            array_map(static fn ($slot) => $slot->fieldId, $visible),
            'beta is invisible to the planner — that is the whole defect.',
        );
    }

    /** The guard: planning refuses while the population is known-incomplete. */
    public function testPlanRefusesWhileAFieldIsRelocating(): void
    {
        [$modelId] = $this->seedModelWithARelocationInFlight();

        $this->expectException(RetypeInProgressException::class);
        $this->makeCompactionService()->plan(1, $modelId);
    }

    /**
     * `--dry-run` refuses too, through the real facade.
     *
     * This is the surface an operator actually calls, and the one the
     * defect hurts most: a dry run's whole purpose is to report numbers,
     * and `pages_after: 1` against a true answer of 2 is the defect in
     * its purest form. Same placement rationale as the ADR 0038
     * deleting-model guard, which also sits in `plan()`.
     */
    public function testDryRunRefusesToo(): void
    {
        [$modelId] = $this->seedModelWithARelocationInFlight();

        $this->expectException(RetypeInProgressException::class);
        (new \StarDust\StarDust(new \StarDust\Config\Config(pdo: $this->pdo)))
            ->compactModel(1, $modelId, dryRun: true);
    }

    /**
     * The guard is checkpoint-keyed, not compaction-keyed: an ordinary
     * promotion blocks compaction exactly as a relocation does.
     *
     * The planner is equally blind to both — a promotion in flight also
     * holds a `backfilling` slot on a page the plan does not account
     * for — so narrowing this guard to relocations would leave the same
     * defect reachable from `promoteFieldToFilterable()`.
     */
    public function testAnOrdinaryPromotionAlsoBlocksCompaction(): void
    {
        [$modelId] = $this->seedFragmentedModel();

        // A JSON-only field promoted to filterable opens a running
        // retype checkpoint with no compaction involved at all.
        $deltaId = $this->createField($modelId, 'string', false, 'delta');
        $this->makeRetypeInitiator()->initiate(1, $deltaId, null, true);
        self::assertSame(
            'running',
            $this->fetchCheckpointForField($deltaId)['status'] ?? null,
            'The promotion must actually be in flight.',
        );

        $this->expectException(RetypeInProgressException::class);
        $this->makeCompactionService()->plan(1, $modelId);
    }

    /** A refusal is not a half-migration: nothing moves, nothing is emitted. */
    public function testRefusalMutatesNothing(): void
    {
        [$modelId] = $this->seedModelWithARelocationInFlight();

        $logger = $this->makeRecordingLogger();
        $versionBefore = $this->fetchSchemaVersion();
        $slotsBefore = $this->slotFingerprint();

        try {
            $this->makeCompactionService($logger)->compact(1, $modelId);
            self::fail('Expected RetypeInProgressException.');
        } catch (RetypeInProgressException $e) {
            self::assertStringContainsString('reconciler', $e->getMessage());
        }

        self::assertSame($versionBefore, $this->fetchSchemaVersion());
        self::assertSame($slotsBefore, $this->slotFingerprint(), 'A refusal must not touch a single slot.');
        self::assertSame(
            [],
            $this->recordsWithEvent($logger->records(), 'compaction_planned'),
            'A refused compaction must emit nothing.',
        );
    }

    /**
     * The refusal is transient, and self-clearing.
     *
     * "Resume is re-run" survives ADR 0039 — it just is not *immediate*.
     * Once the Reconciler drains the stranded relocation, compaction
     * plans against a complete population and reaches `excess_pages = 0`,
     * which is the criterion the rest of this file uses. Without the
     * guard the earlier run would have reported `pages_after: 1` here
     * and left the model on two pages.
     */
    public function testCompactionProceedsOnceTheRelocationDrains(): void
    {
        [$modelId, $fields] = $this->seedModelWithARelocationInFlight();

        $workSource = $this->makeRetypeBackfillWorkSource();
        for ($i = 0; $i < 50; $i++) {
            if ($this->fetchCheckpointForField($fields['beta'])['status'] === 'completed') {
                break;
            }
            $workSource->tickOne('test-drain');
        }
        self::assertSame(
            'completed',
            $this->fetchCheckpointForField($fields['beta'])['status'],
            'The stranded relocation must drain before the re-run.',
        );

        $plan = $this->makeCompactionService()->compact(1, $modelId);

        $after = (new SpreadSampler($this->pdo, $this->makeRecordingLogger(), 2))->report(1, $modelId);
        self::assertSame(
            $plan->pagesAfter(),
            $after[0]->pagesOccupied,
            'The reported end state must match the ADR 0031 spread sample that verifies it.',
        );
        self::assertSame(0, $after[0]->excessPages(), 'excess_pages -> 0 is the success criterion.');
        self::assertSame(3, $after[0]->liveSlotCount, 'No slot may be lost.');
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    /** Set by {@see self::seedModelWithARelocationInFlight()}. */
    private int $windowModelId = 0;

    /**
     * The ADR 0039 window: a model with one field stranded mid-relocation
     * onto a page the planner will not choose.
     *
     * Reproduces the 2026-08-27 measurement exactly. Page 1 is roomy, so
     * the planner consolidates there; beta is relocated onto page 3
     * instead and left undrained. The planner then sees only alpha(p1)
     * and gamma(p3) — two pages against a true three — and would plan
     * gamma to page 1 and report `pages_after: 1`, while the real end
     * state once beta drains is alpha+gamma on p1 and beta on p3, which
     * ADR 0031 samples as `pages_occupied: 2, excess_pages: 1`.
     *
     * Page layout is forced by direct registry UPDATE for the reason
     * `seedFragmentedModel()` documents — `SlotReserver` packs affinely
     * (ADR 0032) and cannot produce a fragmented model on request.
     *
     * @return array{0: int, 1: array<string, int>, 2: int}
     */
    private function seedModelWithARelocationInFlight(): array
    {
        $roomy = $this->provisionPage(['i_str_01', 'i_str_02', 'i_str_03']);
        $lonely = $this->provisionPage(['i_str_01']);
        $stranded = $this->provisionPage(['i_str_01', 'i_str_02']);

        $modelId = $this->createModel(1);
        $fields = [];
        foreach (['alpha', 'beta', 'gamma'] as $name) {
            $fields[$name] = $this->createField($modelId, 'string', true, $name);
        }

        $this->bindSlot($roomy, 'i_str_01', $fields['alpha'], 'assigned');
        $this->bindSlot($lonely, 'i_str_01', $fields['beta'], 'assigned');
        $this->bindSlot($stranded, 'i_str_01', $fields['gamma'], 'assigned');

        $this->seedEntry(1, $modelId, [
            'alpha' => 'alpha-value',
            'beta'  => 'beta-value',
            'gamma' => 'gamma-value',
        ]);

        // Strand beta mid-flight: real initiation, deliberately undrained.
        // Its old slot goes `tombstoned`, its new one `backfilling` on the
        // page the planner is about to overlook.
        $this->makeRetypeInitiator()->initiateRelocation(1, $fields['beta'], $stranded);

        $this->windowModelId = $modelId;

        return [$modelId, $fields, $stranded];
    }

    /**
     * A model with three string fields forced onto three separate pages.
     *
     * Placement is forced by direct registry UPDATE because `SlotReserver`
     * now packs affinely (ADR 0032) and cannot produce a fragmented model
     * on request — the same bypass precedent as `SlotAffinityTest`.
     *
     * @return array{0: int, 1: array<string, int>, 2: list<int>}
     */
    private function seedFragmentedModel(): array
    {
        // Page 1 is roomiest so the planner consolidates onto it. Pages 2
        // and 3 carry a spare column each: since ADR 0043 a page has
        // exactly the columns it was provisioned with, so a one-column
        // page has no free slot left once its field lands, and
        // `stripeFieldOntoItsOwnPage()` would have nowhere to re-fragment
        // to after a consolidation.
        $pages = [
            $this->provisionPage(['i_str_01', 'i_str_02', 'i_str_03']),
            $this->provisionPage(['i_str_01', 'i_str_02']),
            $this->provisionPage(['i_str_01', 'i_str_02']),
        ];

        $modelId = $this->createModel(1);
        $fields = [];
        foreach (['alpha', 'beta', 'gamma'] as $name) {
            $fields[$name] = $this->createField($modelId, 'string', true, $name);
        }

        // One field per page. Page 1 is roomiest, so the planner should
        // consolidate onto it.
        $i = 0;
        foreach ($fields as $fieldId) {
            $this->bindSlot($pages[$i], 'i_str_01', $fieldId, 'assigned');
            $i++;
        }

        $entryIds = [$this->seedEntry(1, $modelId, [
            'alpha' => 'alpha-value',
            'beta'  => 'beta-value',
            'gamma' => 'gamma-value',
        ])];

        return [$modelId, $fields, $entryIds];
    }

    /**
     * Moves one field's live slot onto a page nothing else of its model
     * occupies, by direct registry UPDATE, so the model reads as
     * fragmented again.
     *
     * Frees the vacated row rather than tombstoning it: a tombstone
     * would sit there until a Liberator sweep and this fixture wants the
     * capacity back immediately.
     */
    private function stripeFieldOntoItsOwnPage(int $fieldId): void
    {
        $current = $this->fetchLiveSlotForField($fieldId);
        self::assertNotNull($current, 'Field must hold a live slot to be striped off it.');

        $target = $this->pdo->prepare(
            'SELECT id, page_id FROM stardust_slot_assignments'
            . " WHERE status = 'free' AND slot_type = 'str' AND page_id <> ?"
            . ' ORDER BY page_id, id LIMIT 1'
        );
        $target->execute([(int) $current['page_id']]);
        $row = $target->fetch(\PDO::FETCH_ASSOC);
        self::assertNotFalse($row, 'Fixture needs a free string slot on another page.');

        // Release the old row BEFORE claiming the new one:
        // `ux_slot_assignments_field_live` (ADR 0017) permits at most one
        // live slot per field, so the other order trips errno 1062. Same
        // ordering constraint `LiveSlotTombstoner` works around.
        $this->pdo->prepare(
            'UPDATE stardust_slot_assignments'
            . " SET field_id = NULL, status = 'free', updated_at = UTC_TIMESTAMP()"
            . ' WHERE id = ?'
        )->execute([(int) $current['id']]);

        $this->pdo->prepare(
            'UPDATE stardust_slot_assignments'
            . " SET field_id = ?, status = 'assigned', updated_at = UTC_TIMESTAMP()"
            . ' WHERE id = ?'
        )->execute([$fieldId, (int) $row['id']]);
    }

    private function bindSlot(int $pageId, string $slotColumn, int $fieldId, string $status): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE stardust_slot_assignments SET field_id = ?, status = ?, updated_at = UTC_TIMESTAMP()'
            . ' WHERE page_id = ? AND slot_column = ?'
        );
        $stmt->execute([$fieldId, $status, $pageId, $slotColumn]);
    }

    /**
     * A compaction service whose "wait for the Reconciler" step drives
     * the real work source instead of sleeping — there is no daemon in a
     * smoke test, and the service is correct to block without one.
     */
    private function makeCompactionService(?\Psr\Log\LoggerInterface $logger = null): CompactionService
    {
        $log = $logger ?? $this->makeRecordingLogger();
        $workSource = $this->makeRetypeBackfillWorkSource($log);

        return new CompactionService(
            repository: new CompactionRepository($this->pdo),
            retypeInitiator: $this->makeRetypeInitiator($log),
            checkpointRepository: new RetypeCheckpointRepository($this->pdo),
            logger: $log,
            clock: new SystemClock(),
            pollIntervalMicros: 0,
            maxPollsPerField: 50,
            sleepFn: static function (int $_micros) use ($workSource): void {
                // Stand in for a running `bin/stardust reconciler`.
                $workSource->tickOne('test-compaction');
            },
        );
    }

    /** Order-stable snapshot of every slot's assignment state. */
    private function slotFingerprint(): string
    {
        $rows = $this->pdo->query(
            'SELECT id, page_id, slot_column, status, field_id'
            . ' FROM stardust_slot_assignments ORDER BY id'
        )->fetchAll(\PDO::FETCH_ASSOC);

        return json_encode($rows, JSON_THROW_ON_ERROR);
    }
}
