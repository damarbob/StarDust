<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Reconciler;

use StarDust\Clock\SystemClock;
use StarDust\Logging\StdoutNdjsonLogger;
use StarDust\Reconciler\TickOutcome;
use StarDust\Tests\Smoke\Phase5TestCase;

/**
 * Exhaustion → enqueue → capacity-wait round-trip.
 *
 * The setup creates an entry whose registered field has NO live slot
 * (no page provisioned yet). `EntryWriter::write()` enqueues; the
 * Reconciler tick claims the row but discovers the field still has
 * no slot, emits `capacity_wait`, and rolls the chunk back so the
 * queue row stays claimable for a future tick.
 */
final class SyncQueueCapacityWaitTest extends Phase5TestCase
{
    public function testCapacityWaitRollsBackAndEmitsEvent(): void
    {
        $modelId = $this->createModel(1);
        $this->createField($modelId, 'string', false, 'no_slot');

        // Write directly (no page provisioned). EntryWriter handles
        // the exhaustion enqueue.
        $entryId = $this->seedEntry(1, $modelId, ['no_slot' => 'x']);

        // Sanity: the write enqueued.
        self::assertSame(1, $this->countQueueRows());

        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $logger = new StdoutNdjsonLogger(new SystemClock(), $stream);

        $source = $this->makeSyncQueueWorkSource($logger);
        $outcome = $source->tickOne('test-corr-cap');

        self::assertSame(TickOutcome::CAPACITY_WAIT, $outcome);
        self::assertSame(1, $this->countQueueRows(), 'Queue row must remain after capacity_wait.');
        self::assertSame(0, $this->countDlqRows(), 'No DLQ on capacity_wait.');
        unset($entryId);

        rewind($stream);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($stream))));
        $events = array_map(static fn (string $l) => json_decode($l, true, flags: JSON_THROW_ON_ERROR), $lines);

        $names = array_map(static fn (array $e) => $e['event'] ?? null, $events);
        self::assertContains('chunk_claimed', $names);
        self::assertContains('capacity_wait', $names);
        self::assertNotContains('chunk_complete', $names);
    }

    private function countQueueRows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_sync_queue')->fetchColumn();
    }

    private function countDlqRows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_reconciler_dlq')->fetchColumn();
    }
}
