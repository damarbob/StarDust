<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Search;

use Psr\Log\NullLogger;
use StarDust\Config\Config;
use StarDust\Exception\FieldNotFilterableException;
use StarDust\Exception\FieldNotSortableException;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Read\EntryQuery;
use StarDust\Read\SchemaVersionCache;
use StarDust\Read\SlotResolver;
use StarDust\Read\SortSpec;
use StarDust\Search\Mysql\MysqlNativeDriver;
use StarDust\Search\SearchRequest;
use StarDust\Slot\LiveSlotTombstoner;
use StarDust\StarDust;
use StarDust\Tests\Smoke\Phase8TestCase;
use StarDust\Tests\Smoke\Support\StatementRecordingPdo;
use StarDust\Tests\Smoke\Support\TestPdo;

/**
 * The built-in driver judges a field from the snapshot a request has just
 * checked, and from the database on every other path.
 *
 * Pre-flight asks whether each filter leaf's field is filterable, and a field
 * sort asks whether the field is sortable. The per-request copy of the driver
 * answers both from the snapshot, which is why a filtered read sends no
 * statement for it. That is only safe while the snapshot says what the database
 * would, so the first test compares the two on every state a field can be in.
 * The rest pin the properties that keep the shortcut honest: the shared driver
 * never answers from a cache, and a field whose state changed is judged by its
 * new state on the very next read, whether the service and the driver share a
 * schema-version cache or not.
 */
final class SnapshotCapabilityTest extends Phase8TestCase
{
    /**
     * One model with a field in each state the answer depends on, on one page.
     *
     * @return array{0: int, 1: array<string, int>, 2: array<string, bool>}
     *         model id, field id by state, and whether the database says the
     *         field is filterable now
     */
    private function modelWithAFieldInEveryState(): array
    {
        $this->provisionPage(['i_str_01', 'i_str_02', 'i_int_01', 'i_int_02', 'i_num_01', 'i_dt_01', 'i_dt_02']);
        $modelId = $this->createModel(1);

        $ids = [];
        $ids['assigned'] = $this->createField($modelId, 'string', true, 'f_assigned');
        $this->reserveSlotFor($ids['assigned']);

        $ids['grandfathered'] = $this->createField($modelId, 'string', false, 'f_grandfathered');
        $this->forceGrandfatheredSlotFor($ids['grandfathered'], 'str');

        $ids['ready'] = $this->createField($modelId, 'int', true, 'f_ready');
        $this->reserveSlotFor($ids['ready']);
        $this->setSlotStatus($ids['ready'], 'ready');

        $ids['backfilling'] = $this->createField($modelId, 'numeric', true, 'f_backfilling');
        $this->reserveSlotFor($ids['backfilling']);
        $this->setSlotStatus($ids['backfilling'], 'backfilling');

        // Not reachable through the engine, which clears `field_id` before it
        // flips a slot to tombstoned. Built by hand so the two answers are
        // compared on it as well.
        $ids['tombstoned, still attached'] = $this->createField($modelId, 'datetime', true, 'f_tombstoned');
        $this->reserveSlotFor($ids['tombstoned, still attached']);
        $this->setSlotStatus($ids['tombstoned, still attached'], 'tombstoned');

        $ids['tombstoned, released'] = $this->createField($modelId, 'datetime', true, 'f_released');
        $this->reserveSlotFor($ids['tombstoned, released']);
        (new LiveSlotTombstoner($this->pdo))->tombstone($ids['tombstoned, released'], gmdate('Y-m-d H:i:s'));

        $ids['unmapped'] = $this->createField($modelId, 'string', true, 'f_unmapped');
        $ids['not filterable'] = $this->createField($modelId, 'string', false, 'f_plain');

        $ids['being deleted'] = $this->createField($modelId, 'int', true, 'f_deleted');
        $this->reserveSlotFor($ids['being deleted']);
        self::assertTrue($this->makeDeleteFieldInitiator()->initiate(1, $ids['being deleted']));

        $expected = [
            'assigned'                   => true,
            'grandfathered'              => false,
            'ready'                      => true,
            'backfilling'                => false,
            'tombstoned, still attached' => false,
            'tombstoned, released'       => false,
            'unmapped'                   => false,
            'not filterable'             => false,
            'being deleted'              => false,
        ];

        return [$modelId, $ids, $expected];
    }

    private function setSlotStatus(int $fieldId, string $status): void
    {
        $this->pdo->prepare(
            'UPDATE stardust_slot_assignments SET status = ?, tombstoned_at = '
            . ($status === 'tombstoned' ? 'UTC_TIMESTAMP()' : 'NULL')
            . ' WHERE field_id = ?'
        )->execute([$status, $fieldId]);
    }

    private function bumpSchemaVersion(): void
    {
        $this->pdo->exec('UPDATE stardust_schema_version SET version = version + 1 WHERE id = 1');
    }

    private function liveVersion(): int
    {
        return (int) $this->pdo->query('SELECT version FROM stardust_schema_version WHERE id = 1')->fetchColumn();
    }

    private function recordingPdo(): StatementRecordingPdo
    {
        return new StatementRecordingPdo(
            getenv('STARDUST_TEST_DSN') ?: '',
            getenv('STARDUST_TEST_USER') ?: '',
            getenv('STARDUST_TEST_PASS') ?: '',
            TestPdo::options(),
        );
    }

    public function testTheCopyAnswersEveryFieldStateAsTheDatabaseDoes(): void
    {
        [$modelId, $ids, $expected] = $this->modelWithAFieldInEveryState();

        $pdo = $this->recordingPdo();
        $shared = new MysqlNativeDriver($pdo, new NullLogger(), new SchemaVersionCache($pdo, new NullLogger()));
        $copy = $shared->withVerifiedSnapshot(SlotResolver::load($this->pdo, $modelId, $this->liveVersion()));

        foreach ($expected as $state => $isFilterableNow) {
            $fieldId = $ids[$state];

            $pdo->statements = [];
            self::assertSame($isFilterableNow, $shared->supportsFilterOn($fieldId), "{$state}, from the database");
            self::assertSame($isFilterableNow, $shared->supportsSortOn($fieldId), "{$state}, sort from the database");
            self::assertCount(2, $pdo->statements, "{$state}: the shared driver asks the database each time");

            $pdo->statements = [];
            self::assertSame($isFilterableNow, $copy->supportsFilterOn($fieldId), "{$state}, from the snapshot");
            self::assertSame($isFilterableNow, $copy->supportsSortOn($fieldId), "{$state}, sort from the snapshot");

            // A field the snapshot does not carry is the one case that still
            // asks the database. A field being deleted is left out of it.
            self::assertCount(
                $state === 'being deleted' ? 2 : 0,
                $pdo->statements,
                "{$state}: statements the copy sent",
            );
        }

        self::assertFalse($copy->supportsFilterOn(999_999), 'An unknown field id is not filterable.');
    }

    /**
     * The copy carries one request's snapshot and the shared driver carries
     * none, so a caller outside the engine that holds the shared driver still
     * gets the database's answer at the moment it asks.
     */
    public function testTheSharedDriverNeverAnswersFromACache(): void
    {
        [$modelId] = $this->setupFilterableStringField();
        $fieldId = (int) $this->pdo->query('SELECT id FROM stardust_fields WHERE name = \'name\'')->fetchColumn();

        $pdo = $this->recordingPdo();
        $shared = new MysqlNativeDriver($pdo, new NullLogger(), new SchemaVersionCache($pdo, new NullLogger()));
        $shared->list(new SearchRequest(tenantId: 1, modelId: $modelId));
        self::assertTrue($shared->supportsFilterOn($fieldId));

        // No version bump and no read in between: only the database knows.
        $this->setSlotStatus($fieldId, 'backfilling');

        self::assertFalse($shared->supportsFilterOn($fieldId));
        self::assertFalse($shared->supportsSortOn($fieldId));
    }

    public function testAFieldThatStoppedBeingIndexedIsRejectedOnTheVeryNextRead(): void
    {
        [$modelId, $fieldId, , $fieldName] = $this->setupFilterableStringField();
        $this->seedEntry(1, $modelId, [$fieldName => 'a']);
        $engine = new StarDust(new Config(pdo: $this->pdo, logger: new NullLogger()));
        $filtered = new EntryQuery(tenantId: 1, modelId: $modelId, filter: LeafNode::local($fieldName, 'eq', 'a'));
        $sorted = new EntryQuery(tenantId: 1, modelId: $modelId, sort: SortSpec::byField($fieldName));

        self::assertCount(1, $engine->read($filtered)->rows);
        self::assertCount(1, $engine->read($sorted)->rows);

        // What an in-flight retype does: the slot leaves the indexed states
        // and the registry transaction bumps the schema version.
        $this->setSlotStatus($fieldId, 'backfilling');
        $this->bumpSchemaVersion();

        try {
            $engine->read($filtered);
            self::fail('A filter on a field that is no longer indexed must be rejected.');
        } catch (FieldNotFilterableException) {
            // expected
        }
        try {
            $engine->read($sorted);
            self::fail('A sort on a field that is no longer indexed must be rejected.');
        } catch (FieldNotSortableException) {
            // expected
        }

        // And back, once the backfill is done.
        $this->setSlotStatus($fieldId, 'ready');
        $this->bumpSchemaVersion();

        self::assertCount(1, $engine->read($filtered)->rows);
        self::assertCount(1, $engine->read($sorted)->rows);
    }

    /**
     * A service and a driver built with caches of their own, as the test
     * helpers do and as a consumer who injects a driver does. The driver's
     * cache is warm and goes stale, and the answer must still follow the
     * registry, because the service checks the version and hands the driver
     * the snapshot it got.
     */
    public function testAServiceAndADriverWithSeparateCachesStillGiveCurrentAnswers(): void
    {
        [$modelId, $fieldId, , $fieldName] = $this->setupFilterableStringField();
        $this->seedEntry(1, $modelId, [$fieldName => 'a']);

        $driver = $this->makeMysqlDriver();
        $service = $this->makeSearchService($driver);
        $request = SearchRequest::fromEntryQuery(
            new EntryQuery(tenantId: 1, modelId: $modelId, filter: LeafNode::local($fieldName, 'eq', 'a')),
        );

        // Warms the driver's own cache at the current version.
        $driver->list(new SearchRequest(tenantId: 1, modelId: $modelId));
        self::assertCount(1, $service->execute($request)->rows);

        $this->setSlotStatus($fieldId, 'backfilling');
        $this->bumpSchemaVersion();

        $this->expectException(FieldNotFilterableException::class);
        $service->execute($request);
    }

    public function testAServiceAndADriverWithSeparateCachesAcceptAFieldThatBecameIndexed(): void
    {
        [$modelId, $fieldId, , $fieldName] = $this->setupFilterableStringField();
        $this->seedEntry(1, $modelId, [$fieldName => 'a']);
        $this->setSlotStatus($fieldId, 'backfilling');
        $this->bumpSchemaVersion();

        $driver = $this->makeMysqlDriver();
        $service = $this->makeSearchService($driver);
        $request = SearchRequest::fromEntryQuery(
            new EntryQuery(tenantId: 1, modelId: $modelId, filter: LeafNode::local($fieldName, 'eq', 'a')),
        );

        $driver->list(new SearchRequest(tenantId: 1, modelId: $modelId));
        try {
            $service->execute($request);
            self::fail('The field is backfilling, so the filter must be rejected.');
        } catch (FieldNotFilterableException) {
            // expected
        }

        $this->setSlotStatus($fieldId, 'ready');
        $this->bumpSchemaVersion();

        self::assertCount(1, $service->execute($request)->rows);
    }
}
