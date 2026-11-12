<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Read;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use StarDust\Read\SchemaVersionCache;
use StarDust\Reconciler\TickOutcome;
use StarDust\Search\BatchPointReadInterface;
use StarDust\Search\Mysql\MysqlNativeDriver;
use StarDust\Tests\Smoke\Phase6bTestCase;
use StarDust\Tests\Smoke\Support\StatementRecordingPdo;
use StarDust\Tests\Smoke\Support\TestPdo;

/**
 * The built-in driver's batch point read.
 *
 * The contract is that `getMany()` gives, for every id, the answer `get()`
 * gives, so most cases compare the two entry for entry. The windows are the
 * ones `get()` already handles (a rename and a field deletion still draining,
 * and a model being deleted), because a batch that skipped them would hand
 * back what the rest of the engine has stopped returning.
 */
final class BatchPointReadTest extends Phase6bTestCase
{
    private function driver(?PDO $pdo = null, ?LoggerInterface $logger = null): MysqlNativeDriver
    {
        $pdo    ??= $this->pdo;
        $logger ??= new NullLogger();

        return new MysqlNativeDriver(
            pdo:    $pdo,
            logger: $logger,
            cache:  new SchemaVersionCache($pdo, $logger),
            engine: $this->engine,
        );
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

    /**
     * Statements sent since the last call. Clears the recording.
     */
    private function sent(StatementRecordingPdo $pdo): int
    {
        $count = count($pdo->statements);
        $pdo->statements = [];

        return $count;
    }

    /**
     * @return array{0: int, 1: list<int>} model id and the ids of `$count` entries
     */
    private function modelWithEntries(int $tenantId, string $name, int $count): array
    {
        $modelId = $this->createModel($tenantId, $name);
        $this->createField($modelId, 'string', false, 'title');
        $this->createField($modelId, 'int', false, 'rank');

        $ids = [];
        for ($i = 1; $i <= $count; $i++) {
            $ids[] = $this->seedEntry($tenantId, $modelId, ['title' => "{$name} {$i}", 'rank' => $i]);
        }

        return [$modelId, $ids];
    }

    public function testTheDriverAdvertisesTheCapability(): void
    {
        self::assertInstanceOf(BatchPointReadInterface::class, $this->driver());
    }

    public function testReturnsTheEntriesThatExistKeyedByIdInTheOrderGiven(): void
    {
        [, $ids] = $this->modelWithEntries(1, 'article', 3);

        $result = $this->driver()->getMany(1, [$ids[2], $ids[0], $ids[1]]);

        self::assertSame([$ids[2], $ids[0], $ids[1]], array_keys($result));
        self::assertSame('article 3', $result[$ids[2]]->fields['title']);
    }

    public function testEveryEntryMatchesWhatGetReturns(): void
    {
        [, $articles] = $this->modelWithEntries(1, 'article', 3);
        [, $notes]    = $this->modelWithEntries(1, 'note', 2);
        $driver = $this->driver();

        $all    = array_merge($articles, $notes);
        $result = $driver->getMany(1, $all);

        self::assertCount(5, $result);
        foreach ($all as $id) {
            self::assertEquals($driver->get(1, $id), $result[$id], "entry {$id}");
        }
    }

    public function testLeavesOutAMissingAnOtherTenantsAndASoftDeletedEntry(): void
    {
        [, $mine]  = $this->modelWithEntries(1, 'article', 2);
        [, $other] = $this->modelWithEntries(2, 'article', 1);
        $this->pdo->exec('UPDATE entry_data SET deleted_at = UTC_TIMESTAMP() WHERE id = ' . $mine[1]);
        $driver = $this->driver();

        $result = $driver->getMany(1, [$mine[0], $mine[1], $other[0], 987_654]);

        self::assertSame([$mine[0]], array_keys($result));
        self::assertNull($driver->get(1, $mine[1]), 'Fixture guard for the soft-deleted entry.');
        self::assertNull($driver->get(1, $other[0]), 'Fixture guard for the other tenant.');
    }

    public function testAnIdListWithNothingFoundReturnsAnEmptyArrayAndLoadsNoSnapshot(): void
    {
        $recording = $this->recording();
        $driver    = $this->driver($recording);
        $this->sent($recording);

        self::assertSame([], $driver->getMany(1, [987_654, 987_655]));
        self::assertSame(1, $this->sent($recording), 'The entry statement alone.');
    }

    public function testARenameStillDrainingIsReadTheWayGetReadsIt(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'title');
        $ids = [];
        for ($i = 1; $i <= 6; $i++) {
            $ids[] = $this->seedEntry(1, $modelId, ['title' => "v{$i}"]);
        }
        $this->makeRenameInitiator()->initiate(1, $fieldId, 'headline');
        self::assertSame(TickOutcome::WORK_DONE, $this->runRenameTick(null, 2));

        $driver = $this->driver();
        $result = $driver->getMany(1, $ids);

        self::assertCount(6, $result);
        foreach ($ids as $id) {
            self::assertArrayHasKey('headline', $result[$id]->fields, "entry {$id}");
            self::assertArrayNotHasKey('title', $result[$id]->fields, "entry {$id}");
            self::assertEquals($driver->get(1, $id), $result[$id], "entry {$id}");
        }
    }

    public function testAFieldDeletionStillDrainingIsReadTheWayGetReadsIt(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'colour');
        $this->createField($modelId, 'string', false, 'shape');
        $ids = [];
        for ($i = 1; $i <= 6; $i++) {
            $ids[] = $this->seedEntry(1, $modelId, ['colour' => "c{$i}", 'shape' => 'round']);
        }
        self::assertTrue($this->makeDeleteFieldInitiator()->initiate(1, $fieldId));
        self::assertSame(TickOutcome::WORK_DONE, $this->runDeleteTick(null, 2));
        self::assertArrayHasKey('colour', $this->rawPayload($ids[5]) ?? [], 'Fixture guard: residue remains.');

        $driver = $this->driver();
        $result = $driver->getMany(1, $ids);

        self::assertCount(6, $result);
        foreach ($ids as $id) {
            self::assertArrayNotHasKey('colour', $result[$id]->fields, "entry {$id}");
            self::assertSame('round', $result[$id]->fields['shape'], "entry {$id}");
            self::assertEquals($driver->get(1, $id), $result[$id], "entry {$id}");
        }
    }

    public function testAModelBeingDeletedContributesNothingAndItsNeighboursAreUnaffected(): void
    {
        [$doomed, $doomedIds] = $this->modelWithEntries(1, 'invoice', 4);
        [, $keptIds]          = $this->modelWithEntries(1, 'article', 2);
        self::assertTrue($this->makeDeleteModelInitiator()->initiate(1, $doomed));
        self::assertSame(TickOutcome::WORK_DONE, $this->runModelPurgeTick(null, 2));

        $driver = $this->driver();
        $result = $driver->getMany(1, array_merge($doomedIds, $keptIds));

        self::assertSame($keptIds, array_keys($result));
        foreach ($doomedIds as $id) {
            self::assertNull($driver->get(1, $id), "get() agrees for entry {$id}.");
        }
    }

    public function testAWarmBatchIsOneStatement(): void
    {
        [, $ids] = $this->modelWithEntries(1, 'article', 3);
        $recording = $this->recording();
        $driver    = $this->driver($recording);
        $this->sent($recording);

        $driver->getMany(1, $ids);
        $this->sent($recording);

        $driver->getMany(1, array_reverse($ids));
        self::assertSame(1, $this->sent($recording));
    }

    public function testAColdBatchLoadsOneSnapshotPerModel(): void
    {
        [, $articles] = $this->modelWithEntries(1, 'article', 2);
        [, $notes]    = $this->modelWithEntries(1, 'note', 2);

        $recording = $this->recording();
        $driver    = $this->driver($recording);
        $this->sent($recording);
        $driver->getMany(1, $articles);
        self::assertSame(2, $this->sent($recording), 'The entry statement and the registry join.');

        $recording = $this->recording();
        $driver    = $this->driver($recording);
        $this->sent($recording);
        $driver->getMany(1, array_merge($articles, $notes));
        self::assertSame(3, $this->sent($recording), 'The entry statement and one registry join per model.');
    }

    public function testAColdBatchReportsOneCacheMissPerModelUnderOneCorrelationId(): void
    {
        [, $articles] = $this->modelWithEntries(1, 'article', 2);
        [, $notes]    = $this->modelWithEntries(1, 'note', 2);
        $logger = $this->makeRecordingLogger();
        $driver = $this->driver(null, $logger);

        $driver->getMany(1, array_merge($articles, $notes));
        $misses = $this->recordsWithEvent($logger->records(), 'cache_miss');

        self::assertCount(2, $misses);
        self::assertSame($misses[0]['context']['correlation_id'], $misses[1]['context']['correlation_id']);

        $driver->getMany(1, $articles);
        self::assertCount(2, $this->recordsWithEvent($logger->records(), 'cache_miss'), 'A warm batch reports none.');
    }

    public function testAThousandIdsAreOneStatement(): void
    {
        [, $ids] = $this->modelWithEntries(1, 'article', 3);
        $thousand = array_merge($ids, range(500_001, 500_997));
        self::assertCount(1000, $thousand);

        $recording = $this->recording();
        $driver    = $this->driver($recording);
        $this->sent($recording);

        $result = $driver->getMany(1, $thousand);

        self::assertSame($ids, array_keys($result));
        self::assertSame(2, $this->sent($recording), 'The entry statement and the registry join.');
    }
}
