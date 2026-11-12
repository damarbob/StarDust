<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Read;

use InvalidArgumentException;
use PDO;
use Psr\Log\NullLogger;
use StarDust\Config\Config;
use StarDust\Exception\InvalidTenantIdException;
use StarDust\Exception\TooManyEntryIdsException;
use StarDust\Read\SchemaVersionCache;
use StarDust\Search\Mysql\MysqlNativeDriver;
use StarDust\StarDust;
use StarDust\Tests\Smoke\ReadPathTestCase;
use StarDust\Tests\Smoke\Support\BatchDelegatingSearchDriver;
use StarDust\Tests\Smoke\Support\DelegatingSearchDriver;
use StarDust\Tests\Smoke\Support\StatementRecordingPdo;
use StarDust\Tests\Smoke\Support\TestPdo;

/**
 * `StarDust::getMany()`, the facade over the batch point read.
 *
 * The driver's own behaviour is covered by `BatchPointReadTest`. These cases
 * cover what the facade owns: the input rules, what a driver is handed, and
 * the fallback for a driver that has no batch capability.
 */
final class GetManyTest extends ReadPathTestCase
{
    private function engine(?PDO $pdo = null, ?\StarDust\Search\EntrySearchInterface $driver = null): StarDust
    {
        return new StarDust(new Config(
            pdo:          $pdo ?? $this->pdo,
            logger:       new NullLogger(),
            searchDriver: $driver,
        ));
    }

    private function recording(): StatementRecordingPdo
    {
        return new StatementRecordingPdo(
            getenv('STARDUST_TEST_DSN') ?: '',
            getenv('STARDUST_TEST_USER') ?: '',
            getenv('STARDUST_TEST_PASS') ?: '',
            TestPdo::options(),
        );
    }

    private function realDriver(): MysqlNativeDriver
    {
        return new MysqlNativeDriver(
            pdo:    $this->pdo,
            logger: new NullLogger(),
            cache:  new SchemaVersionCache($this->pdo, new NullLogger()),
            engine: $this->engine,
        );
    }

    /**
     * @return list<int> the ids of `$count` entries of tenant 1
     */
    private function seedEntries(int $count): array
    {
        $modelId = $this->createModel(1);
        $this->createField($modelId, 'string', false, 'title');

        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $ids[] = $this->seedEntry(1, $modelId, ['title' => "entry {$i}"]);
        }

        return $ids;
    }

    public function testReturnsWhatGetReturnsForEachId(): void
    {
        $ids    = $this->seedEntries(3);
        $engine = $this->engine();

        $result = $engine->getMany(1, $ids);

        self::assertCount(3, $result);
        foreach ($ids as $id) {
            self::assertEquals($engine->get(1, $id), $result[$id], "entry {$id}");
        }
    }

    public function testIsKeyedByIdInFirstAppearanceOrderWithRepeatsCollapsed(): void
    {
        $ids = $this->seedEntries(3);

        $result = $this->engine()->getMany(1, [$ids[2], $ids[0], $ids[2], 987_654, $ids[1], $ids[0]]);

        self::assertSame([$ids[2], $ids[0], $ids[1]], array_keys($result));
    }

    public function testAWarmCallIsOneStatement(): void
    {
        $ids       = $this->seedEntries(3);
        $recording = $this->recording();
        $engine    = $this->engine($recording);

        $engine->getMany(1, $ids);
        $recording->statements = [];

        $engine->getMany(1, array_reverse($ids));

        self::assertCount(1, $recording->statements);
    }

    public function testAnEmptyListSendsNothing(): void
    {
        $this->seedEntries(1);
        $recording = $this->recording();
        $engine    = $this->engine($recording);
        $engine->getMany(1, [987_654]);
        $recording->statements = [];

        self::assertSame([], $engine->getMany(1, []));
        self::assertSame([], $recording->statements);
    }

    public function testAThousandIdsAreAcceptedAndOneMoreIsRefusedBeforeAnyStatement(): void
    {
        $ids       = $this->seedEntries(2);
        $recording = $this->recording();
        $engine    = $this->engine($recording);
        $thousand  = array_merge($ids, range(500_001, 500_998));
        self::assertCount(1000, $thousand);

        self::assertSame($ids, array_keys($engine->getMany(1, $thousand)));

        $recording->statements = [];
        try {
            $engine->getMany(1, array_merge($thousand, [600_000]));
            self::fail('1001 ids must be refused.');
        } catch (TooManyEntryIdsException $e) {
            self::assertStringContainsString('1000', $e->getMessage());
        }
        self::assertSame([], $recording->statements);
    }

    public function testTheCapCountsTheListAsPassedNotTheDistinctIds(): void
    {
        $this->expectException(TooManyEntryIdsException::class);

        $this->engine()->getMany(1, array_fill(0, 1001, 7));
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function nonIntegerLists(): array
    {
        return [
            'numeric string' => [[1, '2']],
            'float'          => [[1.0]],
            'null'           => [[null]],
            'bool'           => [[true]],
            'nested array'   => [[[1]]],
        ];
    }

    /**
     * @param array<mixed> $ids
     * @dataProvider nonIntegerLists
     */
    public function testANonIntegerElementIsRefusedBeforeAnyStatement(array $ids): void
    {
        $recording = $this->recording();
        $engine    = $this->engine($recording);
        $recording->statements = [];

        try {
            $engine->getMany(1, $ids);
            self::fail('A non-integer element must be refused.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('integer', $e->getMessage());
        }
        self::assertSame([], $recording->statements);
    }

    public function testATenantIdThatIsNotPositiveIsRefused(): void
    {
        $this->expectException(InvalidTenantIdException::class);

        $this->engine()->getMany(0, [1]);
    }

    public function testADriverWithoutTheCapabilityIsAskedOncePerDistinctId(): void
    {
        $ids      = $this->seedEntries(3);
        $driver   = new DelegatingSearchDriver($this->realDriver());
        $engine   = $this->engine(null, $driver);
        $builtIn  = $this->engine();
        $wanted   = [$ids[1], 987_654, $ids[1], $ids[0]];

        $result = $engine->getMany(1, $wanted);

        self::assertSame([[1, $ids[1]], [1, 987_654], [1, $ids[0]]], $driver->getCalls);
        self::assertSame([$ids[1], $ids[0]], array_keys($result));
        self::assertEquals($builtIn->getMany(1, $wanted), $result);
    }

    public function testADriverWithTheCapabilityIsHandedACleanListAndNoGetCalls(): void
    {
        $ids    = $this->seedEntries(3);
        $driver = new BatchDelegatingSearchDriver($this->realDriver());
        $engine = $this->engine(null, $driver);

        $result = $engine->getMany(1, [$ids[1], $ids[1], $ids[0], 987_654]);

        self::assertSame([[1, [$ids[1], $ids[0], 987_654]]], $driver->getManyCalls);
        self::assertSame(0, $driver->getCalls);
        self::assertSame([$ids[1], $ids[0]], array_keys($result));
    }

    public function testAnEmptyListNeverReachesADriverWithTheCapability(): void
    {
        $driver = new BatchDelegatingSearchDriver($this->realDriver());

        self::assertSame([], $this->engine(null, $driver)->getMany(1, []));
        self::assertSame([], $driver->getManyCalls);
    }
}
