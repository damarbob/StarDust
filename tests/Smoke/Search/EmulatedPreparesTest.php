<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Search;

use PDO;
use Psr\Log\NullLogger;
use StarDust\Clock\SystemClock;
use StarDust\Config\Config;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Read\EntryQuery;
use StarDust\Read\SortDirection;
use StarDust\Read\SortSpec;
use StarDust\Search\SearchRequest;
use StarDust\StarDust;
use StarDust\Tests\Smoke\ReadPathTestCase;
use StarDust\Tests\Smoke\Support\TestPdo;

/**
 * Pins that the public read surface runs on a connection with
 * `PDO::ATTR_EMULATE_PREPARES => true`, pdo_mysql's own default, and
 * so what a consumer who passes no options at all hands the engine.
 *
 * Under emulation the driver inlines bound values into the SQL text, and
 * a value bound untyped is inlined **quoted**: the bounded read's
 * `LIMIT ?` (page size + 1) became `LIMIT '4'`, and the first `read()`
 * or `search()` died with errno 1064. `Support\TypedBinding` is the fix;
 * this class is what fails if the probe drifts back to an untyped
 * `execute($bindings)` (verified by doing exactly that).
 *
 * **Emulation is forced on here regardless of
 * `STARDUST_TEST_EMULATE_PREPARES`**, so the default native-prepare run
 * still covers it; the emulated CI cell runs the rest of the suite that
 * way too. The fixture is seeded over the same emulated connection, so
 * the write path it goes through is exercised under emulation as well.
 *
 * Every read goes through the `StarDust` facade rather than
 * `EntryReader` / `SearchService`, because the facade is what a
 * consumer calls and the thing the defect broke.
 */
final class EmulatedPreparesTest extends ReadPathTestCase
{
    private StarDust $stardust;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO(
            (string) getenv('STARDUST_TEST_DSN'),
            (string) getenv('STARDUST_TEST_USER'),
            (string) (getenv('STARDUST_TEST_PASS') ?: ''),
            [PDO::ATTR_EMULATE_PREPARES => true] + TestPdo::options(),
        );
        $this->stardust = new StarDust(new Config(
            pdo: $this->pdo,
            logger: new NullLogger(),
            clock: new SystemClock(),
        ));
    }

    public function testConnectionReallyEmulates(): void
    {
        // Every other assertion here is vacuous on a native connection.
        self::assertTrue((bool) $this->pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES));
    }

    public function testFilteredReadAndSearch(): void
    {
        [$modelId, , , $fieldName] = $this->setupFilterableStringField();
        $keep = $this->seedEntry(1, $modelId, [$fieldName => 'Acme']);
        $this->seedEntry(1, $modelId, [$fieldName => 'Globex']);

        $filter = LeafNode::local($fieldName, 'eq', 'Acme');

        $read = $this->stardust->read(new EntryQuery(tenantId: 1, modelId: $modelId, filter: $filter, pageSize: 10));
        self::assertSame([$keep], array_map(static fn ($e): int => $e->id, $read->rows));
        self::assertSame('Acme', $read->rows[0]->fields[$fieldName]);

        $search = $this->stardust->search(new SearchRequest(tenantId: 1, modelId: $modelId, filter: $filter, pageSize: 10));
        self::assertSame([$keep], array_map(static fn ($e): int => $e->id, $search->rows));
    }

    public function testFieldSortedRead(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        $low  = $this->seedEntry(1, $modelId, [$names['int'] => 1]);
        $high = $this->seedEntry(1, $modelId, [$names['int'] => 30]);
        $mid  = $this->seedEntry(1, $modelId, [$names['int'] => 7]);

        $page = $this->stardust->read(new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
            pageSize: 10,
            sort: SortSpec::byField($names['int'], SortDirection::Desc),
        ));

        self::assertSame([$high, $mid, $low], array_map(static fn ($e): int => $e->id, $page->rows));
    }

    public function testSecondCursorPageOfReadAndSearch(): void
    {
        [$modelId, , , $fieldName] = $this->setupFilterableStringField();
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $this->seedEntry(1, $modelId, [$fieldName => 'row' . $i]);
        }

        $first = $this->stardust->read(new EntryQuery(tenantId: 1, modelId: $modelId, pageSize: 3));
        self::assertSame(array_slice($ids, 0, 3), array_map(static fn ($e): int => $e->id, $first->rows));
        self::assertNotNull($first->nextCursor, 'a 5-row set at page size 3 has a second page');

        $second = $this->stardust->read(new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
            pageSize: 3,
            cursor: $first->nextCursor,
        ));
        self::assertSame(array_slice($ids, 3), array_map(static fn ($e): int => $e->id, $second->rows));
        self::assertNull($second->nextCursor);

        // The same cursor resumes through search() too.
        $viaSearch = $this->stardust->search(new SearchRequest(
            tenantId: 1,
            modelId: $modelId,
            pageSize: 3,
            cursor: $first->nextCursor,
        ));
        self::assertSame(array_slice($ids, 3), array_map(static fn ($e): int => $e->id, $viaSearch->rows));
    }
}
