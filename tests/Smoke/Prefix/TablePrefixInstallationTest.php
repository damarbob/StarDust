<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Prefix;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use StarDust\Config\Config;
use StarDust\Daemon\LockNamespace;
use StarDust\Daemon\PidFileGuard;
use StarDust\Daemon\TickStopReason;
use StarDust\Export\ExportJobRequest;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Read\EntryQuery;
use StarDust\Read\SortDirection;
use StarDust\Read\SortSpec;
use StarDust\Schema\FieldDefinition;
use StarDust\Search\SearchRequest;
use StarDust\StarDust;
use StarDust\Support\TableNames;
use StarDust\Tests\Smoke\Support\TestPdo;
use StarDust\Write\EntryPayload;

/**
 * ADR 0059 end to end: an installation under a table prefix works through
 * the public surface, and two installations share one database without
 * touching each other or an application's own tables.
 *
 * **The tests run in a database of their own, created for the class and
 * dropped after it.** That is what makes them sharp. The rest of the suite
 * leaves the bare StarDust tables in its database, so a query that missed
 * the prefix would quietly succeed against them. Here no bare table
 * exists, so such a query fails with "table does not exist". The class
 * skips when the connection may not create a database.
 *
 * Everything goes through `StarDust` and its daemons (`tick()` runs the
 * Watcher, Liberator, Reconciler and Chronicler), because the point is
 * the paths a literal scan cannot see.
 */
final class TablePrefixInstallationTest extends TestCase
{
    private const A = 'pxa_';
    private const B = 'pxb_';

    private static ?PDO $admin = null;
    private static ?PDO $pdo = null;
    private static string $database = '';

    /** @var array<string, true> prefixes already bootstrapped in this database */
    private static array $bootstrapped = [];

    private string $workDir;

    public static function setUpBeforeClass(): void
    {
        $dsn  = getenv('STARDUST_TEST_DSN') ?: '';
        $user = getenv('STARDUST_TEST_USER') ?: '';
        $pass = getenv('STARDUST_TEST_PASS') ?: '';

        if ($dsn === '' || $user === '') {
            self::markTestSkipped('STARDUST_TEST_DSN and STARDUST_TEST_USER must be set for smoke tests.');
        }

        self::$admin = new PDO($dsn, $user, $pass, TestPdo::options());
        self::$database = 'stardust_prefix_' . bin2hex(random_bytes(4));

        try {
            self::$admin->exec('CREATE DATABASE ' . self::$database);
        } catch (PDOException $e) {
            self::markTestSkipped('These tests need a connection that can create a database: ' . $e->getMessage());
        }

        $ownDsn = str_contains($dsn, 'dbname=')
            ? (string) preg_replace('/dbname=[^;]*/', 'dbname=' . self::$database, $dsn)
            : $dsn . ';dbname=' . self::$database;
        self::$pdo = new PDO($ownDsn, $user, $pass, TestPdo::options());
    }

    public static function tearDownAfterClass(): void
    {
        self::$pdo = null;
        if (self::$admin !== null && self::$database !== '') {
            self::$admin->exec('DROP DATABASE IF EXISTS ' . self::$database);
        }
        self::$admin = null;
        self::$bootstrapped = [];
    }

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stardust-prefix-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->workDir) && is_dir($this->workDir)) {
            self::removeTree($this->workDir);
        }
    }

    public function testBootstrapCreatesExactlyThePrefixedTables(): void
    {
        $this->install(self::A);

        $expected = array_map(
            static fn (string $name): string => self::A . $name,
            [
                'entry_data', 'backfill_checkpoints', 'stardust_sync_queue', 'stardust_models',
                'stardust_fields', 'stardust_pages', 'stardust_slot_assignments',
                'stardust_schema_version', 'stardust_export_jobs', 'stardust_import_jobs',
                'stardust_reconciler_dlq', 'stardust_advisory_schedule',
            ],
        );
        sort($expected);

        $own = array_values(array_filter(
            $this->tableNames(),
            static fn (string $name): bool => str_starts_with($name, self::A),
        ));
        sort($own);

        self::assertSame($expected, $own);
    }

    public function testNothingIsCreatedWithoutAPrefix(): void
    {
        $this->install(self::A);

        $bare = array_values(array_filter(
            $this->tableNames(),
            static fn (string $name): bool => !str_starts_with($name, self::A)
                && !str_starts_with($name, self::B),
        ));

        self::assertSame([], $bare, 'A table was created without the prefix.');
    }

    public function testNamedConstraintsCarryThePrefix(): void
    {
        $this->install(self::A);

        $foreignKeys = $this->column(
            "SELECT constraint_name FROM information_schema.TABLE_CONSTRAINTS"
            . " WHERE table_schema = DATABASE() AND constraint_type = 'FOREIGN KEY'"
            . ' ORDER BY constraint_name'
        );
        foreach (['pxa_fk_fields_model', 'pxa_fk_slot_assignments_page', 'pxa_fk_slot_assignments_field'] as $name) {
            self::assertContains($name, $foreignKeys);
        }
        foreach ($foreignKeys as $name) {
            self::assertTrue(
                str_contains($name, self::A) || str_contains($name, self::B),
                "Foreign key '{$name}' carries no prefix, so a second installation would collide on it.",
            );
        }

        // CHECK constraints are not stored on MySQL before 8.0.16.
        try {
            $checks = $this->column(
                'SELECT constraint_name FROM information_schema.CHECK_CONSTRAINTS'
                . ' WHERE constraint_schema = DATABASE() ORDER BY constraint_name'
            );
        } catch (PDOException) {
            return;
        }
        if ($checks === []) {
            return;
        }
        foreach (['pxa_ck_schema_version_singleton', 'pxa_ck_advisory_schedule_singleton'] as $name) {
            self::assertContains($name, $checks);
        }
    }

    public function testAFullLifecycleRunsUnderAPrefix(): void
    {
        $sd = $this->install(self::A);
        $tables = new TableNames(self::A);

        $model = $sd->schemaBuilder()->createModel(1, 'article', [
            new FieldDefinition('title', 'string', true),
            new FieldDefinition('views', 'int', true),
            new FieldDefinition('note', 'string', false),
        ]);
        $modelId = $model->modelId;

        // No page exists yet. The Watcher sees the demand and provisions one.
        $this->drain($sd);
        self::assertSame(
            [$tables->page(1)],
            $this->column("SELECT table_name FROM {$tables->pages} ORDER BY id"),
            'The Watcher should have provisioned one page under the prefix.',
        );

        // Writes, then the Reconciler backfills whatever the first writes queued.
        $first = $sd->write(new EntryPayload(1, $modelId, ['title' => 'alpha', 'views' => 10, 'note' => 'n1']));
        $sd->write(new EntryPayload(1, $modelId, ['title' => 'beta', 'views' => 20, 'note' => 'n2']));
        $sd->write(new EntryPayload(1, $modelId, ['title' => 'gamma', 'views' => 30, 'note' => 'n3']));
        $this->drain($sd);
        self::assertSame(0, $this->rows($tables->syncQueue), 'The sync queue should be drained.');

        // Search by an indexed string and by an indexed range, and a field sort.
        $byTitle = $sd->search(SearchRequest::fromEntryQuery(
            new EntryQuery(1, $modelId, LeafNode::local('title', 'eq', 'beta')),
        ));
        self::assertCount(1, $byTitle->rows);
        self::assertSame('beta', $byTitle->rows[0]->fields['title']);

        $byRange = $sd->read(new EntryQuery(
            1,
            $modelId,
            LeafNode::local('views', 'gte', 20),
            null,
            100,
            null,
            SortSpec::byField('views', SortDirection::Desc),
        ));
        self::assertSame(['gamma', 'beta'], array_map(static fn ($e) => $e->fields['title'], $byRange->rows));

        $paged = $sd->read(new EntryQuery(1, $modelId, null, null, 2));
        self::assertCount(2, $paged->rows);
        self::assertNotNull($paged->nextCursor);

        self::assertSame('alpha', $sd->get(1, $first->entryId)?->fields['title']);
        self::assertCount(2, $sd->getMany(1, [$first->entryId, $first->entryId + 1]));

        // Update and soft delete.
        $sd->updateEntry(1, $first->entryId, ['title' => 'alpha2', 'views' => 11]);
        self::assertSame('alpha2', $sd->get(1, $first->entryId)?->fields['title']);
        self::assertTrue($sd->deleteEntry(1, $first->entryId));
        self::assertNull($sd->get(1, $first->entryId));

        // The synchronous and the asynchronous bulk paths.
        $bulk = $sd->bulkWrite([
            new EntryPayload(1, $modelId, ['title' => 'delta', 'views' => 40]),
            new EntryPayload(1, $modelId, ['title' => 'epsilon', 'views' => 50]),
        ]);
        self::assertSame(2, $bulk->entriesCommitted);

        $jobId = $sd->submitBulkWrite(1, [new EntryPayload(1, $modelId, ['title' => 'zeta', 'views' => 60])])->jobId;
        $this->drain($sd);
        self::assertSame('completed', $sd->getImportJob(1, $jobId)?->status);

        // An export drains through the Chronicler.
        $exportId = $sd->submitExport(new ExportJobRequest(1, $modelId, ExportJobRequest::FORMAT_CSV))->jobId;
        $this->drain($sd);
        $export = $sd->getExportJob(1, $exportId);
        self::assertSame('completed', $export?->status);
        self::assertNotNull($export->artifactPath);

        // A rename is asynchronous and the Reconciler finishes it.
        $sd->renameField(1, $model->fieldId('note'), 'remark');
        $this->drain($sd);
        $remarks = array_map(
            static fn ($e) => $e->fields['remark'] ?? null,
            $sd->read(new EntryQuery(1, $modelId, LeafNode::local('title', 'eq', 'gamma')))->rows,
        );
        self::assertSame(['n3'], $remarks);

        // A retype moves a filterable field to another slot family.
        $sd->retypeField(1, $model->fieldId('views'), 'numeric');
        $this->drain($sd);
        $retyped = $sd->read(new EntryQuery(1, $modelId, LeafNode::local('views', 'gte', 40)));
        self::assertCount(3, $retyped->rows);

        // Compaction plans against the prefixed registry.
        self::assertSame($modelId, $sd->compactModel(1, $modelId, true)->modelId);

        // Deleting a field severs it, then the purge and the Liberator reclaim its slot.
        self::assertTrue($sd->deleteField(1, $model->fieldId('title')));
        $this->drain($sd);
        self::assertNull($sd->describeModel(1, $modelId)?->field('title'));
        self::assertSame(
            0,
            $this->rows($tables->slotAssignments, "status = 'assigned' AND field_id = " . $model->fieldId('title')),
        );

        // Deleting the model removes its entries.
        self::assertTrue($sd->deleteModel(1, $modelId));
        $this->drain($sd);
        self::assertSame(0, $this->rows($tables->entryData, 'model_id = ' . $modelId));
    }

    public function testTwoInstallationsInOneDatabaseDoNotInterfere(): void
    {
        $a = $this->install(self::A);
        $b = $this->install(self::B);

        $modelA = $a->schemaBuilder()->createModel(1, 'article', [new FieldDefinition('title', 'string', true)]);
        $modelB = $b->schemaBuilder()->createModel(1, 'article', [new FieldDefinition('title', 'string', true)]);
        $this->drain($a);
        $this->drain($b);

        $a->write(new EntryPayload(1, $modelA->modelId, ['title' => 'one']));
        $a->write(new EntryPayload(1, $modelA->modelId, ['title' => 'two']));
        $b->write(new EntryPayload(1, $modelB->modelId, ['title' => 'three']));
        $this->drain($a);
        $this->drain($b);

        self::assertCount(2, $a->read(new EntryQuery(1, $modelA->modelId))->rows);
        self::assertCount(1, $b->read(new EntryQuery(1, $modelB->modelId))->rows);

        // Each installation has its own pages, and a page number restarts at 1 in both.
        self::assertSame(['pxa_entry_slots_page_1'], $this->column('SELECT table_name FROM pxa_stardust_pages'));
        self::assertSame(['pxb_entry_slots_page_1'], $this->column('SELECT table_name FROM pxb_stardust_pages'));

        // Removing a model of one leaves the other alone.
        self::assertTrue($a->deleteModel(1, $modelA->modelId));
        $this->drain($a);
        self::assertSame(0, $this->rows('pxa_entry_data'));
        self::assertSame(1, $this->rows('pxb_entry_data'));
        self::assertCount(1, $b->read(new EntryQuery(1, $modelB->modelId))->rows);
    }

    public function testLockNamesDifferBetweenPrefixesAndAreUnchangedWithoutOne(): void
    {
        $a = $this->install(self::A);
        $b = $this->install(self::B);
        $plain = new StarDust(new Config(pdo: self::$pdo, logger: new NullLogger()));

        $name = 'stardust_page_provision';
        self::assertNotSame($a->lockNamespace()->qualify($name), $b->lockNamespace()->qualify($name));
        self::assertNotSame($a->lockNamespace()->qualify($name), $plain->lockNamespace()->qualify($name));

        // With no prefix the hash input is what it was before the prefix existed.
        self::assertSame(
            (new LockNamespace(self::$pdo))->qualify($name),
            $plain->lockNamespace()->qualify($name),
        );
    }

    public function testDaemonFilesDifferBetweenPrefixesSharingOneDirectory(): void
    {
        $a = $this->install(self::A);
        $b = $this->install(self::B);
        $dir = $this->workDir . DIRECTORY_SEPARATOR . 'pid';
        $tablesA = new TableNames(self::A);

        // Installation A's Watcher is running, so its pid file is held.
        $guard = PidFileGuard::tryAcquire($dir, $tablesA->daemon('watcher'));
        self::assertNotNull($guard);

        try {
            self::assertSame(
                TickStopReason::LOCK_CONTENDED,
                $a->tick(5)->stopReason,
                "A's own tick must see its Watcher's file held.",
            );
            self::assertNotSame(
                TickStopReason::LOCK_CONTENDED,
                $b->tick(5)->stopReason,
                "B's tick must not be blocked by A's Watcher.",
            );
        } finally {
            $guard->release();
        }

        // The shutdown flag is per installation too.
        touch($dir . DIRECTORY_SEPARATOR . $tablesA->daemon('reconciler') . '.shutdown');
        self::assertTrue($a->shutdownSignal('reconciler')->isRequested());
        self::assertFalse($b->shutdownSignal('reconciler')->isRequested());
    }

    public function testAnApplicationsOwnTablesWithTheBareNamesAreNotTouched(): void
    {
        $pdo = self::$pdo;
        self::assertNotNull($pdo);

        $pdo->exec('CREATE TABLE entry_data (id INT PRIMARY KEY, note VARCHAR(20))');
        $pdo->exec('CREATE TABLE backfill_checkpoints (job_name VARCHAR(20) PRIMARY KEY)');
        $pdo->exec("INSERT INTO entry_data (id, note) VALUES (1, 'theirs')");
        $pdo->exec("INSERT INTO backfill_checkpoints (job_name) VALUES ('theirs')");

        try {
            $before = $this->foreignSnapshot();

            $sd = $this->install('pxc_');
            $model = $sd->schemaBuilder()->createModel(1, 'article', [new FieldDefinition('title', 'string', true)]);
            $this->drain($sd);
            $sd->write(new EntryPayload(1, $model->modelId, ['title' => 'mine']));
            $sd->renameField(1, $model->fieldId('title'), 'heading');
            $this->drain($sd);

            self::assertSame($before, $this->foreignSnapshot());
            self::assertSame(1, $this->rows('pxc_entry_data'));
        } finally {
            $pdo->exec('DROP TABLE IF EXISTS entry_data');
            $pdo->exec('DROP TABLE IF EXISTS backfill_checkpoints');
        }
    }

    public function testAnInvalidPrefixStopsAtConfig(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Config(pdo: self::$pdo ?? $this->fail('no connection'), tablePrefix: 'Not Valid');
    }

    private function install(string $prefix): StarDust
    {
        self::assertNotNull(self::$pdo);

        $sd = new StarDust(new Config(
            pdo: self::$pdo,
            logger: new NullLogger(),
            artifactDir: $this->workDir . DIRECTORY_SEPARATOR . 'artifacts',
            pidFileDir: $this->workDir . DIRECTORY_SEPARATOR . 'pid',
            reconcilerCapacityWaitMillis: 0,
            tablePrefix: $prefix,
        ));

        if (!isset(self::$bootstrapped[$prefix])) {
            $sd->bootstrap();
            self::$bootstrapped[$prefix] = true;
        }

        return $sd;
    }

    /** Runs the combined tick, Chronicler included, until a run finds nothing to do. */
    private function drain(StarDust $sd): void
    {
        for ($i = 0; $i < 12; $i++) {
            if ($sd->tick(20, false, true)->stopReason === TickStopReason::IDLE) {
                return;
            }
        }
        self::fail('The installation did not go idle.');
    }

    /** @return list<string> */
    private function tableNames(): array
    {
        return $this->column(
            'SELECT table_name FROM information_schema.TABLES WHERE table_schema = DATABASE() ORDER BY table_name'
        );
    }

    /** @return list<string> */
    private function column(string $sql): array
    {
        self::assertNotNull(self::$pdo);
        $out = [];
        foreach (self::$pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $value) {
            $out[] = (string) $value;
        }

        return $out;
    }

    private function rows(string $table, string $where = '1 = 1'): int
    {
        self::assertNotNull(self::$pdo);

        return (int) self::$pdo->query("SELECT COUNT(*) FROM {$table} WHERE {$where}")->fetchColumn();
    }

    /** @return array<string, mixed> the foreign tables' columns and rows */
    private function foreignSnapshot(): array
    {
        self::assertNotNull(self::$pdo);

        return [
            'columns' => self::$pdo->query(
                'SELECT table_name, column_name, column_type FROM information_schema.COLUMNS'
                . " WHERE table_schema = DATABASE() AND table_name IN ('entry_data', 'backfill_checkpoints')"
                . ' ORDER BY table_name, ordinal_position'
            )->fetchAll(PDO::FETCH_NUM),
            'entry_data' => self::$pdo->query('SELECT id, note FROM entry_data ORDER BY id')->fetchAll(PDO::FETCH_NUM),
            'backfill_checkpoints' => self::$pdo->query('SELECT job_name FROM backfill_checkpoints')->fetchAll(PDO::FETCH_NUM),
        ];
    }

    private static function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
