<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Read;

use PDO;
use PDOException;
use PDOStatement;
use StarDust\Read\SlotResolver;
use StarDust\Tests\Smoke\ReadPathTestCase;
use StarDust\Tests\Smoke\Support\StatementRecordingPdo;
use StarDust\Tests\Smoke\Support\TestPdo;

/**
 * A schema snapshot reads the data dictionary only when a read asks for an
 * index or a key, and reads each map once. `ReadStatementCountTest` pins what
 * whole reads send. This class pins the snapshot itself, so a lookup that
 * creeps back into the load shows up here by name.
 *
 * Every case counts statements that mention `information_schema`, which is
 * where both lookups go.
 */
final class LazyDictionaryTest extends ReadPathTestCase
{
    private function recording(): StatementRecordingPdo
    {
        return new StatementRecordingPdo(
            getenv('STARDUST_TEST_DSN') ?: '',
            getenv('STARDUST_TEST_USER') ?: '',
            getenv('STARDUST_TEST_PASS') ?: '',
            TestPdo::options(),
        );
    }

    /** How many dictionary queries were sent since the last call. Clears the recording. */
    private function dictionaryQueries(StatementRecordingPdo $pdo): int
    {
        $count = count(array_filter(
            $pdo->statements,
            static fn (string $sql): bool => str_contains($sql, 'information_schema'),
        ));
        $pdo->statements = [];

        return $count;
    }

    public function testLoadingASnapshotSendsNoDictionaryQuery(): void
    {
        [$modelId] = $this->setupFilterableStringField();
        $pdo = $this->recording();

        SlotResolver::load($pdo, $modelId, 1);

        self::assertSame(0, $this->dictionaryQueries($pdo));
    }

    public function testAColumnThatIsNotAStringSlotNeverReadsTheDictionary(): void
    {
        [$modelId, , $pageId] = $this->setupFilterableStringField();
        $pdo = $this->recording();
        $snapshot = SlotResolver::load($pdo, $modelId, 1);

        self::assertNull($snapshot->stringKeyFor($pageId, 'i_int_01'));
        self::assertNull($snapshot->stringKeyFor($pageId, 'i_dt_01'));
        self::assertNull($snapshot->stringKeyFor($pageId, 'i_str_01_k'));
        self::assertSame(0, $this->dictionaryQueries($pdo));
    }

    public function testTheIndexMapIsReadOnTheFirstQuestionAndKept(): void
    {
        [$modelId, , $pageId] = $this->setupFilterableStringField();
        $pdo = $this->recording();
        $snapshot = SlotResolver::load($pdo, $modelId, 1);

        self::assertNotNull($snapshot->orderedIndexFor($pageId, 'i_str_01_k'));
        self::assertSame(1, $this->dictionaryQueries($pdo));

        $snapshot->orderedIndexFor($pageId, 'i_str_01_k');
        $snapshot->orderedIndexMap();
        self::assertSame(0, $this->dictionaryQueries($pdo));
    }

    public function testAStringSlotOnAKeyPageReadsBothMapsOnce(): void
    {
        [$modelId, , $pageId] = $this->setupFilterableStringField();
        $pdo = $this->recording();
        $snapshot = SlotResolver::load($pdo, $modelId, 1);

        self::assertNotNull($snapshot->stringKeyFor($pageId, 'i_str_01'));
        self::assertSame(2, $this->dictionaryQueries($pdo));

        $snapshot->stringKeyFor($pageId, 'i_str_01');
        $snapshot->stringKeyMap();
        $snapshot->orderedIndexFor($pageId, 'i_str_01_k');
        self::assertSame(0, $this->dictionaryQueries($pdo));
    }

    public function testAStringSlotOnAPrefixPageReadsTheIndexMapOnly(): void
    {
        $pageId = $this->provisionPrefixPage(['i_str_01']);
        $modelId = $this->createModel(1);
        $this->reserveSlotFor($this->createField($modelId, 'string', true, 'name'));
        $pdo = $this->recording();
        $snapshot = SlotResolver::load($pdo, $modelId, 1);

        self::assertNull($snapshot->stringKeyFor($pageId, 'i_str_01'));
        self::assertSame(1, $this->dictionaryQueries($pdo));
    }

    public function testAModelWithNoPagesNeverReadsTheDictionary(): void
    {
        $modelId = $this->createModel(1);
        $this->createField($modelId, 'string', false, 'note');
        $pdo = $this->recording();
        $snapshot = SlotResolver::load($pdo, $modelId, 1);

        self::assertNull($snapshot->orderedIndexFor(1, 'i_int_01'));
        self::assertNull($snapshot->stringKeyFor(1, 'i_str_01'));
        self::assertSame([], $snapshot->orderedIndexMap());
        self::assertSame([], $snapshot->stringKeyMap());
        self::assertSame(0, $this->dictionaryQueries($pdo));
    }

    public function testAFailedDictionaryReadIsNotKeptAsAnEmptyMap(): void
    {
        [$modelId, , $pageId] = $this->setupFilterableStringField();
        $failsOnce = new class (
            getenv('STARDUST_TEST_DSN') ?: '',
            getenv('STARDUST_TEST_USER') ?: '',
            getenv('STARDUST_TEST_PASS') ?: '',
            TestPdo::options(),
        ) extends PDO {
            private bool $armed = true;

            /**
             * @param array<mixed> $options
             */
            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                if ($this->armed && str_contains($query, 'information_schema.STATISTICS')) {
                    $this->armed = false;
                    throw new PDOException('dictionary unavailable');
                }

                return parent::prepare($query, $options);
            }
        };
        $snapshot = SlotResolver::load($failsOnce, $modelId, 1);

        try {
            $snapshot->orderedIndexFor($pageId, 'i_str_01_k');
            self::fail('The first question should have thrown.');
        } catch (PDOException $e) {
            self::assertSame('dictionary unavailable', $e->getMessage());
        }

        // An empty map kept from the failure would say "no such index" for ever.
        self::assertNotNull($snapshot->orderedIndexFor($pageId, 'i_str_01_k'));
    }
}
