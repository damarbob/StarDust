<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Write;

use Psr\Log\NullLogger;
use StarDust\Clock\SystemClock;
use StarDust\Logging\StdoutNdjsonLogger;
use StarDust\Tests\Smoke\WritePathTestCase;
use StarDust\Write\EntryPayload;
use StarDust\Write\EntryWriter;
use StarDust\Write\SlotRowUpserter;

/**
 * ADR 0034 — non-filterable fields are JSON-only.
 *
 * The exhaustion fallback of ADR 0007 exists to restore *indexed
 * queryability*, so it only makes sense for a field that can be a
 * filter target. A non-filterable field having no slot is the steady
 * state, not a degradation: it must never take a slot, never enqueue,
 * and never be materialized into a grandfathered legacy column.
 *
 * The value is readable throughout — `entry_data.fields` is the system
 * of record (ADR 0013).
 */
final class PayloadSplitterFilterabilityTest extends WritePathTestCase
{
    private function newWriter(?StdoutNdjsonLogger $logger = null): EntryWriter
    {
        return new EntryWriter(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: $logger ?? new NullLogger(),
            slotRowUpserter: new SlotRowUpserter($this->pdo),
        );
    }

    private function countQueueRows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_sync_queue')->fetchColumn();
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchPayload(int $entryId): array
    {
        $stmt = $this->pdo->prepare('SELECT fields FROM entry_data WHERE id = ?');
        $stmt->execute([$entryId]);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $stmt->fetchColumn(), true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * Headline exit criterion: a JSON-only field produces no queue row,
     * and its value still round-trips out of the payload.
     */
    public function testNonFilterableSlotlessFieldDoesNotEnqueue(): void
    {
        $this->provisionPage();
        $modelId = $this->createModel(1);
        $this->createField($modelId, 'string', false, 'note');

        $result = $this->newWriter()->write(new EntryPayload(
            tenantId: 1,
            modelId: $modelId,
            fields: ['note' => 'just json'],
        ));

        self::assertFalse($result->enqueuedForBackfill);
        self::assertSame(0, $this->countQueueRows());
        self::assertSame('just json', $this->fetchPayload($result->entryId)['note']);
    }

    /** The ADR 0007 path must survive untouched for filterable fields. */
    public function testFilterableSlotlessFieldStillEnqueues(): void
    {
        $this->provisionPage();
        $modelId = $this->createModel(1);
        $this->createField($modelId, 'string', true, 'wants_slot');

        $result = $this->newWriter()->write(new EntryPayload(
            tenantId: 1,
            modelId: $modelId,
            fields: ['wants_slot' => 'x'],
        ));

        self::assertTrue($result->enqueuedForBackfill);
        self::assertSame(1, $this->countQueueRows());
    }

    /** One payload, both kinds of slotless field: exactly one enqueue. */
    public function testMixedPayloadEnqueuesOnlyForTheFilterableField(): void
    {
        $this->provisionPage();
        $modelId = $this->createModel(1);
        $this->createField($modelId, 'string', false, 'note');
        $this->createField($modelId, 'string', true, 'wants_slot');

        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);

        $result = $this->newWriter(new StdoutNdjsonLogger(new SystemClock(), $stream))
            ->write(new EntryPayload(
                tenantId: 1,
                modelId: $modelId,
                fields: ['note' => 'just json', 'wants_slot' => 'x'],
            ));

        self::assertTrue($result->enqueuedForBackfill);
        self::assertSame(1, $this->countQueueRows(), 'One entry enqueues at most one row.');

        rewind($stream);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($stream))));
        $names = array_map(
            static fn (string $l) => json_decode($l, true, flags: JSON_THROW_ON_ERROR)['event'] ?? null,
            $lines,
        );
        self::assertSame(
            1,
            count(array_filter($names, static fn ($n) => $n === 'exhaustion_fallback')),
        );

        // Both values remain readable from the payload.
        $payload = $this->fetchPayload($result->entryId);
        self::assertSame('just json', $payload['note']);
        self::assertSame('x', $payload['wants_slot']);
    }

    /**
     * A grandfathered pre-0034 slot is left alone: the write path sheds
     * the dead UPSERT into a column no query reads, and — critically —
     * skipping it must not leak the field into `missingSlotFields` and
     * re-create the unsatisfiable enqueue.
     */
    public function testGrandfatheredNonFilterableSlotIsNotWritten(): void
    {
        $pageId = $this->provisionPage();
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'legacy');
        $this->forceGrandfatheredSlotFor($fieldId);

        $slot = $this->pdo->prepare(
            'SELECT slot_column FROM stardust_slot_assignments WHERE field_id = ?'
        );
        $slot->execute([$fieldId]);
        $slotColumn = (string) $slot->fetchColumn();

        $result = $this->newWriter()->write(new EntryPayload(
            tenantId: 1,
            modelId: $modelId,
            fields: ['legacy' => 'stale-me-not'],
        ));

        self::assertFalse($result->enqueuedForBackfill);
        self::assertSame(0, $this->countQueueRows());

        $tableName = 'entry_slots_page_' . $pageId;
        $read = $this->pdo->prepare("SELECT {$slotColumn} FROM {$tableName} WHERE entry_id = ?");
        $read->execute([$result->entryId]);
        $stored = $read->fetchColumn();

        self::assertTrue(
            $stored === false || $stored === null,
            'A grandfathered non-filterable slot must not be materialized.',
        );

        self::assertSame('stale-me-not', $this->fetchPayload($result->entryId)['legacy']);
    }
}
