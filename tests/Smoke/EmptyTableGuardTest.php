<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use StarDust\Bootstrap\Bootstrapper;
use StarDust\Clock\SystemClock;
use StarDust\Page\EmptyTableGuard;
use StarDust\Page\PageProvisioner;
use StarDust\Page\PopulatedPageDDLException;

/**
 * Phase 2 ADR 0012 guard smoke suite.
 *
 * Exercises EmptyTableGuard against a real provisioned page: empty
 * pages pass; a page that has had one row inserted is rejected with
 * the typed exception; non-conforming table names are rejected before
 * any SQL runs.
 */
final class EmptyTableGuardTest extends TestCase
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

    private function provisionPage1(): void
    {
        (new PageProvisioner(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: new NullLogger(),
            provisionerIdentity: 'phpunit/0',
        ))->provision();
    }

    public function testAssertEmptyAcceptsEmptyPage(): void
    {
        $this->provisionPage1();

        EmptyTableGuard::assertEmpty($this->pdo, 'entry_slots_page_1');
        self::assertTrue(true, 'No exception is the success signal.');
    }

    public function testAssertEmptyThrowsOnPopulatedPage(): void
    {
        $this->provisionPage1();

        $this->pdo->exec(
            "INSERT INTO entry_data (tenant_id, model_id, created_at, updated_at, fields)"
            . " VALUES (1, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), JSON_OBJECT())"
        );
        $entryId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec(
            "INSERT INTO entry_slots_page_1 (entry_id, tenant_id, i_str_01)"
            . " VALUES ({$entryId}, 1, 'sentinel')"
        );

        $this->expectException(PopulatedPageDDLException::class);
        EmptyTableGuard::assertEmpty($this->pdo, 'entry_slots_page_1');
    }

    public function testAssertEmptyRejectsInvalidTableName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EmptyTableGuard::assertEmpty($this->pdo, 'entry_data');
    }

    public function testAssertEmptyRejectsInjectionAttempt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EmptyTableGuard::assertEmpty($this->pdo, 'entry_slots_page_1; DROP TABLE entry_data');
    }

    public function testAssertEmptyRejectsZeroIndexedTableName(): void
    {
        // Page numbering is 1-indexed (stardust_pages.id starts at 1);
        // `entry_slots_page_0` should never appear and the regex rejects it.
        $this->expectException(InvalidArgumentException::class);
        EmptyTableGuard::assertEmpty($this->pdo, 'entry_slots_page_0');
    }
}
