<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Read;

use PDO;
use StarDust\Read\SlotResolver;
use StarDust\Read\SnapshotEntry;
use StarDust\Support\ServerEngine;
use StarDust\Tests\Smoke\Support\StatementRecordingPdo;
use StarDust\Tests\Smoke\Support\TestPdo;
use StarDust\Tests\Smoke\WritePathTestCase;

/**
 * Which string slots the schema snapshot records a key for, against real DDL.
 *
 * The compiler builds a key lookup from what is recorded here and from nothing
 * else, so the answer has to follow the page's own table definition: a key page
 * resolves, and a prefix page, a page from before pages carried only their
 * indexed columns and a key whose index the optimiser skips all resolve nothing
 * and keep the SQL they had before keys. What the dictionary has to show before
 * a key is trusted is `StringKeyTest`'s subject, because a hostile collation
 * name cannot be built from real DDL.
 */
final class StringKeyResolutionTest extends WritePathTestCase
{
    private function expectedCollation(): string
    {
        return match ($this->engine) {
            ServerEngine::MYSQL   => 'utf8mb4_0900_ai_ci',
            ServerEngine::MARIADB => 'utf8mb4_unicode_520_nopad_ci',
        };
    }

    /**
     * A model with one filterable string field per name, each reserved in turn.
     *
     * @param list<string> $fieldNames
     */
    private function modelWithStringFields(array $fieldNames): int
    {
        $modelId = $this->createModel();
        foreach ($fieldNames as $name) {
            $this->reserveSlotFor($this->createField($modelId, 'string', true, $name));
        }

        return $modelId;
    }

    private function load(int $modelId, ?PDO $pdo = null): SnapshotEntry
    {
        return SlotResolver::load($pdo ?? $this->pdo, $modelId, 1);
    }

    public function testAKeyPageResolvesEachStringSlotsKeyHeadAndCollation(): void
    {
        $pageId = $this->provisionPage(['i_str_01', 'i_str_02']);
        $modelId = $this->modelWithStringFields(['a', 'b']);
        $table = "entry_slots_page_{$pageId}";

        $snapshot = $this->load($modelId);

        foreach (['i_str_01', 'i_str_02'] as $slot) {
            $key = $snapshot->stringKeyFor($pageId, $slot);
            self::assertNotNull($key, $slot);
            self::assertSame("{$slot}_k", $key->keyColumn);
            self::assertSame("ix_{$table}_{$slot}", $key->indexName);
            self::assertSame(32, $key->headBytes);
            self::assertSame($this->expectedCollation(), $key->collation);
        }
    }

    public function testAPrefixPageResolvesNoKey(): void
    {
        $pageId = $this->provisionPrefixPage(['i_str_01']);
        $modelId = $this->modelWithStringFields(['a']);

        $snapshot = $this->load($modelId);

        self::assertNull($snapshot->stringKeyFor($pageId, 'i_str_01'));
        self::assertSame([], $snapshot->stringKeyMap());
    }

    public function testALegacyPageResolvesNoKey(): void
    {
        $pageId = $this->provisionLegacyPage();
        $modelId = $this->createModel();
        $this->forceGrandfatheredSlotFor($this->createField($modelId, 'string', false, 'a'));

        self::assertSame([], $this->load($modelId)->stringKeyMap());
        self::assertNotNull($pageId);
    }

    public function testAKeyIndexTheOptimiserSkipsResolvesNoKey(): void
    {
        $pageId = $this->provisionPage(['i_str_01']);
        $modelId = $this->modelWithStringFields(['a']);
        $table = "entry_slots_page_{$pageId}";
        // A hint naming such an index fails the read, so the key must not resolve.
        $this->pdo->exec(
            $this->engine === ServerEngine::MARIADB
                ? "ALTER TABLE {$table} ALTER INDEX ix_{$table}_i_str_01 IGNORED"
                : "ALTER TABLE {$table} ALTER INDEX ix_{$table}_i_str_01 INVISIBLE"
        );

        self::assertNull($this->load($modelId)->stringKeyFor($pageId, 'i_str_01'));
    }

    public function testAKeyColumnThatIsNotVarbinaryIsNotTrusted(): void
    {
        $pageId = $this->provisionPage(['i_str_01']);
        $modelId = $this->modelWithStringFields(['a']);
        $table = "entry_slots_page_{$pageId}";
        // An operator re-declaring the column as something the engine did not
        // build leaves the index in place and the key unusable.
        $this->pdo->exec("ALTER TABLE {$table} DROP INDEX ix_{$table}_i_str_01");
        $this->pdo->exec("ALTER TABLE {$table} DROP COLUMN i_str_01_k");
        $this->pdo->exec("ALTER TABLE {$table} ADD COLUMN i_str_01_k VARCHAR(48) NULL");
        $this->pdo->exec("ALTER TABLE {$table} ADD INDEX ix_{$table}_i_str_01 (tenant_id, i_str_01_k)");

        self::assertNull($this->load($modelId)->stringKeyFor($pageId, 'i_str_01'));
    }

    public function testAModelSpanningBothShapesResolvesKeysOnlyWhereThePageHasThem(): void
    {
        $prefixPage = $this->provisionPrefixPage(['i_str_01']);
        $modelId = $this->createModel();
        $this->reserveSlotFor($this->createField($modelId, 'string', true, 'a'));
        $keyPage = $this->provisionPage(['i_str_01']);
        $this->reserveSlotFor($this->createField($modelId, 'string', true, 'b'));

        $snapshot = $this->load($modelId);

        self::assertNull($snapshot->stringKeyFor($prefixPage, 'i_str_01'));
        self::assertNotNull($snapshot->stringKeyFor($keyPage, 'i_str_01'));
        self::assertSame([$keyPage], array_keys($snapshot->stringKeyMap()));
    }

    /**
     * The extra dictionary query is paid for a model on key pages and by nobody
     * else. A warm read pays nothing, because the snapshot is cached.
     */
    public function testOnlyAModelOnKeyPagesPaysForTheExtraDictionaryQuery(): void
    {
        $this->provisionPrefixPage(['i_str_01']);
        $prefixModel = $this->modelWithStringFields(['a']);
        $this->provisionPage(['i_str_01']);
        $keyModel = $this->modelWithStringFields(['b']);

        $pdo = new StatementRecordingPdo(
            getenv('STARDUST_TEST_DSN') ?: '',
            getenv('STARDUST_TEST_USER') ?: '',
            getenv('STARDUST_TEST_PASS') ?: '',
            TestPdo::options(),
        );

        $this->load($prefixModel, $pdo);
        self::assertSame(0, $pdo->countPreparedContaining('information_schema.COLUMNS'));

        $this->load($keyModel, $pdo);
        self::assertSame(1, $pdo->countPreparedContaining('information_schema.COLUMNS'));
    }
}
