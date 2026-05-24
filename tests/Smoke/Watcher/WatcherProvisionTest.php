<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Watcher;

use StarDust\Clock\SystemClock;
use StarDust\Logging\StdoutNdjsonLogger;
use StarDust\Tests\Smoke\Phase5TestCase;

/**
 * Phase 5 Watcher exit criteria: provisions a new page when capacity
 * drops below threshold; emits the closed `watcher`-source event
 * vocabulary on every tick.
 *
 * Lock-contention is verified separately in {@see AdvisoryLockTest} —
 * the Watcher's hard-coded 10 s timeout (per blueprint AC#2) would
 * make a tick-level test of contention sit on the connection for ten
 * seconds, so we cover the lock primitive at unit level instead.
 */
final class WatcherProvisionTest extends Phase5TestCase
{
    public function testTickProvisionsPageWhenNoPagesExist(): void
    {
        $watcher = $this->makeWatcher(threshold: 0.20);

        self::assertSame(0, $this->countPages());
        $watcher->tick();
        self::assertSame(1, $this->countPages());

        self::assertSame(60, $this->countSlotAssignments());
    }

    public function testTickIsNoOpWhenCapacityAboveThreshold(): void
    {
        $this->provisionPage();

        $watcher = $this->makeWatcher(threshold: 0.20);
        $watcher->tick();

        self::assertSame(1, $this->countPages(), 'Watcher must not provision when capacity is healthy.');
    }

    public function testPollStartedAndPollCompleteAlwaysFire(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $logger = new StdoutNdjsonLogger(new SystemClock(), $stream);

        $watcher = $this->makeWatcher($logger, threshold: 0.20);
        $watcher->tick();

        $events = $this->readStream($stream);
        $names = array_map(static fn (array $e) => $e['event'] ?? null, $events);

        self::assertContains('poll_started', $names);
        self::assertContains('poll_complete', $names);
        self::assertContains('provision_complete', $names);

        foreach ($events as $event) {
            $name = $event['event'] ?? null;
            if (in_array($name, ['poll_started', 'poll_complete', 'provision_started', 'provision_complete'], true)) {
                self::assertSame('watcher', $event['source'] ?? null);
            }
        }
    }

    public function testProvisioningBumpsSchemaVersion(): void
    {
        $startVersion = $this->fetchSchemaVersion();
        $this->makeWatcher(threshold: 0.20)->tick();
        self::assertGreaterThan($startVersion, $this->fetchSchemaVersion());
    }

    private function countPages(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_pages')->fetchColumn();
    }

    private function countSlotAssignments(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_slot_assignments')->fetchColumn();
    }

    private function fetchSchemaVersion(): int
    {
        return (int) $this->pdo->query('SELECT version FROM stardust_schema_version WHERE id = 1')->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    private function readStream($stream): array
    {
        rewind($stream);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($stream))));
        return array_map(
            static fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            $lines,
        );
    }
}
