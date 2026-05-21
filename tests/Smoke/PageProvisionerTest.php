<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use StarDust\Bootstrap\Bootstrapper;
use StarDust\Clock\SystemClock;
use StarDust\Logging\StdoutNdjsonLogger;
use StarDust\Page\PageProvisioner;

/**
 * Phase 2 page provisioner smoke suite.
 *
 * Each test starts from a clean Phase 1 schema and verifies one Phase 2
 * exit criterion: index emission, slot inventory, atomicity of the
 * registry transaction, schema-version increment, monotonic page
 * numbering, and `page_provisioned` event emission.
 */
final class PageProvisionerTest extends TestCase
{
    private const PHASE_1_TABLES = [
        'stardust_slot_assignments',
        'stardust_pages',
        'stardust_fields',
        'stardust_models',
        'stardust_sync_queue',
        'entry_data',
        'stardust_schema_version',
        'stardust_export_jobs',
        'stardust_reconciler_dlq',
        'backfill_checkpoints',
    ];

    private PDO $pdo;

    protected function setUp(): void
    {
        $dsn  = getenv('STARDUST_TEST_DSN') ?: '';
        $user = getenv('STARDUST_TEST_USER') ?: '';
        $pass = getenv('STARDUST_TEST_PASS') ?: '';

        if ($dsn === '' || $user === '') {
            self::markTestSkipped('STARDUST_TEST_DSN and STARDUST_TEST_USER must be set for smoke tests.');
        }

        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            self::fail('Could not connect to test database: ' . $e->getMessage());
        }

        $this->dropEverything();
        (new Bootstrapper($this->pdo))->run();
    }

    protected function tearDown(): void
    {
        if (! isset($this->pdo)) {
            return;
        }
        try {
            $this->dropEverything();
        } catch (\Throwable) {
            // best-effort
        }
    }

    private function dropEverything(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        // Phase 2 page tables — discovered, not allowlisted.
        $pages = $this->pdo
            ->query(
                "SELECT table_name FROM information_schema.TABLES"
                . " WHERE table_schema = DATABASE() AND table_name LIKE 'entry_slots_page_%'"
            )
            ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($pages as $pageTable) {
            try {
                $this->pdo->exec("DROP TABLE IF EXISTS {$pageTable}");
            } catch (\Throwable) {
                // ignored
            }
        }

        foreach (self::PHASE_1_TABLES as $t) {
            try {
                $this->pdo->exec("DROP TABLE IF EXISTS {$t}");
            } catch (\Throwable) {
                // ignored
            }
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function newProvisioner(): PageProvisioner
    {
        return new PageProvisioner(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: new NullLogger(),
            provisionerIdentity: 'phpunit/0',
        );
    }

    /** Exit criterion: indexes emitted only for the named filterable slots. */
    public function testProvisionEmitsIndexesOnlyForFilterableSlots(): void
    {
        $pageId = $this->newProvisioner()->provision(['i_str_01', 'i_int_01', 'i_dt_05']);
        self::assertSame(1, $pageId);

        $rows = $this->pdo->query('SHOW INDEX FROM entry_slots_page_1')->fetchAll();
        $indexes = [];
        foreach ($rows as $r) {
            $name = (string) $r['Key_name'];
            $indexes[$name][(int) $r['Seq_in_index']] = (string) $r['Column_name'];
        }

        self::assertArrayHasKey('ix_entry_slots_page_1_i_str_01', $indexes);
        self::assertArrayHasKey('ix_entry_slots_page_1_i_int_01', $indexes);
        self::assertArrayHasKey('ix_entry_slots_page_1_i_dt_05', $indexes);

        ksort($indexes['ix_entry_slots_page_1_i_str_01']);
        self::assertSame(
            ['tenant_id', 'i_str_01'],
            array_values($indexes['ix_entry_slots_page_1_i_str_01']),
            'Filterable index must be composite (tenant_id, slot_column).'
        );

        foreach ($indexes as $name => $_) {
            if (str_starts_with($name, 'ix_entry_slots_page_1_i_')) {
                self::assertContains(
                    $name,
                    [
                        'ix_entry_slots_page_1_i_str_01',
                        'ix_entry_slots_page_1_i_int_01',
                        'ix_entry_slots_page_1_i_dt_05',
                    ],
                    "Unexpected per-slot index {$name} — non-filterable slots must remain unindexed.",
                );
            }
        }
    }

    /** Exit criterion: all 60 slot rows present, status='free', field_id IS NULL. */
    public function testProvisionInsertsFullSlotInventory(): void
    {
        $pageId = $this->newProvisioner()->provision();

        $rows = $this->pdo
            ->query("SELECT slot_type, status, field_id FROM stardust_slot_assignments WHERE page_id = {$pageId}")
            ->fetchAll();

        self::assertCount(60, $rows);

        $byType = ['str' => 0, 'int' => 0, 'num' => 0, 'dt' => 0];
        foreach ($rows as $r) {
            self::assertSame('free', (string) $r['status']);
            self::assertNull($r['field_id']);
            $byType[(string) $r['slot_type']]++;
        }
        self::assertSame(
            ['str' => 25, 'int' => 15, 'num' => 10, 'dt' => 10],
            $byType,
        );
    }

    /**
     * Exit criterion (§4.6 invariant #4): the page row and slot inventory
     * are present or absent together. Forces a UNIQUE violation on
     * `stardust_pages.table_name` to exercise the rollback branch.
     */
    public function testRegistryTransactionRollsBackOnFailure(): void
    {
        // Seed a row whose table_name will collide with the name our
        // provisioner is about to compute (MAX(id)+1 == 2 → entry_slots_page_2).
        $this->pdo->exec(
            "INSERT INTO stardust_pages (id, table_name, provisioned_at, provisioned_by)"
            . " VALUES (1, 'entry_slots_page_2', UTC_TIMESTAMP(), 'collision-fixture')"
        );

        $versionBefore = (int) $this->pdo
            ->query('SELECT version FROM stardust_schema_version WHERE id = 1')
            ->fetchColumn();

        $threw = false;
        try {
            $this->newProvisioner()->provision();
        } catch (PDOException) {
            $threw = true;
        }
        self::assertTrue($threw, 'Provision must surface the unique-key violation as PDOException.');

        // Only the pre-seeded row remains; no new id=2 row was committed.
        $count = (int) $this->pdo
            ->query("SELECT COUNT(*) FROM stardust_pages WHERE table_name = 'entry_slots_page_2'")
            ->fetchColumn();
        self::assertSame(1, $count);

        $idOfRemaining = (int) $this->pdo
            ->query("SELECT id FROM stardust_pages WHERE table_name = 'entry_slots_page_2'")
            ->fetchColumn();
        self::assertSame(1, $idOfRemaining, 'Pre-seeded row must be the only stardust_pages entry left.');

        $slotCount = (int) $this->pdo
            ->query("SELECT COUNT(*) FROM stardust_slot_assignments")
            ->fetchColumn();
        self::assertSame(0, $slotCount, 'No slot inventory may be committed when the page insert rolls back.');

        $versionAfter = (int) $this->pdo
            ->query('SELECT version FROM stardust_schema_version WHERE id = 1')
            ->fetchColumn();
        self::assertSame($versionBefore, $versionAfter, 'Schema version must not advance on a failed provision.');

        // The DDL auto-committed before the registry transaction; per ADR
        // 0017 / schema reference §4.6 the orphan table is left for operator
        // triage. Assert it exists but is unregistered.
        $orphanExists = (bool) $this->pdo
            ->query(
                "SELECT 1 FROM information_schema.TABLES"
                . " WHERE table_schema = DATABASE() AND table_name = 'entry_slots_page_2'"
            )
            ->fetchColumn();
        self::assertTrue($orphanExists, 'Orphan extension page should remain after the rollback (operator triage).');
    }

    /** Exit criterion: schema version bumped in the same transaction as the provisioning. */
    public function testSchemaVersionIncrementsOnSuccessfulProvision(): void
    {
        $before = (int) $this->pdo
            ->query('SELECT version FROM stardust_schema_version WHERE id = 1')
            ->fetchColumn();

        $this->newProvisioner()->provision();

        $after = (int) $this->pdo
            ->query('SELECT version FROM stardust_schema_version WHERE id = 1')
            ->fetchColumn();
        self::assertSame($before + 1, $after);
    }

    /** Sequential calls produce entry_slots_page_1, entry_slots_page_2, … */
    public function testSequentialProvisionsAssignMonotonicPageNumbers(): void
    {
        $provisioner = $this->newProvisioner();

        self::assertSame(1, $provisioner->provision());
        self::assertSame(2, $provisioner->provision());
        self::assertSame(3, $provisioner->provision());

        $tables = $this->pdo
            ->query(
                "SELECT table_name FROM stardust_pages ORDER BY id"
            )
            ->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(
            ['entry_slots_page_1', 'entry_slots_page_2', 'entry_slots_page_3'],
            $tables,
        );
    }

    /** ADR 0020 `page_provisioned` event lands on the structured-log stream. */
    public function testProvisionEmitsStructuredLogEvent(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);

        $provisioner = new PageProvisioner(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: new StdoutNdjsonLogger(new SystemClock(), $stream),
            provisionerIdentity: 'phpunit/0',
        );

        $provisioner->provision(['i_str_01']);

        rewind($stream);
        $records = array_values(array_filter(explode("\n", (string) stream_get_contents($stream))));
        self::assertCount(1, $records, 'Provision should emit exactly one log record.');

        $decoded = json_decode($records[0], true);
        self::assertIsArray($decoded);
        self::assertSame('page_provisioned', $decoded['event'] ?? null);
        self::assertSame('registry', $decoded['source'] ?? null);
        self::assertSame(1, $decoded['page_id'] ?? null);
        self::assertSame('entry_slots_page_1', $decoded['table_name'] ?? null);
        self::assertSame(['i_str_01'], $decoded['filterable_slots'] ?? null);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $decoded['correlation_id'] ?? '',
        );
    }

    /** Unknown slot column names are rejected before any SQL runs. */
    public function testProvisionRejectsUnknownFilterableSlot(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->newProvisioner()->provision(['i_str_99']);
    }

    /** Duplicate slot names are silently deduplicated so MySQL errno 1061 cannot leak. */
    public function testProvisionDeduplicatesFilterableSlots(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);

        $provisioner = new PageProvisioner(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: new StdoutNdjsonLogger(new SystemClock(), $stream),
            provisionerIdentity: 'phpunit/0',
        );

        $pageId = $provisioner->provision(['i_str_01', 'i_str_01', 'i_int_03']);
        self::assertSame(1, $pageId);

        $rows = $this->pdo->query('SHOW INDEX FROM entry_slots_page_1')->fetchAll();
        $perSlotIndexes = [];
        foreach ($rows as $r) {
            $name = (string) $r['Key_name'];
            if (str_starts_with($name, 'ix_entry_slots_page_1_i_')) {
                $perSlotIndexes[$name] = true;
            }
        }
        self::assertSame(
            ['ix_entry_slots_page_1_i_str_01', 'ix_entry_slots_page_1_i_int_03'],
            array_keys($perSlotIndexes),
            'Per-slot indexes must be emitted exactly once per distinct slot.',
        );

        rewind($stream);
        $records = array_values(array_filter(explode("\n", (string) stream_get_contents($stream))));
        self::assertCount(1, $records);
        $decoded = json_decode($records[0], true);
        self::assertIsArray($decoded);
        self::assertSame(
            ['i_str_01', 'i_int_03'],
            $decoded['filterable_slots'] ?? null,
            'Log payload must reflect the deduplicated slot list, not the raw input.',
        );
    }
}
