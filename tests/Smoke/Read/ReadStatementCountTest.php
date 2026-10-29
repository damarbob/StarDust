<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Read;

use Psr\Log\NullLogger;
use StarDust\Config\Config;
use StarDust\Filter\Ast\AndNode;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Read\CursorCodec;
use StarDust\Read\EntryQuery;
use StarDust\Read\SortSpec;
use StarDust\StarDust;
use StarDust\Tests\Smoke\ReadPathTestCase;
use StarDust\Tests\Smoke\Support\StatementRecordingPdo;
use StarDust\Tests\Smoke\Support\TestPdo;

/**
 * Which statements a read sends to the server, in order.
 *
 * Every case builds a `StarDust` on a recording connection, the same object a
 * consumer holds, so the schema-version cache is shared between the service and
 * the driver exactly as it is in production. A statement is named by what it
 * asks for, and the lists are exact so that a change to what a read costs shows
 * up as a change to a list.
 *
 * A warm case reads once to fill the cache and counts the second read. A cold
 * case counts the first read in the process, which also loads the snapshot.
 *
 * - `version` is the schema-version check every read makes.
 * - `filterability` asks whether a field is filterable right now.
 * - `snapshot ...` is one statement of a snapshot load.
 * - `probe` and `fetch` are the two bounded-read queries.
 * - `entry` is the point read's own query.
 *
 * The statements sent once per `StarDust`, when the server is first detected,
 * are not part of a read's cost and are left out.
 */
final class ReadStatementCountTest extends ReadPathTestCase
{
    /**
     * @return array{0: StarDust, 1: StatementRecordingPdo}
     */
    private function engine(): array
    {
        $pdo = new StatementRecordingPdo(
            getenv('STARDUST_TEST_DSN') ?: '',
            getenv('STARDUST_TEST_USER') ?: '',
            getenv('STARDUST_TEST_PASS') ?: '',
            TestPdo::options(),
        );
        $engine = new StarDust(new Config(pdo: $pdo, logger: new NullLogger()));
        $engine->serverEngine();
        $pdo->statements = [];

        return [$engine, $pdo];
    }

    private function label(string $sql): string
    {
        return match (true) {
            str_starts_with($sql, 'SET SESSION max_sort_length') => 'driver setup',
            $sql === 'SELECT version FROM stardust_schema_version WHERE id = 1' => 'version',
            str_contains($sql, 'SELECT f.is_filterable AS is_filterable') => 'filterability',
            str_contains($sql, 'FROM stardust_models m') => 'snapshot registry',
            str_contains($sql, 'FROM stardust_pages') => 'snapshot pages',
            str_contains($sql, 'information_schema.STATISTICS') => 'snapshot indexes',
            str_contains($sql, 'information_schema.COLUMNS') => 'snapshot string keys',
            str_contains($sql, 'WHERE entry_data.id IN') => 'fetch',
            str_starts_with($sql, 'SELECT entry_data.id'),
            str_starts_with($sql, 'SELECT STRAIGHT_JOIN entry_data.id') => 'probe',
            str_contains($sql, 'FROM entry_data WHERE id = ?') => 'entry',
            default => 'other: ' . $sql,
        };
    }

    /**
     * What was sent since the last call, by name. Clears the recording.
     *
     * @return list<string>
     */
    private function sent(StatementRecordingPdo $pdo): array
    {
        $labels = array_map($this->label(...), $pdo->statements);
        $pdo->statements = [];

        return array_values(array_filter($labels, static fn (string $label): bool => $label !== 'driver setup'));
    }

    /**
     * Runs `$call` once to fill the cache, then again, and names what the
     * second run sent.
     *
     * @param callable(StarDust): mixed $call
     * @return list<string>
     */
    private function warm(StarDust $engine, StatementRecordingPdo $pdo, callable $call): array
    {
        $call($engine);
        $this->sent($pdo);
        $call($engine);

        return $this->sent($pdo);
    }

    /**
     * A model with a filterable field of each slot family on one key page, and
     * three entries.
     *
     * @return array{0: int, 1: list<int>}
     */
    private function sortableModelWithEntries(): array
    {
        [$modelId] = $this->setupSortableModel();
        $ids = [];
        foreach ([['a', 1, 1.5], ['b', 2, 2.5], ['c', 3, 3.5]] as [$title, $rank, $price]) {
            $ids[] = $this->seedEntry(1, $modelId, ['title' => $title, 'rank' => $rank, 'price' => $price]);
        }

        return [$modelId, $ids];
    }

    public function testWarmReadWithOneFilterLeaf(): void
    {
        [$modelId] = $this->sortableModelWithEntries();
        [$engine, $pdo] = $this->engine();
        $query = new EntryQuery(tenantId: 1, modelId: $modelId, filter: LeafNode::local('title', 'eq', 'a'));

        $sent = $this->warm($engine, $pdo, static fn (StarDust $e) => $e->read($query));

        self::assertSame(['version', 'filterability', 'version', 'probe', 'fetch'], $sent);
    }

    public function testWarmReadWithThreeFilterLeaves(): void
    {
        [$modelId] = $this->sortableModelWithEntries();
        [$engine, $pdo] = $this->engine();
        $query = new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
            filter: new AndNode([
                LeafNode::local('title', 'eq', 'a'),
                LeafNode::local('rank', 'gte', 1),
                LeafNode::local('price', 'lte', 9.5),
            ]),
        );

        $sent = $this->warm($engine, $pdo, static fn (StarDust $e) => $e->read($query));

        self::assertSame(
            ['version', 'filterability', 'filterability', 'filterability', 'version', 'probe', 'fetch'],
            $sent,
        );
    }

    public function testWarmReadSortedByAFieldWithNoFilter(): void
    {
        [$modelId] = $this->sortableModelWithEntries();
        [$engine, $pdo] = $this->engine();
        $query = new EntryQuery(tenantId: 1, modelId: $modelId, sort: SortSpec::byField('rank'));

        $sent = $this->warm($engine, $pdo, static fn (StarDust $e) => $e->read($query));

        self::assertSame(['version', 'filterability', 'version', 'probe', 'fetch'], $sent);
    }

    public function testWarmReadWithACursorOnly(): void
    {
        [$modelId, $ids] = $this->sortableModelWithEntries();
        [$engine, $pdo] = $this->engine();
        $query = new EntryQuery(tenantId: 1, modelId: $modelId, cursor: CursorCodec::encode($ids[0]));

        $sent = $this->warm($engine, $pdo, static fn (StarDust $e) => $e->read($query));

        self::assertSame(['version', 'version', 'probe', 'fetch'], $sent);
    }

    public function testWarmReadWithNoFilterSortOrCursorSkipsPreFlight(): void
    {
        [$modelId] = $this->sortableModelWithEntries();
        [$engine, $pdo] = $this->engine();
        $query = new EntryQuery(tenantId: 1, modelId: $modelId);

        $sent = $this->warm($engine, $pdo, static fn (StarDust $e) => $e->read($query));

        self::assertSame(['version', 'probe', 'fetch'], $sent);
    }

    /**
     * An empty probe has no ids to fetch, so the second query is not sent.
     */
    public function testWarmFilteredReadThatMatchesNothingSkipsTheFetch(): void
    {
        [$modelId] = $this->sortableModelWithEntries();
        [$engine, $pdo] = $this->engine();
        $query = new EntryQuery(tenantId: 1, modelId: $modelId, filter: LeafNode::local('title', 'eq', 'nothing'));

        $sent = $this->warm($engine, $pdo, static fn (StarDust $e) => $e->read($query));

        self::assertSame(['version', 'filterability', 'version', 'probe'], $sent);
    }

    public function testWarmPointRead(): void
    {
        [, $ids] = $this->sortableModelWithEntries();
        [$engine, $pdo] = $this->engine();

        $sent = $this->warm($engine, $pdo, static fn (StarDust $e) => $e->get(1, $ids[0]));

        self::assertSame(['entry', 'version'], $sent);
    }

    public function testPointReadOfAMissingEntryIsOneStatement(): void
    {
        $this->sortableModelWithEntries();
        [$engine, $pdo] = $this->engine();

        $sent = $this->warm($engine, $pdo, static fn (StarDust $e) => $e->get(1, 999_999));

        self::assertSame(['entry'], $sent);
    }

    public function testColdReadOnAPrefixPageLoadsThreeSnapshotStatements(): void
    {
        $this->provisionPrefixPage(['i_str_01']);
        $modelId = $this->createModel(1);
        $this->reserveSlotFor($this->createField($modelId, 'string', true, 'name'));
        $this->seedEntry(1, $modelId, ['name' => 'a']);
        [$engine, $pdo] = $this->engine();

        $engine->read(new EntryQuery(tenantId: 1, modelId: $modelId, filter: LeafNode::local('name', 'eq', 'a')));

        self::assertSame(
            [
                'version',
                'snapshot registry', 'snapshot pages', 'snapshot indexes',
                'filterability', 'version', 'probe', 'fetch',
            ],
            $this->sent($pdo),
        );
    }

    public function testColdReadOnAKeyPageLoadsFourSnapshotStatements(): void
    {
        [$modelId] = $this->setupFilterableStringField();
        $this->seedEntry(1, $modelId, ['name' => 'a']);
        [$engine, $pdo] = $this->engine();

        $engine->read(new EntryQuery(tenantId: 1, modelId: $modelId, filter: LeafNode::local('name', 'eq', 'a')));

        self::assertSame(
            [
                'version',
                'snapshot registry', 'snapshot pages', 'snapshot indexes', 'snapshot string keys',
                'filterability', 'version', 'probe', 'fetch',
            ],
            $this->sent($pdo),
        );
    }

    public function testColdPointReadOnAKeyPageLoadsTheSnapshot(): void
    {
        [$modelId] = $this->setupFilterableStringField();
        $entryId = $this->seedEntry(1, $modelId, ['name' => 'a']);
        [$engine, $pdo] = $this->engine();

        $engine->get(1, $entryId);

        self::assertSame(
            [
                'entry', 'version',
                'snapshot registry', 'snapshot pages', 'snapshot indexes', 'snapshot string keys',
            ],
            $this->sent($pdo),
        );
    }
}
