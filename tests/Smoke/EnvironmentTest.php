<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use StarDust\Config\Config;
use StarDust\Exception\UnsupportedServerException;
use StarDust\Logging\StdoutNdjsonLogger;
use StarDust\StarDust;
use StarDust\Support\ServerEngine;
use StarDust\Support\ServerEngineDetector;

/**
 * Phase 0 smoke suite — verifies the operating environment satisfies
 * the Phase 0 exit criteria (MySQL 8.0.13+ / MariaDB 10.11+ feature
 * surface per ADR 0055, unsupported-server rejection, package boots
 * with defaults).
 *
 * Connection parameters are read from env vars:
 *   STARDUST_TEST_DSN   (required, e.g. "mysql:host=127.0.0.1;port=3306")
 *   STARDUST_TEST_USER  (required)
 *   STARDUST_TEST_PASS  (optional, defaults to "")
 *
 * When pointed at a server `ServerEngineDetector` does not recognise —
 * MariaDB 10.6 or earlier, MySQL/Percona below 8.0.13, or anything
 * else entirely — `testServerIsASupportedEngine` throws and the suite
 * exits non-zero. CI's `mariadb-rejection` job exploits that against a
 * below-floor MariaDB target to prove the floor is enforced rather
 * than merely documented.
 */
final class EnvironmentTest extends TestCase
{
    private const PARTIAL_INDEX_TABLE = 'stardust_smoke_partial_unique';

    private PDO $pdo;
    private ServerEngine $engine;

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

        // Deliberately NOT wrapped: testServerIsASupportedEngine is what
        // proves this call fails closed on an unsupported server, and
        // catching it here would swallow that for every other test too.
        $this->engine = ServerEngineDetector::detect($this->pdo);
    }

    protected function tearDown(): void
    {
        // Best-effort cleanup so re-runs against the same DB are idempotent.
        // Guarded with isset() because PHPUnit may still invoke tearDown
        // after a setUp that exited via markTestSkipped() / self::fail(),
        // leaving the typed $pdo property uninitialised.
        if (! isset($this->pdo)) {
            return;
        }
        try {
            $this->pdo->exec('DROP TABLE IF EXISTS ' . self::PARTIAL_INDEX_TABLE);
        } catch (\Throwable) {
            // ignored
        }
    }

    /**
     * Exit criterion 4 (ADR 0055 §4): an unsupported server must cause
     * the suite to exit non-zero. `setUp()` already ran detection to
     * populate `$this->engine` — this method exists to name the
     * criterion explicitly and to assert on the result, since a test
     * with no assertion of its own is risky under
     * `beStrictAboutTestsThatDoNotTestAnything`.
     *
     * Renamed from `testServerIsMySql`, which pre-dated MariaDB support
     * and unconditionally failed on any `MariaDB`-marked version
     * string. The floor itself — MySQL/Percona 8.0.13+ or MariaDB
     * 10.11+ — is enforced by `ServerEngineDetector::detect()`, not
     * re-derived here; this method's job is only to prove the *smoke
     * suite* observes the same floor the runtime does; see
     * `ServerEngineDetectorTest` for the mechanism's own boundary
     * coverage.
     */
    public function testServerIsASupportedEngine(): void
    {
        self::assertContains(
            $this->engine,
            [ServerEngine::MYSQL, ServerEngine::MARIADB],
            'ServerEngineDetector::detect() returned an engine outside the closed enum — this should be unreachable; it throws UnsupportedServerException for anything else.',
        );
    }

    /** Reinforces criteria 2-3: server must be 8.0.13 or newer. */
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

    /**
     * Exit criterion 3: a partial/conditional unique index must work
     * (this is the mechanism that enforces the registry's "at most one
     * live slot per field" invariant per ADR 0017 / 0023) — on **either**
     * supported engine, via whichever mechanism that engine has.
     *
     * MySQL gets the literal 8.0.13+ functional index this test always
     * used. MariaDB has no functional-index syntax at all (errno 1064,
     * measured on 10.6/10.11/11) and gets the ADR 0054 §4 substitute
     * instead: a `PERSISTENT` generated column holding the identical
     * `CASE` expression, plus a plain `UNIQUE` index over it — the same
     * construct `Bootstrap\Bootstrapper::ensureSlotAssignmentLiveFieldIdColumn()`
     * adds to the real registry table. **The two branches are asserted
     * on identically past table setup**: same insert sequence, same
     * expected `PDOException` on the second live row. That is the
     * point — this test exists to prove the *observable behaviour* the
     * registry actually depends on, not to prove MySQL's specific DDL
     * syntax parses.
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

        if ($this->engine === ServerEngine::MYSQL) {
            // MySQL 8.0.13+ functional index. MariaDB rejects this syntax.
            $this->pdo->exec("
                CREATE UNIQUE INDEX ux_{$table}_live
                    ON {$table} (
                        (CASE WHEN status IN ('assigned', 'backfilling', 'ready')
                              THEN field_id END)
                    )
            ");
        } else {
            // MariaDB substitute (ADR 0054 §4): a PERSISTENT generated
            // column holding the identical CASE expression, plus a plain
            // UNIQUE index over it.
            $this->pdo->exec("
                ALTER TABLE {$table}
                    ADD COLUMN live_field_id INT
                        GENERATED ALWAYS AS (
                            CASE WHEN status IN ('assigned', 'backfilling', 'ready')
                                 THEN field_id END
                        ) PERSISTENT
            ");
            $this->pdo->exec("CREATE UNIQUE INDEX ux_{$table}_live ON {$table} (live_field_id)");
        }

        // Inserting two 'free' rows with the same field_id must succeed
        // (CASE returns NULL, and NULLs are not unique-constrained).
        $this->pdo->exec("INSERT INTO {$table} (field_id, status) VALUES (1, 'free')");
        $this->pdo->exec("INSERT INTO {$table} (field_id, status) VALUES (1, 'free')");

        // One 'assigned' row is fine.
        $this->pdo->exec("INSERT INTO {$table} (field_id, status) VALUES (1, 'assigned')");

        // A second 'assigned' row for the same field_id must violate the
        // partial unique constraint — same observable behaviour on both engines.
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
