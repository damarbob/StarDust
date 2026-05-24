<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Daemon;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use StarDust\Daemon\AdvisoryLock;
use StarDust\Exception\AdvisoryLockTimeoutException;

/**
 * Phase 5 advisory-lock smoke. Exercises the {@see AdvisoryLock} wrapper
 * directly so we can use a 1 s timeout (the Watcher's own 10 s is pinned
 * by blueprint AC#2 and would make the test sit on the connection for
 * ten seconds).
 */
final class AdvisoryLockTest extends TestCase
{
    private PDO $primary;
    private PDO $sibling;

    protected function setUp(): void
    {
        $dsn  = getenv('STARDUST_TEST_DSN') ?: '';
        $user = getenv('STARDUST_TEST_USER') ?: '';
        $pass = getenv('STARDUST_TEST_PASS') ?: '';

        if ($dsn === '' || $user === '') {
            self::markTestSkipped('STARDUST_TEST_DSN/STARDUST_TEST_USER must be set.');
        }

        try {
            $this->primary = $this->makeConn($dsn, $user, $pass);
            $this->sibling = $this->makeConn($dsn, $user, $pass);
        } catch (PDOException $e) {
            self::fail('Could not connect to test database: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->sibling)) {
            try { $this->sibling->exec("SELECT RELEASE_LOCK('stardust_test_lock')"); } catch (\Throwable) {}
        }
        if (isset($this->primary)) {
            try { $this->primary->exec("SELECT RELEASE_LOCK('stardust_test_lock')"); } catch (\Throwable) {}
        }
    }

    public function testAcquireReleaseHappyPath(): void
    {
        $lock = AdvisoryLock::acquire($this->primary, 'stardust_test_lock', 1);
        $lock->release();

        $second = AdvisoryLock::acquire($this->primary, 'stardust_test_lock', 1);
        self::assertNotNull($second);
        $second->release();
    }

    public function testTimeoutThrowsWhenSiblingHoldsLock(): void
    {
        $sibling = AdvisoryLock::acquire($this->sibling, 'stardust_test_lock', 1);

        try {
            AdvisoryLock::acquire($this->primary, 'stardust_test_lock', 1);
            self::fail('Expected AdvisoryLockTimeoutException.');
        } catch (AdvisoryLockTimeoutException $e) {
            self::assertStringContainsString('stardust_test_lock', $e->getMessage());
        } finally {
            $sibling->release();
        }
    }

    public function testReleaseIsIdempotent(): void
    {
        $lock = AdvisoryLock::acquire($this->primary, 'stardust_test_lock', 1);
        $lock->release();
        $lock->release();
        // No exception, no exception — the test passes if we got here.
        self::assertTrue(true);
    }

    private function makeConn(string $dsn, string $user, string $pass): PDO
    {
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
