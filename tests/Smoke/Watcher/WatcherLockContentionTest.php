<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Watcher;

use PDO;
use StarDust\Clock\SystemClock;
use StarDust\Daemon\AdvisoryLock;
use StarDust\Logging\StdoutNdjsonLogger;
use StarDust\Tests\Smoke\Phase5TestCase;

/**
 * End-to-end Watcher → `lock_contention` event flow.
 *
 * The production Watcher's blueprint-pinned 10 s lock timeout would
 * sit on the connection for ten seconds when a sibling holds the
 * lock. We override it via `Config::$watcherProvisionLockTimeoutSeconds`
 * for testing (defaults to 10 in production) — same code path, faster
 * smoke run.
 */
final class WatcherLockContentionTest extends Phase5TestCase
{
    public function testLockContentionEmitsEventAndDoesNotProvision(): void
    {
        // Sibling PDO holds the advisory lock. MUST be a separate
        // session because GET_LOCK is re-entrant on the same session.
        $sibling = $this->newSiblingPdo();
        $heldLock = AdvisoryLock::acquire($sibling, 'stardust_page_provision', 1);

        try {
            $stream = fopen('php://memory', 'r+');
            self::assertNotFalse($stream);
            $logger = new StdoutNdjsonLogger(new SystemClock(), $stream);

            // 1 s timeout — fast smoke run; identical catch path as the
            // production 10 s timeout.
            $watcher = $this->makeWatcher($logger, threshold: 0.20, lockTimeoutSeconds: 1);

            $pagesBefore = $this->countPages();
            $watcher->tick();
            $pagesAfter = $this->countPages();

            self::assertSame($pagesBefore, $pagesAfter, 'Watcher must not provision when lock is held.');

            $events = $this->readEvents($stream);
            $names = array_map(static fn (array $e) => $e['event'] ?? null, $events);

            self::assertContains('lock_contention', $names);
            self::assertNotContains('provision_started', $names);
            self::assertNotContains('provision_complete', $names);

            // The lock_contention event must carry source: watcher.
            $lockEvents = array_values(array_filter(
                $events,
                static fn (array $e) => ($e['event'] ?? null) === 'lock_contention',
            ));
            self::assertCount(1, $lockEvents);
            self::assertSame('watcher', $lockEvents[0]['source'] ?? null);
        } finally {
            $heldLock->release();
        }
    }

    private function countPages(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_pages')->fetchColumn();
    }

    private function newSiblingPdo(): PDO
    {
        $dsn  = getenv('STARDUST_TEST_DSN') ?: '';
        $user = getenv('STARDUST_TEST_USER') ?: '';
        $pass = getenv('STARDUST_TEST_PASS') ?: '';
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function readEvents($stream): array
    {
        rewind($stream);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($stream))));
        return array_map(
            static fn (string $l) => json_decode($l, true, flags: JSON_THROW_ON_ERROR),
            $lines,
        );
    }
}
