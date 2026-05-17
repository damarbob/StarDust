<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use StarDust\Config\Config;
use StarDust\Logging\StdoutNdjsonLogger;
use StarDust\StarDust;

/**
 * Phase 0 smoke suite — verifies the operating environment satisfies
 * the Phase 0 exit criteria (MySQL 8.0.13+ feature surface, MariaDB
 * rejection, package boots with defaults).
 *
 * Connection parameters are read from env vars:
 *   STARDUST_TEST_DSN   (required, e.g. "mysql:host=127.0.0.1;port=3306")
 *   STARDUST_TEST_USER  (required)
 *   STARDUST_TEST_PASS  (optional, defaults to "")
 *
 * When pointed at a MariaDB instance, this suite is expected to fail
 * (the version-string check and the partial-unique-index check both
 * reject MariaDB). CI exploits that to satisfy the rejection criterion.
 */
final class EnvironmentTest extends TestCase
{
    private const PARTIAL_INDEX_TABLE = 'stardust_smoke_partial_unique';

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
    }

    protected function tearDown(): void
    {
        // Best-effort cleanup so re-runs against the same DB are idempotent.
        try {
            $this->pdo->exec('DROP TABLE IF EXISTS ' . self::PARTIAL_INDEX_TABLE);
        } catch (\Throwable) {
            // ignored
        }
    }

    /** Exit criterion 5: MariaDB must cause the suite to exit non-zero. */
    public function testServerIsMySql(): void
    {
        $version = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();

        self::assertStringNotContainsString(
            'MariaDB',
            $version,
            'StarDust does not support MariaDB; MySQL 8.0.13+ or Percona 8.0.13+ required.',
        );
    }

    /** Reinforces criteria 2-4: server must be 8.0.13 or newer. */
    public function testMySqlVersionFloor(): void
    {
        $version = (string) $this->pdo->query('SELECT VERSION()')->fetchColumn();

        // Parse "8.0.39" / "8.0.39-foo" / "8.4.0" — first three dotted ints.
        self::assertMatchesRegularExpression(
            '/^(\d+)\.(\d+)\.(\d+)/',
            $version,
            'Unexpected VERSION() output: ' . $version,
        );

        preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $m);
        $tuple = [(int) $m[1], (int) $m[2], (int) $m[3]];

        self::assertGreaterThanOrEqual(
            0,
            $this->compareVersion($tuple, [8, 0, 13]),
            "MySQL 8.0.13+ required; got {$version}.",
        );
    }

    /** Exit criterion 4: CTEs (WITH ... AS) must be available. */
    public function testCteSupported(): void
    {
        $stmt = $this->pdo->query('WITH cte AS (SELECT 1 AS n) SELECT n FROM cte');
        self::assertNotFalse($stmt);

        $row = $stmt->fetch();
        self::assertSame(1, (int) $row['n']);
    }

    /**
     * Exit criterion 3: functional / conditional unique indexes must work
     * (this is the mechanism that enforces the registry's "at most one
     * live slot per field" invariant per ADR 0017 / 0023).
     */
    public function testPartialUniqueIndexSupported(): void
    {
        $table = self::PARTIAL_INDEX_TABLE;

        $this->pdo->exec("DROP TABLE IF EXISTS {$table}");
        $this->pdo->exec("
            CREATE TABLE {$table} (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                field_id INT NOT NULL,
                status VARCHAR(20) NOT NULL
            ) ENGINE=InnoDB
        ");

        // MySQL 8.0.13+ functional index. MariaDB rejects this syntax.
        $this->pdo->exec("
            CREATE UNIQUE INDEX ux_{$table}_live
                ON {$table} (
                    (CASE WHEN status IN ('assigned', 'backfilling', 'ready')
                          THEN field_id END)
                )
        ");

        // Inserting two 'free' rows with the same field_id must succeed
        // (CASE returns NULL, and NULLs are not unique-constrained).
        $this->pdo->exec("INSERT INTO {$table} (field_id, status) VALUES (1, 'free')");
        $this->pdo->exec("INSERT INTO {$table} (field_id, status) VALUES (1, 'free')");

        // One 'assigned' row is fine.
        $this->pdo->exec("INSERT INTO {$table} (field_id, status) VALUES (1, 'assigned')");

        // A second 'assigned' row for the same field_id must violate the
        // partial unique constraint.
        $this->expectException(PDOException::class);
        $this->pdo->exec("INSERT INTO {$table} (field_id, status) VALUES (1, 'assigned')");
    }

    /** Exit criterion 1 (smoke): the engine class boots with defaults. */
    public function testEnginePackageBootsWithDefaults(): void
    {
        $engine = new StarDust(new Config(pdo: $this->pdo));

        self::assertSame($this->pdo, $engine->pdo());
        self::assertInstanceOf(StdoutNdjsonLogger::class, $engine->logger());
    }

    /**
     * @param array{int,int,int} $a
     * @param array{int,int,int} $b
     */
    private function compareVersion(array $a, array $b): int
    {
        for ($i = 0; $i < 3; $i++) {
            if ($a[$i] !== $b[$i]) {
                return $a[$i] <=> $b[$i];
            }
        }
        return 0;
    }
}
