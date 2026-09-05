<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Watcher;

use PDO;
use Psr\Log\NullLogger;
use StarDust\Config\Config;
use StarDust\Schema\FieldDefinition;
use StarDust\StarDust;
use StarDust\Tests\Smoke\Phase6bTestCase;

/**
 * ADR 0042's acceptance criterion: three fields promoted one at a time
 * land on one page, not three.
 *
 * This is the measured repro from the ADR, inverted. Before index
 * headroom the same sequence produced `pages=3, free=177, free_ratio
 * 0.9833` with `high_spread_model` firing at `excess_pages: 2` — and the
 * state was unrecoverable, because `compactModel()` needs indexed free
 * slots to absorb the moves and demand-sized provisioning guarantees
 * there are none.
 *
 * **The promotions must be serial.** Promoting all three before a
 * Watcher tick already lands on one page under the old policy, because
 * the planner sees all three waiters at once — so a fixture that
 * promotes in parallel passes without the change and proves nothing.
 * Each promotion here is followed by its own Watcher tick and Reconciler
 * drain, which is the ordinary operator sequence and the worst case.
 *
 * The `co_located` assertion is the half that distinguishes this from a
 * cosmetic fix: ADR 0032 model affinity can only prefer a page that
 * holds an indexed free slot of the field's family, so under the old
 * policy it was structurally inert and every reservation logged
 * `affinity: fallback`. Headroom is what gives it something to prefer.
 */
final class SerialPromotionSpreadTest extends Phase6bTestCase
{
    /** @var list<array<string, mixed>> */
    private array $records = [];

    private function engine(): StarDust
    {
        return new StarDust(new Config(
            pdo: $this->pdo,
            logger: new NullLogger(),
        ));
    }

    /**
     * Drain until the field holds a live slot, or give up. The bound is a
     * guard against a hung fixture, not a tuning parameter — one promotion
     * needs a Watcher tick to provision and a Reconciler tick to reserve.
     */
    private function drain(StarDust $engine, int $fieldId): void
    {
        for ($i = 0; $i < 12; $i++) {
            $engine->watcher()->tick();
            $engine->reconciler()->tick();

            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) FROM stardust_slot_assignments"
                . " WHERE field_id = ? AND status IN ('assigned', 'ready')"
            );
            $stmt->execute([$fieldId]);

            if ((int) $stmt->fetchColumn() === 1) {
                return;
            }
        }

        self::fail("Field {$fieldId} never reached a live slot.");
    }

    public function testThreeSeriallyPromotedFieldsShareOnePage(): void
    {
        $engine = $this->engine();

        // All three start JSON-only, so each promotion is its own unit of
        // demand arriving after the previous page already exists.
        $model = $engine->schemaBuilder()->createModel(1, 'places', [
            new FieldDefinition('city', 'string'),
            new FieldDefinition('country', 'string'),
            new FieldDefinition('population', 'int'),
        ]);

        foreach (['city', 'country', 'population'] as $name) {
            $fieldId = $model->fieldId($name);
            $engine->promoteFieldToFilterable(1, $fieldId);
            $this->drain($engine, $fieldId);
        }

        $pages = (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_pages')->fetchColumn();
        self::assertSame(1, $pages, 'Serial promotion used to provision one page per field.');

        $samples = $this->makeSpreadSampler(new NullLogger())->report(1, $model->modelId);
        self::assertCount(1, $samples);
        self::assertSame(3, $samples[0]->liveSlotCount);
        self::assertSame(1, $samples[0]->pagesOccupied);
        self::assertSame(0, $samples[0]->excessPages(), 'excess_pages -> 0 is ADR 0031 stated success.');
    }

    public function testAffinityCoLocatesOnceHeadroomGivesItSomethingToPrefer(): void
    {
        $log = new class extends NullLogger {
            /** @var list<array<string, mixed>> */
            public array $records = [];

            /** @param array<mixed> $context */
            public function info(string|\Stringable $message, array $context = []): void
            {
                $this->records[] = $context;
            }
        };

        $engine = new StarDust(new Config(pdo: $this->pdo, logger: $log));

        $model = $engine->schemaBuilder()->createModel(1, 'places', [
            new FieldDefinition('city', 'string'),
            new FieldDefinition('country', 'string'),
        ]);

        foreach (['city', 'country'] as $name) {
            $fieldId = $model->fieldId($name);
            $engine->promoteFieldToFilterable(1, $fieldId);
            $this->drain($engine, $fieldId);
        }

        $affinities = [];
        foreach ($log->records as $record) {
            if (($record['event'] ?? null) === 'slot_reserved' && isset($record['affinity'])) {
                $affinities[] = $record['affinity'];
            }
        }

        self::assertNotEmpty($affinities, 'slot_reserved must report an affinity outcome.');
        self::assertContains(
            'co_located',
            $affinities,
            'Under demand-sized provisioning every reservation logged fallback, because affinity '
            . 'can only prefer a page holding an indexed free slot of the family and there never was one.',
        );
    }

    protected function tearDown(): void
    {
        $this->records = [];
        parent::tearDown();
    }
}
