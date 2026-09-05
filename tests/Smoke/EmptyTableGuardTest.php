<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use InvalidArgumentException;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use StarDust\Page\EmptyTableGuard;
use StarDust\Page\PopulatedPageDDLException;
use StarDust\Tests\Smoke\Support\LegacyPage;
use StarDust\Tests\Smoke\Support\SchemaFixture;

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

        SchemaFixture::reset($this->pdo);
    }

    private function provisionPage1(): void
    {
        // A legacy-shaped page: the guard is about page *rows*, not page
        // width, and this keeps the fixture independent of whatever
        // column set the provisioner currently emits.
        LegacyPage::provision($this->pdo, 'phpunit/0');
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
