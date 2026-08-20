<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Compaction;

use StarDust\Compaction\CompactionRepository;
use StarDust\Compaction\CompactionService;
use StarDust\Clock\SystemClock;
use StarDust\Exception\CompactionCapacityException;
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
    // Fixtures
    // ---------------------------------------------------------------

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
        $pages = [
            $this->provisionPage(['i_str_01', 'i_str_02', 'i_str_03']),
            $this->provisionPage(['i_str_01']),
            $this->provisionPage(['i_str_01']),
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
