<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use StarDust\Clock\SystemClock;
use StarDust\Page\PageProvisioner;
use StarDust\Slot\SlotReserver;
use StarDust\Support\ServerEngine;
use StarDust\Support\ServerEngineDetector;
use StarDust\Support\StringKeyLayout;
use StarDust\Tests\Smoke\Support\LegacyPage;
use StarDust\Tests\Smoke\Support\PrefixIndexPage;
use StarDust\Tests\Smoke\Support\SchemaFixture;
use StarDust\Write\BulkIngestor;
use StarDust\Write\EntryWriter;
use StarDust\Write\SlotRowUpserter;
use StarDust\Tests\Smoke\Support\TestPdo;

/**
 * Shared scaffolding for Phase 3 write-path smoke tests.
 *
 * Provides the env-gated MySQL connection, the per-test schema reset,
 * and a handful of registry-seed helpers (model + field + reserved
 * slot) so individual tests can focus on their behavioural assertions.
 *
 * There is deliberately no `tearDown()` sweep: {@see SchemaFixture} is
 * what guarantees a clean slate, and it runs in `setUp()`. Leaving the
 * last test's rows in place costs nothing and makes a failure easier
 * to inspect afterwards.
 */
abstract class WritePathTestCase extends TestCase
{
    protected PDO $pdo;
    protected ServerEngine $engine;

    protected function setUp(): void
    {
        $dsn  = getenv('STARDUST_TEST_DSN') ?: '';
        $user = getenv('STARDUST_TEST_USER') ?: '';
        $pass = getenv('STARDUST_TEST_PASS') ?: '';

        if ($dsn === '' || $user === '') {
            self::markTestSkipped('STARDUST_TEST_DSN and STARDUST_TEST_USER must be set for smoke tests.');
        }

        try {
            $this->pdo = new PDO($dsn, $user, $pass, TestPdo::options());
        } catch (PDOException $e) {
            self::fail('Could not connect to test database: ' . $e->getMessage());
        }

        $this->engine = ServerEngineDetector::detect($this->pdo);
        SchemaFixture::reset($this->pdo);
    }

    /**
     * A second PDO session against the same test database, for tests
     * that need two real connections (SKIP LOCKED / GET_LOCK
     * contention proofs). Lifted out of
     * `Chronicler\ChroniclerMultiWorkerClaimTest`, which was the only
     * caller until the ADR 0049 Liberator multi-worker tests needed the
     * identical helper — shared here rather than duplicated a second
     * time.
     */
    protected function makeSiblingPdo(): PDO
    {
        $dsn  = getenv('STARDUST_TEST_DSN') ?: '';
        $user = getenv('STARDUST_TEST_USER') ?: '';
        $pass = getenv('STARDUST_TEST_PASS') ?: '';

        if ($dsn === '' || $user === '') {
            self::markTestSkipped('STARDUST_TEST_DSN/STARDUST_TEST_USER must be set.');
        }

        return new PDO($dsn, $user, $pass, TestPdo::options());
    }

    /**
     * Provision a page indexing exactly the named slot columns.
     */
    protected function provisionPage(array $filterableSlots = []): int
    {
        return (new PageProvisioner(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: new NullLogger(),
            engine: $this->engine,
            provisionerIdentity: 'phpunit/0',
        ))->provision($filterableSlots);
    }

    /**
     * Provision a pre-ADR-0043 page: all sixty slot columns, none of
     * them indexed, sixty `free` inventory rows.
     *
     * Use it for any fixture whose subject is a slot the reserver takes
     * without `requireIndexed`, or that needs free inventory the
     * production path would never hand out. {@see LegacyPage} explains
     * why the shape has to stay reachable.
     */
    protected function provisionLegacyPage(): int
    {
        return LegacyPage::provision($this->pdo, $this->engine, 'phpunit/0');
    }

    /**
     * Provision a prefix-index page: exactly the named columns, with a
     * string slot indexed on its first 766 characters.
     *
     * `provisionPage()` builds whatever shape the provisioner builds today.
     * Use this one when the subject is the prefix shape itself, or a page
     * from before string slots moved to a generated key.
     * {@see PrefixIndexPage} explains why the shape has to stay reachable.
     *
     * @param list<string> $filterableSlots
     */
    protected function provisionPrefixPage(array $filterableSlots): int
    {
        return PrefixIndexPage::provision($this->pdo, $this->engine, $filterableSlots, 'phpunit/0');
    }

    /**
     * The entries a key page finds for `$value` in a string slot through the
     * slot's generated key alone, with no recheck on the slot itself.
     *
     * The collation and the head width are read from the page the way the
     * compiler reads them, so this is the lookup the key exists to serve. It
     * is how a test proves the key, which the server fills on its own, holds
     * what the slot says it should.
     *
     * @return list<int>
     */
    protected function entryIdsFoundByKey(int $pageId, string $slotColumn, string $value): array
    {
        $table = "entry_slots_page_{$pageId}";
        $keyColumn = StringKeyLayout::keyColumn($slotColumn);

        $info = $this->pdo->prepare(
            'SELECT COLUMN_NAME, COLLATION_NAME, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN (?, ?)'
        );
        $info->execute([$table, $slotColumn, $keyColumn]);

        $collation = null;
        $head = null;
        foreach ($info->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row = array_change_key_case($row, CASE_LOWER);
            if ($row['column_name'] === $slotColumn) {
                $collation = (string) $row['collation_name'];
            } else {
                $head = StringKeyLayout::headBytes((int) $row['character_maximum_length']);
            }
        }
        self::assertNotNull($collation, "{$table}.{$slotColumn} must exist.");
        self::assertNotNull($head, "{$table}.{$keyColumn} must exist.");

        $lookup = StringKeyLayout::equals($keyColumn, $value, $head, $collation);
        $stmt = $this->pdo->prepare("SELECT entry_id FROM {$table} WHERE {$lookup['sql']} ORDER BY entry_id");
        $stmt->execute($lookup['bindings']);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Insert a model row and return its id.
     */
    protected function createModel(int $tenantId = 1, ?string $name = null): int
    {
        $name ??= 'model_' . bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare(
            'INSERT INTO stardust_models (tenant_id, name, created_at)'
            . ' VALUES (?, ?, UTC_TIMESTAMP())'
        );
        $stmt->execute([$tenantId, $name]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Insert a field row and return its id.
     */
    protected function createField(
        int $modelId,
        string $declaredType = 'string',
        bool $isFilterable = false,
        ?string $name = null,
    ): int {
        $name ??= 'field_' . bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare(
            'INSERT INTO stardust_fields'
            . ' (model_id, name, declared_type, is_filterable, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $stmt->execute([$modelId, $name, $declaredType, $isFilterable ? 1 : 0]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Reserve a slot for a field (calls Phase 2 SlotReserver).
     *
     * The field must be filterable — SlotReserver rejects a
     * non-filterable one per ADR 0034. Use
     * {@see self::forceGrandfatheredSlotFor()} to construct the legacy
     * shape instead.
     */
    protected function reserveSlotFor(int $fieldId): void
    {
        (new SlotReserver(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: new NullLogger(),
        ))->reserve($fieldId);
    }

    /**
     * Assign a slot by direct registry UPDATE, bypassing SlotReserver.
     *
     * The only way to construct a pre-ADR-0034 *grandfathered* slot —
     * a live slot held by a non-filterable field — now that the
     * reserver rejects them. Runs the same three statements
     * `reserveCore()` does (claim the oldest free slot of the family,
     * flip it to `assigned`, bump the schema version), minus the guard.
     */
    protected function forceGrandfatheredSlotFor(int $fieldId, string $slotType = 'str'): void
    {
        $select = $this->pdo->prepare(
            'SELECT id FROM stardust_slot_assignments'
            . " WHERE status = 'free' AND slot_type = ?"
            . ' ORDER BY page_id, id LIMIT 1'
        );
        $select->execute([$slotType]);
        $assignmentId = $select->fetchColumn();

        if ($assignmentId === false) {
            $this->fail("No free {$slotType} slot available to grandfather onto field {$fieldId}.");
        }

        $update = $this->pdo->prepare(
            'UPDATE stardust_slot_assignments'
            . " SET status = 'assigned', field_id = ?, updated_at = UTC_TIMESTAMP()"
            . ' WHERE id = ?'
        );
        $update->execute([$fieldId, $assignmentId]);

        $this->pdo->exec(
            'UPDATE stardust_schema_version'
            . ' SET version = version + 1, updated_at = UTC_TIMESTAMP()'
            . ' WHERE id = 1'
        );
    }

    /**
     * Model with one filterable field holding a live (unindexed) slot.
     *
     * The field is filterable because a reserved slot implies
     * filterability under ADR 0034 — only filterable fields may hold
     * one. The page itself carries no composite index, so this is the
     * "filterable but not indexed" fixture; see
     * {@see ReadPathTestCase::setupFilterableStringField()} for the
     * indexed twin.
     *
     * @return array{0: int, 1: int, 2: int, 3: string} [modelId, fieldId, pageId, fieldName]
     */
    /**
     * The real write path, bound to the test PDO.
     *
     * `EntryWriterTest` and `BulkIngestorTest` each build their own; this
     * exists because the correlation tests need both, and duplicating the
     * construction a third and fourth time is how the two drift.
     */
    protected function makeEntryWriter(?\Psr\Log\LoggerInterface $logger = null): EntryWriter
    {
        return new EntryWriter(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: $logger ?? new NullLogger(),
            slotRowUpserter: new SlotRowUpserter($this->pdo),
        );
    }

    protected function makeBulkIngestor(?\Psr\Log\LoggerInterface $logger = null): BulkIngestor
    {
        $log = $logger ?? new NullLogger();

        return new BulkIngestor(
            pdo: $this->pdo,
            entryWriter: $this->makeEntryWriter($log),
            logger: $log,
            // No real wall-clock delay in tests; the inter-chunk pacing
            // is BulkIngestorTest's subject, not this helper's.
            sleepFn: static function (int $micros): void {
            },
        );
    }

    protected function setupModelWithReservedField(
        int $tenantId = 1,
        string $declaredType = 'string',
    ): array {
        $pageId = $this->provisionLegacyPage();
        $modelId = $this->createModel($tenantId);
        $fieldName = 'field_' . bin2hex(random_bytes(4));
        $fieldId = $this->createField($modelId, $declaredType, true, $fieldName);
        $this->reserveSlotFor($fieldId);
        return [$modelId, $fieldId, $pageId, $fieldName];
    }
}
