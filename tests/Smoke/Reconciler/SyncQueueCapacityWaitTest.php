<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Reconciler;

use StarDust\Clock\SystemClock;
use StarDust\Logging\StdoutNdjsonLogger;
use StarDust\Reconciler\TickOutcome;
use StarDust\Tests\Smoke\Phase5TestCase;
use StarDust\Watcher\PendingDemandReader;

/**
 * Exhaustion → enqueue → reserve-or-wait round-trip.
 *
 * The setup creates an entry whose registered *filterable* field has NO
 * live slot. `EntryWriter::write()` enqueues; the Reconciler tick claims
 * the row, discovers the field still has no slot, and rolls the chunk
 * back so the queue rows stay claimable. What happens next is the ADR
 * 0007 reservation:
 *
 *   - Indexed free capacity exists ⇒ the tick reserves the slot and
 *     returns WORK_DONE with no `capacity_wait`; the next tick drains.
 *     This is what makes the Watcher's `pending_demand` gauge
 *     self-draining.
 *   - No indexed free capacity ⇒ `capacity_wait` and CAPACITY_WAIT,
 *     exactly as before, so the Watcher gets a chance to provision.
 *
 * Filterability is the discriminator: a non-filterable field is
 * JSON-only under ADR 0034, never enqueues, and therefore can never
 * produce the unsatisfiable capacity_wait loop this round-trip used to
 * be able to spin forever on. That negative case is covered alongside.
 */
final class SyncQueueCapacityWaitTest extends Phase5TestCase
{
    public function testCapacityWaitRollsBackAndEmitsEvent(): void
    {
        $modelId = $this->createModel(1);
        $this->createField($modelId, 'string', true, 'no_slot');

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

    /**
     * ADR 0034: a non-filterable field is JSON-only, so a slotless one
     * never enqueues — which is what eliminates the unsatisfiable
     * capacity_wait loop. Before ADR 0034 this exact setup produced a
     * queue row whose backfill could never succeed, so every Reconciler
     * tick re-claimed it, rolled the chunk back, and emitted
     * `capacity_wait` again, indefinitely.
     */
    public function testNonFilterableFieldNeverProducesAQueueRowOrCapacityWait(): void
    {
        $modelId = $this->createModel(1);
        $this->createField($modelId, 'string', false, 'json_only');

        $this->seedEntry(1, $modelId, ['json_only' => 'x']);

        self::assertSame(0, $this->countQueueRows(), 'A JSON-only field must never enqueue.');

        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $logger = new StdoutNdjsonLogger(new SystemClock(), $stream);

        $outcome = $this->makeSyncQueueWorkSource($logger)->tickOne('test-corr-json-only');

        self::assertSame(TickOutcome::IDLE, $outcome);
        self::assertSame(0, $this->countDlqRows());

        rewind($stream);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($stream))));
        $names = array_map(
            static fn (string $l) => json_decode($l, true, flags: JSON_THROW_ON_ERROR)['event'] ?? null,
            $lines,
        );
        self::assertNotContains('capacity_wait', $names);

        self::assertSame(
            0,
            $this->countLiveSlots(),
            'A JSON-only field must never acquire a slot, reservation path or not.',
        );
    }

    /**
     * The whole point of the ADR 0007 reservation: with indexed free
     * capacity available, the tick reserves rather than only rolling
     * back, and the next tick drains the queue it left claimable.
     */
    public function testReservesForAnUnmappedFilterableFieldThenDrainsOnTheNextTick(): void
    {
        $this->provisionPageWithIndexedFamily('str');
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'late_slot');

        $entryId = $this->seedEntry(1, $modelId, ['late_slot' => 'hello']);
        self::assertSame(1, $this->countQueueRows(), 'The write must enqueue: no slot exists yet.');
        self::assertNull($this->fetchSlotColumnFor($fieldId));

        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $logger = new StdoutNdjsonLogger(new SystemClock(), $stream);
        $source = $this->makeSyncQueueWorkSource($logger);

        // Tick 1: rolls the chunk back, then reserves.
        self::assertSame(TickOutcome::WORK_DONE, $source->tickOne('test-corr-reserve-1'));

        $slotColumn = $this->fetchSlotColumnFor($fieldId);
        self::assertNotNull($slotColumn, 'The tick must reserve a slot for the unmapped filterable field.');
        self::assertSame('assigned', $this->fetchSlotStatusFor($fieldId), 'ADR 0007 reserves live, not backfilling.');
        self::assertSame(1, $this->countQueueRows(), 'The rolled-back chunk must leave the queue row claimable.');

        // Tick 2: the same rows now backfill against the new slot.
        self::assertSame(TickOutcome::WORK_DONE, $source->tickOne('test-corr-reserve-2'));
        self::assertSame(0, $this->countQueueRows(), 'The second tick must drain the queue.');
        self::assertSame(0, $this->countDlqRows());

        $names = $this->eventNames($stream);
        self::assertContains('slot_reserved', $names, 'The reservation is recorded by slot_reserved.');
        self::assertContains('chunk_complete', $names);
        self::assertNotContains(
            'capacity_wait',
            $names,
            'A wait that the tick itself resolved must not be reported as capacity_wait.',
        );

        // The value actually landed in the slot column.
        $table = $this->pageTableFor($fieldId);
        $stmt = $this->pdo->prepare("SELECT {$slotColumn} FROM {$table} WHERE entry_id = ?");
        $stmt->execute([$entryId]);
        self::assertSame('hello', $stmt->fetchColumn());
    }

    /**
     * The gauge the ADR 0007 reservation exists to fix.
     * `PendingDemandReader` counted this field forever, because no
     * production path reserved for a plain unmapped filterable field.
     */
    public function testPendingDemandDrainsAfterTheReservation(): void
    {
        $this->provisionPageWithIndexedFamily('str');
        $modelId = $this->createModel(1);
        $this->createField($modelId, 'string', true, 'gauge_field');
        $this->seedEntry(1, $modelId, ['gauge_field' => 'x']);

        $reader = new PendingDemandReader($this->pdo);
        self::assertSame(1, $reader->read()->totalWaiters(), 'Sanity: the field is demand before the tick.');

        $this->makeSyncQueueWorkSource()->tickOne('test-corr-demand');

        self::assertSame(0, $reader->read()->totalWaiters(), 'pending_demand must be self-draining.');
    }

    /**
     * ADR 0004: the reservation requires an *indexed* slot. A page with
     * free slots but no composite index cannot satisfy it, so the tick
     * still waits for the Watcher — which is also the correct
     * behaviour on a deployment whose pages predate the Watcher's
     * index-aware provisioning (ADR 0035).
     */
    public function testUnindexedFreeCapacityDoesNotSatisfyTheReservation(): void
    {
        $this->provisionPage();
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'needs_index');

        $this->seedEntry(1, $modelId, ['needs_index' => 'x']);

        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $logger = new StdoutNdjsonLogger(new SystemClock(), $stream);

        $outcome = $this->makeSyncQueueWorkSource($logger)->tickOne('test-corr-unindexed');

        self::assertSame(TickOutcome::CAPACITY_WAIT, $outcome);
        self::assertNull(
            $this->fetchSlotColumnFor($fieldId),
            'An unindexed free slot must not be reserved — ADR 0004 fail-fast.',
        );
        self::assertSame(1, $this->countQueueRows());
        self::assertContains('capacity_wait', $this->eventNames($stream));
    }

    /** @param resource $stream */
    private function eventNames($stream): array
    {
        rewind($stream);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($stream))));

        return array_map(
            static fn (string $l) => json_decode($l, true, flags: JSON_THROW_ON_ERROR)['event'] ?? null,
            $lines,
        );
    }

    private function fetchSlotColumnFor(int $fieldId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT slot_column FROM stardust_slot_assignments'
            . " WHERE field_id = ? AND status IN ('assigned','backfilling','ready')"
        );
        $stmt->execute([$fieldId]);
        $column = $stmt->fetchColumn();

        return $column === false ? null : (string) $column;
    }

    private function fetchSlotStatusFor(int $fieldId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT status FROM stardust_slot_assignments'
            . " WHERE field_id = ? AND status IN ('assigned','backfilling','ready')"
        );
        $stmt->execute([$fieldId]);
        $status = $stmt->fetchColumn();

        return $status === false ? null : (string) $status;
    }

    private function pageTableFor(int $fieldId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.table_name FROM stardust_pages p'
            . ' JOIN stardust_slot_assignments a ON a.page_id = p.id'
            . ' WHERE a.field_id = ?'
        );
        $stmt->execute([$fieldId]);

        return (string) $stmt->fetchColumn();
    }

    private function countLiveSlots(): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM stardust_slot_assignments'
            . " WHERE field_id IS NOT NULL AND status IN ('assigned','backfilling','ready')"
        )->fetchColumn();
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
