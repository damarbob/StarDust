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
 * It reads at the shipped default as well as at a raised value and at
 * zero, because a test that only asserts the raised case cannot tell a
 * live wire from a hard-coded constant, and one that only asserts the
 * default cannot tell the wire from a literal.
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
     * `max(shortfall, headroom)` string columns — and with a shortfall of
     * one, that is the headroom alone. Since ADR 0042 the other three
     * families are indexed to the headroom too, with no floor, which is
     * what makes the `k = 0` case below collapse to a single column.
     *
     * @return list<string>
     */
    private function provisionThroughFacade(?int $headroom = null): array
    {
        $engine = new StarDust(new Config(
            pdo: $this->pdo,
            logger: new NullLogger(),
            // null means "do not pass it", so the shipped Config default is
            // what the assertion reads. That is the point of the first case
            // below: it locks the constant, not merely the wire.
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

    /**
     * The shipped default, read by omitting the parameter rather than by
     * restating it — so this fails if `Config`'s default moves and the
     * expectation here does not.
     */
    public function testShippedDefaultIndexesFourColumnsOfEveryFamily(): void
    {
        self::assertSame(
            [
                'i_dt_01', 'i_dt_02', 'i_dt_03', 'i_dt_04',
                'i_int_01', 'i_int_02', 'i_int_03', 'i_int_04',
                'i_num_01', 'i_num_02', 'i_num_03', 'i_num_04',
                'i_str_01', 'i_str_02', 'i_str_03', 'i_str_04',
            ],
            $this->provisionThroughFacade(),
            'Sixteen indexed columns at k = 4, ordered by name from information_schema.',
        );
    }

    /**
     * A raised value must actually travel. Without this the case above
     * cannot tell a live wire from a hard-coded constant of the same value.
     */
    public function testRaisedHeadroomWidensTheIndexedSetOnANewPage(): void
    {
        $columns = $this->provisionThroughFacade(6);

        self::assertCount(24, $columns, 'Six of every family.');
        self::assertSame(
            ['i_str_01', 'i_str_02', 'i_str_03', 'i_str_04', 'i_str_05', 'i_str_06'],
            array_values(array_filter($columns, static fn (string $c): bool => str_starts_with($c, 'i_str_'))),
            'Config::$pageIndexHeadroom must reach PageProvisioner through StarDust::watcher().',
        );
    }

    /**
     * `k = 0` is the documented opt-out to the pre-ADR-0042 policy, and it
     * must survive the whole wiring rather than only the planner: the
     * demanded family keeps its floor of one column, and nothing else is
     * indexed.
     */
    public function testZeroHeadroomRestoresThePre0042PageShape(): void
    {
        self::assertSame(['i_str_01'], $this->provisionThroughFacade(0));
    }
}
