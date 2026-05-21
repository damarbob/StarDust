<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use StarDust\Bootstrap\Bootstrapper;
use StarDust\Config\Config;
use StarDust\StarDust;

/**
 * Phase 1 smoke suite — verifies the bootstrap migration runner against
 * the six Phase 1 exit criteria (idempotent run, singleton seeded, ENUM
 * enforced, partial unique index present, composite indexes on
 * entry_data).
 *
 * Connection parameters are read from env vars:
 *   STARDUST_TEST_DSN   (required, e.g. "mysql:host=127.0.0.1;port=3306;dbname=stardust_test")
 *   STARDUST_TEST_USER  (required)
 *   STARDUST_TEST_PASS  (optional, defaults to "")
 *
 * The test database MUST already exist; the bootstrap runner creates
 * tables but not the schema itself. Use a dedicated throwaway database
 * — setUp() drops every StarDust table before each test.
 */
final class BootstrapTest extends TestCase
{
    /**
     * Static Phase 1 tables the bootstrap creates. Phase 2 extension pages
     * (`entry_slots_page_N`) are named dynamically and are NOT listed here —
     * `dropAllTables()` discovers them from `information_schema` at runtime
     * and drops them before this static set.
     *
     * The listed order is the reverse of FK dependency for human readability;
     * `dropAllTables()` disables `FOREIGN_KEY_CHECKS` for the sweep, so the
     * order is not load-bearing for the drop to succeed.
     *
     * @var list<string>
     */
    private const TABLES = [
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

        $this->dropAllTables();
    }

    protected function tearDown(): void
    {
        if (! isset($this->pdo)) {
            return;
        }
        try {
            $this->dropAllTables();
        } catch (\Throwable) {
            // best-effort
        }
    }

    private function dropAllTables(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        // Phase 2 extension pages (entry_slots_page_N) are named dynamically,
        // so they cannot be allowlisted in self::TABLES — discover any that
        // a previous Phase 2 smoke test (or a half-finished run) left behind
        // and drop them before the Phase 1 tables they reference.
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

        foreach (self::TABLES as $t) {
            try {
                $this->pdo->exec("DROP TABLE IF EXISTS {$t}");
            } catch (\Throwable) {
                // ignored
            }
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** Exit criterion 1: blank database → all tables present. */
    public function testBootstrapCreatesEveryTableOnBlankDatabase(): void
    {
        (new Bootstrapper($this->pdo))->run();

        foreach (self::TABLES as $table) {
            self::assertTrue(
                $this->tableExists($table),
                "Expected table {$table} to exist after bootstrap.",
            );
        }
    }

    /**
     * Exit criterion 2: re-running the bootstrap is a no-op. Verified by
     * inserting a sentinel row between runs and asserting it survives —
     * a buggy re-run that recreated the table would lose the row.
     */
    public function testBootstrapIsIdempotentAndNonDestructive(): void
    {
        $bootstrapper = new Bootstrapper($this->pdo);
        $bootstrapper->run();

        $this->pdo->exec(
            "INSERT INTO entry_data (tenant_id, model_id, created_at, updated_at, fields)"
            . " VALUES (42, 7, UTC_TIMESTAMP(), UTC_TIMESTAMP(), JSON_OBJECT('marker', 'phase1'))"
        );
        $sentinelId = (int) $this->pdo->lastInsertId();

        // Run twice more; neither call may throw, and the sentinel must remain.
        $bootstrapper->run();
        $bootstrapper->run();

        $marker = $this->pdo
            ->query("SELECT JSON_UNQUOTE(JSON_EXTRACT(fields, '$.marker')) AS m FROM entry_data WHERE id = {$sentinelId}")
            ->fetchColumn();
        self::assertSame('phase1', $marker, 'Idempotent bootstrap must not recreate populated tables.');

        // And every table must still be present.
        foreach (self::TABLES as $table) {
            self::assertTrue($this->tableExists($table), "Table {$table} disappeared during idempotent re-run.");
        }
    }

    /** Exit criterion 3: stardust_schema_version is seeded with exactly one row, id = 1. */
    public function testSchemaVersionSingletonSeeded(): void
    {
        (new Bootstrapper($this->pdo))->run();

        $rows = $this->pdo
            ->query('SELECT id, version FROM stardust_schema_version')
            ->fetchAll();

        self::assertCount(1, $rows, 'stardust_schema_version must contain exactly one row.');
        self::assertSame(1, (int) $rows[0]['id']);
        self::assertSame(0, (int) $rows[0]['version'], 'Initial version counter should be 0.');

        // Re-running must not duplicate the singleton.
        (new Bootstrapper($this->pdo))->run();
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_schema_version')->fetchColumn();
        self::assertSame(1, $count, 'Bootstrap re-run must not duplicate the singleton row.');
    }

    /**
     * Exit criterion 4: stardust_slot_assignments.status rejects any value
     * outside the closed five-state vocabulary. Relies on STRICT_TRANS_TABLES
     * (default sql_mode on MySQL 8.0+) elevating the ENUM truncation to an error.
     */
    public function testSlotAssignmentStatusEnumRejectsInvalidValue(): void
    {
        (new Bootstrapper($this->pdo))->run();

        // Seed a page so the FK on stardust_slot_assignments.page_id is satisfied.
        $this->pdo->exec(
            "INSERT INTO stardust_pages (table_name, provisioned_at, provisioned_by)"
            . " VALUES ('entry_slots_page_smoke', UTC_TIMESTAMP(), 'phpunit')"
        );
        $pageId = (int) $this->pdo->lastInsertId();

        $this->expectException(PDOException::class);
        $this->pdo->exec(
            "INSERT INTO stardust_slot_assignments (page_id, slot_column, slot_type, status)"
            . " VALUES ({$pageId}, 'i_str_01', 'str', 'definitely_not_a_real_status')"
        );
    }

    /** Sanity: each of the five legitimate status values is accepted. */
    public function testSlotAssignmentStatusEnumAcceptsAllFiveStates(): void
    {
        (new Bootstrapper($this->pdo))->run();

        $this->pdo->exec(
            "INSERT INTO stardust_pages (table_name, provisioned_at, provisioned_by)"
            . " VALUES ('entry_slots_page_smoke', UTC_TIMESTAMP(), 'phpunit')"
        );
        $pageId = (int) $this->pdo->lastInsertId();

        $states = ['free', 'assigned', 'tombstoned', 'backfilling', 'ready'];
        $stmt = $this->pdo->prepare(
            "INSERT INTO stardust_slot_assignments (page_id, slot_column, slot_type, status)"
            . " VALUES (?, ?, 'str', ?)"
        );

        foreach ($states as $i => $state) {
            $stmt->execute([$pageId, sprintf('i_str_%02d', $i + 1), $state]);
        }

        $count = (int) $this->pdo
            ->query("SELECT COUNT(*) FROM stardust_slot_assignments WHERE page_id = {$pageId}")
            ->fetchColumn();
        self::assertSame(5, $count, 'All five legal status values must be insertable.');
    }

    /**
     * Exit criterion 5: the partial unique index UNIQUE (field_id)
     * WHERE status IN ('assigned','backfilling','ready') is present.
     * Verified via SHOW INDEX per the criterion's literal wording.
     */
    public function testPartialUniqueIndexOnSlotAssignmentsIsPresent(): void
    {
        (new Bootstrapper($this->pdo))->run();

        $rows = $this->pdo
            ->query('SHOW INDEX FROM stardust_slot_assignments')
            ->fetchAll();

        $matching = array_values(array_filter(
            $rows,
            static fn(array $r): bool => ($r['Key_name'] ?? null) === 'ux_slot_assignments_field_live'
        ));

        self::assertCount(
            1,
            $matching,
            'Expected exactly one entry for ux_slot_assignments_field_live in SHOW INDEX.',
        );
        self::assertSame(
            0,
            (int) $matching[0]['Non_unique'],
            'Functional partial index must be UNIQUE (Non_unique = 0).',
        );

        // MySQL functional indexes leave Column_name NULL and populate Expression.
        $expression = $matching[0]['Expression'] ?? '';
        self::assertNotSame('', (string) $expression, 'Functional index must expose its expression.');
        self::assertStringContainsString('assigned', (string) $expression);
        self::assertStringContainsString('backfilling', (string) $expression);
        self::assertStringContainsString('ready', (string) $expression);
        self::assertStringContainsString('field_id', (string) $expression);
    }

    /**
     * Behavioral check: the partial unique index admits multiple free /
     * tombstoned rows for the same field_id but rejects a second live
     * row. Belt-and-braces on top of the SHOW INDEX assertion above.
     */
    public function testPartialUniqueIndexEnforcesAtMostOneLiveSlotPerField(): void
    {
        (new Bootstrapper($this->pdo))->run();

        $this->pdo->exec(
            "INSERT INTO stardust_models (tenant_id, name, created_at)"
            . " VALUES (1, 'smoke_model', UTC_TIMESTAMP())"
        );
        $modelId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec(
            "INSERT INTO stardust_fields (model_id, name, declared_type, is_filterable, created_at, updated_at)"
            . " VALUES ({$modelId}, 'smoke_field', 'string', TRUE, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $fieldId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec(
            "INSERT INTO stardust_pages (table_name, provisioned_at, provisioned_by)"
            . " VALUES ('entry_slots_page_smoke', UTC_TIMESTAMP(), 'phpunit')"
        );
        $pageId = (int) $this->pdo->lastInsertId();

        // Two free rows naming the same field_id must both be accepted
        // (the CASE expression yields NULL for free rows).
        $this->pdo->exec(
            "INSERT INTO stardust_slot_assignments (page_id, slot_column, slot_type, field_id, status)"
            . " VALUES ({$pageId}, 'i_str_01', 'str', {$fieldId}, 'free')"
        );
        $this->pdo->exec(
            "INSERT INTO stardust_slot_assignments (page_id, slot_column, slot_type, field_id, status)"
            . " VALUES ({$pageId}, 'i_str_02', 'str', {$fieldId}, 'free')"
        );

        // Promote i_str_01 to assigned — first live row for this field, allowed.
        $this->pdo->exec(
            "UPDATE stardust_slot_assignments SET status = 'assigned'"
            . " WHERE page_id = {$pageId} AND slot_column = 'i_str_01'"
        );

        // Promoting i_str_02 to a live state must violate the partial unique.
        $this->expectException(PDOException::class);
        $this->pdo->exec(
            "UPDATE stardust_slot_assignments SET status = 'backfilling'"
            . " WHERE page_id = {$pageId} AND slot_column = 'i_str_02'"
        );
    }

    /**
     * Exit criterion 6: entry_data carries the tenant-scoped composite
     * indexes (tenant_id, model_id) and (tenant_id, deleted_at, created_at).
     */
    public function testEntryDataCompositeIndexesPresent(): void
    {
        (new Bootstrapper($this->pdo))->run();

        $rows = $this->pdo->query('SHOW INDEX FROM entry_data')->fetchAll();

        $indexes = [];
        foreach ($rows as $r) {
            $name = (string) $r['Key_name'];
            $indexes[$name][(int) $r['Seq_in_index']] = (string) $r['Column_name'];
        }

        self::assertArrayHasKey(
            'ix_entry_data_tenant_model',
            $indexes,
            '(tenant_id, model_id) composite index missing on entry_data.',
        );
        ksort($indexes['ix_entry_data_tenant_model']);
        self::assertSame(
            ['tenant_id', 'model_id'],
            array_values($indexes['ix_entry_data_tenant_model']),
        );

        self::assertArrayHasKey(
            'ix_entry_data_tenant_lifecycle',
            $indexes,
            '(tenant_id, deleted_at, created_at) composite index missing on entry_data.',
        );
        ksort($indexes['ix_entry_data_tenant_lifecycle']);
        self::assertSame(
            ['tenant_id', 'deleted_at', 'created_at'],
            array_values($indexes['ix_entry_data_tenant_lifecycle']),
        );
    }

    /** Engine convenience method delegates to the Bootstrapper. */
    public function testEngineBootstrapMethodInvokesBootstrapper(): void
    {
        $engine = new StarDust(new Config(pdo: $this->pdo));
        $engine->bootstrap();

        self::assertTrue($this->tableExists('stardust_schema_version'));
        self::assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_schema_version')->fetchColumn(),
        );
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES'
            . ' WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$table]);
        return (bool) $stmt->fetchColumn();
    }
}
