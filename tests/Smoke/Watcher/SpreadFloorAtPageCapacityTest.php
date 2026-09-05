<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Watcher;

use Psr\Log\NullLogger;
use StarDust\Config\Config;
use StarDust\Schema\FieldDefinition;
use StarDust\StarDust;
use StarDust\Tests\Smoke\Phase6bTestCase;

/**
 * A model at its *page-capacity* floor reports no excess, and compacts
 * to a no-op rather than an exception.
 *
 * The sibling of {@see SerialPromotionSpreadTest}, one field further
 * along. Three serially promoted fields fit inside one page's headroom
 * (`Config::$pageIndexHeadroom`, `k = 4`) and land on one page. The
 * `k + 1`st cannot: no page has a free `str` slot left, so the Watcher
 * provisions a second page, and the model occupies two pages because
 * that is the fewest that can hold it.
 *
 * `theoretical_min_pages` used to divide by the *layout* capacity
 * (`PageProvisioner::STRING_SLOTS`, 25) and answer 1 — a floor no page
 * has been able to offer since ADR 0034, and one that ADR 0043 made
 * structurally impossible by giving a page exactly the columns it
 * indexes. Two symptoms followed from the single wrong number, and both
 * are asserted here:
 *
 * - `excess_pages` was non-zero for a model that cannot be packed
 *   tighter, and at `2k + 1` fields it crossed
 *   `Config::$spreadExcessPageThreshold` and fired `high_spread_model` —
 *   the ADR 0031 advisory telling an operator to compact something that
 *   is already compact.
 * - `compactModel()` then refused, `--dry-run` included, because
 *   `CompactionPlanner` starts its search at that floor and every
 *   candidate set smaller than the current one fails to fit against
 *   real per-page inventory.
 *
 * **The promotions must be serial**, for the reason
 * `SerialPromotionSpreadTest` documents: promoted together they arrive
 * as one unit of demand and the planner sizes a single page to fit them
 * all, which is a layout this defect never reached.
 */
final class SpreadFloorAtPageCapacityTest extends Phase6bTestCase
{
    /** `Config::$pageIndexHeadroom`, restated so the arithmetic below reads. */
    private const HEADROOM = 4;

    /**
     * Promote `$count` string fields one at a time, each with its own
     * Watcher tick and Reconciler drain.
     *
     * @return array{StarDust, int} the engine and the model id
     */
    private function promoteSerially(int $count): array
    {
        $engine = new StarDust(new Config(pdo: $this->pdo, logger: new NullLogger()));

        $fields = [];
        for ($i = 1; $i <= $count; $i++) {
            $fields[] = new FieldDefinition(sprintf('attr_%02d', $i), 'string');
        }

        $model = $engine->schemaBuilder()->createModel(1, 'wide', $fields);

        foreach ($fields as $field) {
            $fieldId = $model->fieldId($field->name);
            $engine->promoteFieldToFilterable(1, $fieldId);
            $this->drain($engine, $fieldId);
        }

        return [$engine, $model->modelId];
    }

    /**
     * Drain until the field holds a live slot. The bound guards against a
     * hung fixture; it is not a tuning parameter.
     */
    private function drain(StarDust $engine, int $fieldId): void
    {
        for ($i = 0; $i < 12; $i++) {
            $engine->watcher()->tick();
            $engine->reconciler()->tick();

            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM stardust_slot_assignments'
                . " WHERE field_id = ? AND status IN ('assigned', 'ready')"
            );
            $stmt->execute([$fieldId]);

            if ((int) $stmt->fetchColumn() === 1) {
                return;
            }
        }

        self::fail("Field {$fieldId} never reached a live slot.");
    }

    /** Every `str` column on every page the model touches, per page. */
    private function assertEveryPageIsFullOfStringSlots(int $expectedPages): void
    {
        $rows = $this->pdo->query(
            "SELECT page_id, COUNT(*) AS n FROM stardust_slot_assignments"
            . " WHERE slot_type = 'str' GROUP BY page_id ORDER BY page_id"
        );
        self::assertNotFalse($rows);

        $counts = [];
        foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[] = (int) $row['n'];
        }

        self::assertCount($expectedPages, $counts, 'Fixture must span the expected number of pages.');
        foreach ($counts as $n) {
            self::assertSame(
                self::HEADROOM,
                $n,
                'Each page carries exactly k string columns, so the layout is already optimal.',
            );
        }
    }

    /**
     * `k + 1` fields — the first size that misreports.
     *
     * Five string fields cannot share one four-column page, so two pages
     * is the floor and `excess_pages` must be zero.
     */
    public function testModelAtItsPageCapacityFloorReportsNoExcess(): void
    {
        [, $modelId] = $this->promoteSerially(self::HEADROOM + 1);

        $this->assertEveryPageIsFullOfStringSlots(2);

        $samples = $this->makeSpreadSampler(new NullLogger())->report(1, $modelId);
        self::assertCount(1, $samples);
        self::assertSame(self::HEADROOM + 1, $samples[0]->liveSlotCount);
        self::assertSame(2, $samples[0]->pagesOccupied);
        self::assertSame(2, $samples[0]->theoreticalMinPages, 'Five str fields need two four-column pages.');
        self::assertSame(0, $samples[0]->excessPages(), 'The layout is optimal; excess must be zero.');
    }

    /**
     * `2k + 1` fields — the first size that alerts.
     *
     * Nine fields over three full pages used to report `excess_pages: 2`,
     * crossing the default `spreadExcessPageThreshold` and firing the
     * advisory at an operator who has nothing to act on.
     */
    public function testModelAtItsPageCapacityFloorDoesNotAlert(): void
    {
        [, $modelId] = $this->promoteSerially(2 * self::HEADROOM + 1);

        $this->assertEveryPageIsFullOfStringSlots(3);

        $log = new class extends NullLogger {
            /** @var list<string> */
            public array $events = [];

            /** @param array<mixed> $context */
            public function info(string|\Stringable $message, array $context = []): void
            {
                $this->events[] = (string) ($context['event'] ?? '');
            }

            /** @param array<mixed> $context */
            public function warning(string|\Stringable $message, array $context = []): void
            {
                $this->events[] = (string) ($context['event'] ?? '');
            }
        };

        $samples = $this->makeSpreadSampler($log)->report(1, $modelId);

        self::assertCount(1, $samples);
        self::assertSame(3, $samples[0]->pagesOccupied);
        self::assertSame(3, $samples[0]->theoreticalMinPages);
        self::assertSame(0, $samples[0]->excessPages());

        self::assertContains('spread_sampled', $log->events);
        self::assertNotContains(
            'high_spread_model',
            $log->events,
            'A model at its floor must never fire the compaction advisory.',
        );
    }

    /**
     * The second symptom of the same wrong number: the planner starts its
     * search at the floor, so a floor of 1 against three real pages made
     * every candidate set inadmissible and the operation threw instead of
     * reporting that there was nothing to do.
     */
    public function testCompactionPlansANoopInsteadOfRefusing(): void
    {
        [$engine, $modelId] = $this->promoteSerially(2 * self::HEADROOM + 1);

        $plan = $engine->compactModel(1, $modelId, dryRun: true);

        self::assertTrue($plan->isNoop(), 'A model at its floor wants nothing from the operator.');
        self::assertSame(3, $plan->pagesBefore);
        self::assertSame(3, $plan->theoreticalMinPages);
        self::assertSame(0, $plan->excessPagesRemoved());
    }
}
