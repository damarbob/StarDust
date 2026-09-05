<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Watcher;

use PDO;
use Psr\Log\NullLogger;
use StarDust\Config\Config;
use StarDust\Schema\FieldDefinition;
use StarDust\StarDust;
use StarDust\Tests\Smoke\Phase5TestCase;

/**
 * `Config::$pageIndexHeadroom` reaches the page DDL (ADR 0042).
 *
 * This test exists because the value's whole journey — `Config` →
 * `StarDust::watcher()` → `Watcher` → `ProvisioningPlanner::plan()` →
 * `PageProvisioner::provision()` — had **no** coverage. Every other
 * Watcher test builds its daemon through `Phase5TestCase::makeWatcher()`,
 * which constructs `Watcher` directly and therefore never exercises the
 * factory; and no test in the suite calls `StarDust::watcher()` at all.
 * A wrong field name or a dropped named argument in that factory would
 * have left the entire suite green.
 *
 * So this is deliberately an *end-to-end* assertion against
 * `information_schema` rather than a unit test of the planner: the
 * planner's arithmetic is already covered by `ProvisioningPlannerTest`,
 * and duplicating it here would re-test the one link that was never in
 * doubt while leaving the untested ones untested.
 *
 * It reads at the shipped default of 1 as well as at a raised value,
 * because a test that only asserts the raised case cannot tell a live
 * wire from a hard-coded constant.
 */
final class IndexHeadroomWiringTest extends Phase5TestCase
{
    /**
     * @return list<string> second-position columns of every composite
     *         index on the page — i.e. the slot columns actually indexed
     */
    private function indexedColumnsOf(string $table): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT COLUMN_NAME FROM information_schema.STATISTICS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND SEQ_IN_INDEX = 2'
            . ' ORDER BY COLUMN_NAME'
        );
        $stmt->execute([$table]);

        /** @var list<string> $cols */
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return $cols;
    }

    /**
     * One filterable string field with no slot is exactly one unit of
     * `str` demand, so the page the Watcher provisions for it indexes
     * `max(shortfall, headroom)` string columns — and with a shortfall
     * of one, that is the headroom alone.
     *
     * @return list<string>
     */
    private function provisionThroughFacade(int $headroom): array
    {
        $engine = new StarDust(new Config(
            pdo: $this->pdo,
            logger: new NullLogger(),
            pageIndexHeadroom: $headroom,
        ));

        $engine->schemaBuilder()->createModel(1, 'places', [
            new FieldDefinition('city', 'string', isFilterable: true),
        ]);

        $engine->watcher()->tick();

        $table = $this->pdo->query('SELECT table_name FROM stardust_pages ORDER BY id')->fetchColumn();
        self::assertIsString($table, 'The Watcher should have provisioned a page for the waiting field.');

        return $this->indexedColumnsOf($table);
    }

    public function testDefaultHeadroomIndexesOneColumnOfTheDemandedFamily(): void
    {
        self::assertSame(['i_str_01'], $this->provisionThroughFacade(1));
    }

    public function testRaisedHeadroomWidensTheIndexedSetOnANewPage(): void
    {
        self::assertSame(
            ['i_str_01', 'i_str_02', 'i_str_03', 'i_str_04', 'i_str_05'],
            $this->provisionThroughFacade(5),
            'Config::$pageIndexHeadroom must reach PageProvisioner through StarDust::watcher().',
        );
    }
}
