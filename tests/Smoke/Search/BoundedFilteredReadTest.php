<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Search;

use Psr\Log\NullLogger;
use StarDust\Clock\SystemClock;
use StarDust\Filter\Ast\AndNode;
use StarDust\Filter\Ast\FilterNode;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Filter\Limits\FilterLimits;
use StarDust\Read\CursorCodec;
use StarDust\Read\EntryQuery;
use StarDust\Read\SchemaVersionCache;
use StarDust\Read\SortDirection;
use StarDust\Read\SortSpec;
use StarDust\Search\Mysql\SqlFilterCompiler;
use StarDust\Search\Mysql\SqlFragment;
use StarDust\Search\PreFlight\CapabilityChecker;
use StarDust\Search\PreFlight\FieldRefResolver;
use StarDust\Search\PreFlight\PreFlightPipeline;
use StarDust\Search\PreFlight\SortValidator;
use StarDust\Search\PreFlight\ValueTypeValidator;
use StarDust\Support\TypedBinding;
use StarDust\Tests\Smoke\Phase8TestCase;
use StarDust\Write\EntryChunkWriter;
use StarDust\Write\EntryPayload;
use StarDust\Write\SlotRowUpserter;

/**
 * A filtered read in the default order examines about a page of rows, not
 * the match set (ADR 0056), measured on a real server.
 *
 * Rows touched is the sum of the session's `Handler_read_*` counters across
 * the probe, and `Sort_rows` says whether it sorted. Both are server work,
 * unlike wall time, so other load on the host cannot change a verdict.
 *
 * **Why the fixture looks the way it does.**
 *
 *   - Two tenants whose entries interleave by id, so a tenant's rows are
 *     spread through `entry_data` and the explicit tenant predicate on the
 *     driving page is exercised.
 *   - Values come from MD5, not from a cycle. A periodic value correlates
 *     across pages, and a cross-page AND of two such columns then matches
 *     almost nothing.
 *   - One value shared by 50% of a tenant, one by 5% and one by 25%. The
 *     50% case is the one that exposed the regression in a `FORCE INDEX`
 *     alone, so a fixture with only selective values cannot tell the pinned
 *     shape from the broken one.
 *   - Tenant 1's `rank` and `rank2` live on different pages, which is what
 *     a cross-page AND needs.
 *
 * **MariaDB is already bounded for these filters**, so these assertions pass
 * there with or without the rewrite. The shape assertions (`STRAIGHT_JOIN`,
 * `FORCE INDEX`, the driving page first in `FROM`) are what pin it on every
 * engine, and the row-count ones can fail only on MySQL.
 *
 * Validated by neutering on MySQL 8.0.13, each time with the shape assertion
 * switched off so that only the row counts could fail:
 *
 *   - Driving-leaf selection returning nothing: the 5% integer equality
 *     touched 385 rows against a bound of 33, `int AND str` sorted 8 rows,
 *     and EXPLAIN reported a filesort.
 *   - `FORCE INDEX` removed with the reordering kept: the 5% equality on a
 *     descending cursor page touched 2 514 rows. The `int AND str` case did
 *     not go red at this fixture size, so it is not what pins the hint here.
 *   - The reordering removed with `FORCE INDEX` kept (the hint-only shape,
 *     `FROM entry_data` first): the 50% equality on a cursor page touched
 *     7 399 rows. This is the check that shows the pinned join order, and
 *     not the hint, is what bounds the read.
 */
final class BoundedFilteredReadTest extends Phase8TestCase
{
    private const PER_TENANT = 2500;
    private const PAGE_SIZE = 10;
    private const SALT_RANK = 'rank';

    /** Values and their share of a tenant: 0 is 50%, 1 is 5%, 2 is 25%. */
    private const HALF = 0;
    private const FIVE_PERCENT = 1;
    private const QUARTER = 2;

    private int $modelId = 0;
    private int $otherModelId = 0;

    /** @var array<string, int> field name to field id, tenant 1 */
    private array $fieldIds = [];

    private PreFlightPipeline $pipeline;
    private SchemaVersionCache $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $logger = new NullLogger();
        $this->cache = new SchemaVersionCache($this->pdo, $logger);
        $this->pipeline = new PreFlightPipeline(
            fieldRefResolver:   new FieldRefResolver($logger),
            capabilityChecker:  new CapabilityChecker($logger),
            valueTypeValidator: new ValueTypeValidator($logger, new FilterLimits()),
            sortValidator:      new SortValidator($logger),
        );
    }

    // ---------------------------------------------------------------------
    // Rows touched
    // ---------------------------------------------------------------------

    public function testEqualityTouchesAboutAPageInEveryFamilyDirectionAndSelectivity(): void
    {
        $this->seedFixture();
        $bound = 3 * (self::PAGE_SIZE + 1);

        $families = [
            'int'      => fn (int $v): LeafNode => LeafNode::local('rank', 'eq', $v),
            'numeric'  => fn (int $v): LeafNode => LeafNode::local('price', 'eq', $this->priceFor($v)),
            'datetime' => fn (int $v): LeafNode => LeafNode::local('due_at', 'eq', $this->dueAtFor($v)),
        ];

        foreach ($families as $family => $leaf) {
            foreach ([self::FIVE_PERCENT, self::HALF] as $value) {
                foreach ([SortDirection::Asc, SortDirection::Desc] as $direction) {
                    $first = $this->probe($leaf($value), $direction, null);
                    $this->assertDriven($first['fragment']);
                    $this->assertBounded($first, $bound, "{$family} value {$value} {$direction->value} page 1");

                    // A cursor page: the anchor is the last row of the first page.
                    self::assertGreaterThanOrEqual(self::PAGE_SIZE, count($first['ids']));
                    $anchor = $first['ids'][self::PAGE_SIZE - 1];
                    $later = $this->probe($leaf($value), $direction, $anchor);
                    $this->assertBounded($later, $bound, "{$family} value {$value} {$direction->value} cursor page");
                }
            }
        }
    }

    public function testAnAndIsBoundedByTheDrivingLeafsMatchCountNotThePageSize(): void
    {
        $this->seedFixture();

        // int AND str: the integer leaf drives. The string filter then
        // trims its rows, so the work follows the integer leaf's matches.
        $drivingMatches = $this->countTenantOneMatches('i_int_01', self::FIVE_PERCENT);
        self::assertGreaterThan(self::PAGE_SIZE, $drivingMatches, 'fixture: the driving leaf must match more than a page');

        $intAndStr = new AndNode([
            LeafNode::local('rank', 'eq', self::FIVE_PERCENT),
            LeafNode::local('title', 'eq', 'cat-3'),
        ]);
        $result = $this->probe($intAndStr, SortDirection::Asc, null);
        $this->assertDriven($result['fragment']);
        $this->assertBounded($result, 3 * ($drivingMatches + self::PAGE_SIZE + 1), 'int AND str');
        self::assertLessThan(
            self::PER_TENANT,
            $result['reads'],
            'the work must follow the driving leaf, not the tenant (a scan of the tenant would read at least this many rows)',
        );

        // Two 25% equalities on different pages: still the driving leaf's count.
        $quarterMatches = $this->countTenantOneMatches('i_int_01', self::QUARTER);
        $crossPage = new AndNode([
            LeafNode::local('rank', 'eq', self::QUARTER),
            LeafNode::local('rank2', 'eq', self::QUARTER),
        ]);
        $result = $this->probe($crossPage, SortDirection::Asc, null);
        $this->assertDriven($result['fragment']);
        $this->assertBounded($result, 3 * ($quarterMatches + self::PAGE_SIZE + 1), 'cross-page AND of two 25% equalities');
    }

    // ---------------------------------------------------------------------
    // Plan shape
    // ---------------------------------------------------------------------

    public function testTheDrivenQueryNeverFilesorts(): void
    {
        $this->seedFixture();

        $filters = [
            'int 5%'         => LeafNode::local('rank', 'eq', self::FIVE_PERCENT),
            'int 50%'        => LeafNode::local('rank', 'eq', self::HALF),
            'numeric 5%'     => LeafNode::local('price', 'eq', $this->priceFor(self::FIVE_PERCENT)),
            'datetime 5%'    => LeafNode::local('due_at', 'eq', $this->dueAtFor(self::FIVE_PERCENT)),
            'int AND str'    => new AndNode([
                LeafNode::local('rank', 'eq', self::FIVE_PERCENT),
                LeafNode::local('title', 'eq', 'cat-3'),
            ]),
        ];

        foreach ($filters as $label => $filter) {
            foreach ([SortDirection::Asc, SortDirection::Desc] as $direction) {
                $fragment = $this->fragment($filter, $direction, null);
                $stmt = $this->pdo->prepare('EXPLAIN ' . $fragment->sql);
                TypedBinding::bind($stmt, $fragment->bindings);
                $stmt->execute();
                $extras = array_map(
                    static fn (array $row): string => (string) ($row['Extra'] ?? $row['extra'] ?? ''),
                    $stmt->fetchAll(\PDO::FETCH_ASSOC),
                );

                foreach ($extras as $extra) {
                    self::assertStringNotContainsString('filesort', $extra, "{$label} {$direction->value}: {$fragment->sql}");
                    self::assertStringNotContainsString('temporary', $extra, "{$label} {$direction->value}: {$fragment->sql}");
                }
            }
        }
    }

    // ---------------------------------------------------------------------
    // Correctness
    // ---------------------------------------------------------------------

    public function testACursorWalkReturnsExactlyTheReferenceIdsAndOnlyTheTenantsOwn(): void
    {
        $this->seedFixture();

        $cases = [
            'int 5%'     => [LeafNode::local('rank', 'eq', self::FIVE_PERCENT), 'i_int_01', self::FIVE_PERCENT],
            'int 50%'    => [LeafNode::local('rank', 'eq', self::HALF), 'i_int_01', self::HALF],
            'numeric 5%' => [LeafNode::local('price', 'eq', $this->priceFor(self::FIVE_PERCENT)), 'i_num_01', $this->priceFor(self::FIVE_PERCENT)],
            'datetime 5%' => [LeafNode::local('due_at', 'eq', $this->dueAtFor(self::FIVE_PERCENT)), 'i_dt_01', $this->dbDatetime(self::FIVE_PERCENT)],
        ];

        foreach ($cases as $label => [$filter, $column, $value]) {
            $reference = $this->referenceIds($column, $value);
            self::assertNotEmpty($reference, "{$label}: fixture produced no match");

            foreach ([SortDirection::Asc, SortDirection::Desc] as $direction) {
                $walked = $this->walk($filter, $direction);
                $expected = $direction === SortDirection::Asc ? $reference : array_reverse($reference);

                self::assertSame($expected, $walked, "{$label} {$direction->value}: the walk disagrees with the reference query");
            }
        }

        // The walk above compares against tenant 1's own reference, so any
        // row of tenant 2 would already have failed it. Say it directly too.
        $foreign = (int) $this->pdo
            ->query('SELECT COUNT(*) FROM entry_data WHERE model_id = ' . $this->otherModelId)
            ->fetchColumn();
        self::assertGreaterThan(0, $foreign, 'fixture: the other tenant must hold rows');
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * @return array{ids: list<int>, reads: int, sorts: int, fragment: SqlFragment}
     */
    private function probe(FilterNode $filter, SortDirection $direction, ?int $afterId): array
    {
        $fragment = $this->fragment($filter, $direction, $afterId);

        $stmt = $this->pdo->prepare($fragment->sql);
        TypedBinding::bind($stmt, $fragment->bindings);

        // One unmeasured run first. Under emulated prepares nothing reaches
        // the server until execute(), so the first run of a statement also
        // pays to open its tables, which the native path pays at prepare.
        // That is a one-off cost of the session and not of the query.
        $stmt->execute();
        $stmt->fetchAll();

        // The counters read themselves, so measure that overhead and subtract it.
        $this->counters();
        $idle = $this->delta($this->counters(), $this->counters());

        $before = $this->counters();
        $stmt->execute();
        $ids = array_map(static fn ($v): int => (int) $v, $stmt->fetchAll(\PDO::FETCH_COLUMN));
        $used = $this->delta($before, $this->counters());

        return [
            'ids'      => $ids,
            'reads'    => max(0, $used['reads'] - $idle['reads']),
            'sorts'    => max(0, $used['sorts'] - $idle['sorts']),
            'fragment' => $fragment,
        ];
    }

    private function fragment(FilterNode $filter, SortDirection $direction, ?int $afterId): SqlFragment
    {
        $snapshot = $this->cache->snapshotForModel($this->modelId, 1, 'bounded-test');
        $driver = $this->makeMysqlDriver();
        $resolved = $this->pipeline->validate($filter, $snapshot, $driver, 1, 'bounded-test');

        $sort = $direction === SortDirection::Asc ? null : SortSpec::byId(SortDirection::Desc);

        return (new SqlFilterCompiler())->compile(
            $resolved,
            new EntryQuery(
                tenantId: 1,
                modelId:  $this->modelId,
                filter:   $resolved,
                pageSize: self::PAGE_SIZE,
                cursor:   $afterId === null ? null : CursorCodec::encodeFor($sort, $afterId),
                sort:     $sort,
            ),
            $snapshot,
        );
    }

    /** @return array<string, int> */
    private function counters(): array
    {
        $rows = $this->pdo
            ->query("SHOW SESSION STATUS WHERE Variable_name LIKE 'Handler\\_read%' OR Variable_name = 'Sort_rows'")
            ->fetchAll(\PDO::FETCH_KEY_PAIR);

        return array_map('intval', $rows);
    }

    /**
     * @param array<string, int> $before
     * @param array<string, int> $after
     * @return array{reads: int, sorts: int}
     */
    private function delta(array $before, array $after): array
    {
        $reads = 0;
        foreach ($after as $name => $value) {
            if (str_starts_with($name, 'Handler_read')) {
                $reads += $value - ($before[$name] ?? 0);
            }
        }

        return [
            'reads' => $reads,
            'sorts' => ($after['Sort_rows'] ?? 0) - ($before['Sort_rows'] ?? 0),
        ];
    }

    /** @param array{ids: list<int>, reads: int, sorts: int, fragment: SqlFragment} $result */
    private function assertBounded(array $result, int $maxReads, string $label): void
    {
        self::assertLessThanOrEqual(
            $maxReads,
            $result['reads'],
            "{$label}: {$result['reads']} rows touched, bound {$maxReads}. SQL: {$result['fragment']->sql}",
        );
        self::assertSame(0, $result['sorts'], "{$label}: the probe sorted {$result['sorts']} rows. SQL: {$result['fragment']->sql}");
    }

    /** The shape itself, which a MariaDB run cannot show through row counts. */
    private function assertDriven(SqlFragment $fragment): void
    {
        self::assertStringStartsWith('SELECT STRAIGHT_JOIN entry_data.id FROM entry_slots_page_', $fragment->sql);
        self::assertStringContainsString(' FORCE INDEX (`', $fragment->sql);
        self::assertMatchesRegularExpression('/ORDER BY p\d+\.entry_id (ASC|DESC) LIMIT \?$/', $fragment->sql);
    }

    /** Tenant 1's rows whose page column equals the given value. */
    private function countTenantOneMatches(string $column, int $value): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM entry_slots_page_1 WHERE tenant_id = 1 AND ' . $column . ' = ?'
        );
        $stmt->execute([$value]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Ids from a plain query with no hints, in ascending order, for tenant 1
     * and the given page-1 column value.
     *
     * @return list<int>
     */
    private function referenceIds(string $column, int|float|string $value): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT d.id FROM entry_data d JOIN entry_slots_page_1 p ON p.entry_id = d.id AND p.tenant_id = d.tenant_id"
            . " WHERE d.tenant_id = 1 AND d.model_id = ? AND d.deleted_at IS NULL AND p.{$column} = ? ORDER BY d.id"
        );
        $stmt->execute([$this->modelId, $value]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Every id a cursor walk through `read()` returns, in walk order.
     *
     * @return list<int>
     */
    private function walk(FilterNode $filter, SortDirection $direction): array
    {
        $sort = $direction === SortDirection::Asc ? null : SortSpec::byId(SortDirection::Desc);
        $ids = [];
        $cursor = null;
        for ($page = 0; $page < 500; $page++) {
            $result = $this->reader()->read(new EntryQuery(
                tenantId: 1,
                modelId:  $this->modelId,
                filter:   $filter,
                pageSize: 50,
                cursor:   $cursor,
                sort:     $sort,
            ));
            foreach ($result->rows as $entry) {
                self::assertSame(1, $entry->tenantId, 'a row of another tenant came back');
                $ids[] = $entry->id;
            }
            $cursor = $result->nextCursor;
            if ($cursor === null) {
                return $ids;
            }
        }

        self::fail('the walk did not end');
    }

    // ---------------------------------------------------------------------
    // Fixture
    // ---------------------------------------------------------------------

    private function seedFixture(): void
    {
        // Tenant 1's four fields fill the first page's only slot of each
        // family, so `rank2` can only land on the second page.
        $this->provisionPage(['i_str_01', 'i_int_01', 'i_num_01', 'i_dt_01']);
        $this->modelId = $this->createModel(1, 'bounded_one');
        $this->fieldIds = $this->registerFields($this->modelId, ['title', 'rank', 'price', 'due_at']);

        $this->provisionPage(['i_int_01']);
        $this->fieldIds += $this->registerFields($this->modelId, ['rank2']);

        // Tenant 2 starts once the first two pages are full, so its slots
        // are on a third page and never share a column with tenant 1.
        $this->provisionPage(['i_str_01', 'i_int_01', 'i_num_01', 'i_dt_01', 'i_int_02']);
        $this->otherModelId = $this->createModel(2, 'bounded_two');
        $this->registerFields($this->otherModelId, ['title', 'rank', 'price', 'due_at', 'rank2']);

        $pageOf = fn (string $field): int => (int) ($this->fetchLiveSlotForField($this->fieldIds[$field])['page_id'] ?? 0);
        self::assertSame(1, $pageOf('rank'), 'fixture: rank must be on the first page');
        self::assertSame(2, $pageOf('rank2'), 'fixture: rank2 must be on the second page');

        $writer = new EntryChunkWriter($this->pdo, new SystemClock(), new SlotRowUpserter($this->pdo));
        $payloads = [];
        for ($i = 0; $i < self::PER_TENANT; $i++) {
            // Interleaved: tenant 1, tenant 2, tenant 1, ...
            foreach ([[1, $this->modelId], [2, $this->otherModelId]] as [$tenant, $model]) {
                $payloads[] = new EntryPayload($tenant, $model, [
                    'title'  => 'cat-' . ($this->bucket($tenant, $i, 'title') % 10),
                    'rank'   => $this->valueOf($this->bucket($tenant, $i, self::SALT_RANK)),
                    'rank2'  => $this->valueOf($this->bucket($tenant, $i, 'rank2')),
                    'price'  => $this->priceFor($this->valueOf($this->bucket($tenant, $i, 'price'))),
                    'due_at' => $this->dueAtFor($this->valueOf($this->bucket($tenant, $i, 'due_at'))),
                ]);
            }
            if (count($payloads) >= 500) {
                $this->writeCommitted($writer, $payloads);
                $payloads = [];
            }
        }
        $this->writeCommitted($writer, $payloads);

        // Fresh statistics, so the optimiser plans as it would on a settled table.
        $this->pdo->query('ANALYZE TABLE entry_data, entry_slots_page_1, entry_slots_page_2, entry_slots_page_3')->fetchAll();
    }

    /**
     * @param list<string> $names
     * @return array<string, int>
     */
    private function registerFields(int $modelId, array $names): array
    {
        $types = ['title' => 'string', 'rank' => 'int', 'rank2' => 'int', 'price' => 'numeric', 'due_at' => 'datetime'];
        $ids = [];
        foreach ($names as $name) {
            $ids[$name] = $this->createField($modelId, $types[$name], true, $name);
            $this->reserveSlotFor($ids[$name]);
        }

        return $ids;
    }

    /** @param list<EntryPayload> $payloads */
    private function writeCommitted(EntryChunkWriter $writer, array $payloads): void
    {
        if ($payloads === []) {
            return;
        }
        $this->pdo->beginTransaction();
        $writer->writeChunk($payloads);
        $this->pdo->commit();
    }

    /** 0 to 99, taken from an MD5 so no value repeats on a cycle. */
    private function bucket(int $tenant, int $i, string $salt): int
    {
        return hexdec(substr(md5("{$tenant}:{$i}:{$salt}"), 0, 7)) % 100;
    }

    /** 50% become 0, 5% become 1, 25% become 2 and the rest spread over 3 to 19. */
    private function valueOf(int $bucket): int
    {
        return match (true) {
            $bucket < 50 => self::HALF,
            $bucket < 55 => self::FIVE_PERCENT,
            $bucket < 80 => self::QUARTER,
            default      => 3 + $bucket % 17,
        };
    }

    /** A quarter step is exact in binary, so the DOUBLE slot compares equal. */
    private function priceFor(int $value): float
    {
        return $value + 0.25;
    }

    private function dueAtFor(int $value): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', 1767225600 + $value * 86400);
    }

    /** What the datetime slot stores for a value, for the reference query. */
    private function dbDatetime(int $value): string
    {
        return gmdate('Y-m-d H:i:s', 1767225600 + $value * 86400);
    }
}
