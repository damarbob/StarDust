<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Liberator;

use StarDust\Clock\SystemClock;
use StarDust\Logging\StdoutNdjsonLogger;
use StarDust\Tests\Smoke\Phase6aTestCase;

/**
 * Phase 6a exit-criterion #1 + #4: nullification → reclaim → schema
 * version bump, all atomic with the final chunk.
 */
final class LiberatorSweepTest extends Phase6aTestCase
{
    public function testHappyPathSweepNullifiesAndReclaims(): void
    {
        [$modelId, $fieldId, $pageId, $_fieldName] = $this->setupModelWithReservedField(1, 'string');
        $tableName = $this->pageTableNameFor($pageId);
        $slotAssignmentId = $this->slotAssignmentIdFor($fieldId);
        $slotColumn = $this->slotColumnFor($slotAssignmentId);

        $this->seedSlotValues($modelId, $tableName, $slotColumn, 10, 'before-sweep');
        $this->tombstoneSlotAssignment($slotAssignmentId);

        $versionBefore = $this->fetchSchemaVersion();

        // chunk size 500 → all 10 rows fit in one chunk; the same tx
        // also transitions to free and bumps schema_version.
        $this->makeLiberator(chunkSize: 500)->tick();

        self::assertSame(0, $this->countNonNullValues($tableName, $slotColumn));

        $row = $this->fetchSlotAssignment($slotAssignmentId);
        self::assertSame('free', $row['status']);
        self::assertNull($row['field_id']);
        self::assertSame(0, (int) $row['sweep_gap_count']);

        self::assertGreaterThan($versionBefore, $this->fetchSchemaVersion(),
            'tombstoned → free transition must bump stardust_schema_version in same tx (ADR 0017 §4.6).');
    }

    public function testMultiChunkSweep(): void
    {
        [$modelId, $fieldId, $pageId, $_fieldName] = $this->setupModelWithReservedField(1, 'string');
        $tableName = $this->pageTableNameFor($pageId);
        $slotAssignmentId = $this->slotAssignmentIdFor($fieldId);
        $slotColumn = $this->slotColumnFor($slotAssignmentId);

        // 25 rows over chunkSize=10 → 3 chunks (10/10/5). Final chunk
        // reclaims the slot.
        $this->seedSlotValues($modelId, $tableName, $slotColumn, 25);
        $this->tombstoneSlotAssignment($slotAssignmentId);

        $this->makeLiberator(chunkSize: 10)->tick();

        self::assertSame(0, $this->countNonNullValues($tableName, $slotColumn));
        self::assertSame('free', $this->fetchSlotAssignment($slotAssignmentId)['status']);
    }

    public function testEmptyPartitionImmediateReclaim(): void
    {
        [$_modelId, $fieldId, $_pageId, $_fieldName] = $this->setupModelWithReservedField(1, 'string');
        $slotAssignmentId = $this->slotAssignmentIdFor($fieldId);

        // No entry_data + no slot rows for this slot → the first SELECT
        // returns 0 rows, isLast=true, immediate reclaim.
        $this->tombstoneSlotAssignment($slotAssignmentId);

        $this->makeLiberator(chunkSize: 500)->tick();

        self::assertSame('free', $this->fetchSlotAssignment($slotAssignmentId)['status']);
    }

    public function testSweepCommitsCursorPerChunk(): void
    {
        [$modelId, $fieldId, $pageId, $_fieldName] = $this->setupModelWithReservedField(1, 'string');
        $tableName = $this->pageTableNameFor($pageId);
        $slotAssignmentId = $this->slotAssignmentIdFor($fieldId);
        $slotColumn = $this->slotColumnFor($slotAssignmentId);

        // 30 rows, chunkSize=10 → after 2 chunks cursor should sit at
        // the 20th entry_id and still be in `tombstoned` because more
        // rows remain. Use a sweeper that processes a single tick.
        $entryIds = $this->seedSlotValues($modelId, $tableName, $slotColumn, 30);
        $this->tombstoneSlotAssignment($slotAssignmentId);

        $this->makeLiberator(chunkSize: 10)->tick();

        $row = $this->fetchSlotAssignment($slotAssignmentId);
        // Final chunk transitions to free; cursor is the 30th entry id.
        self::assertSame('free', $row['status']);
        self::assertSame($entryIds[29], (int) $row['sweep_cursor_id']);
    }

    public function testEmitsExpectedEventSequence(): void
    {
        [$modelId, $fieldId, $pageId, $_fieldName] = $this->setupModelWithReservedField(1, 'string');
        $tableName = $this->pageTableNameFor($pageId);
        $slotAssignmentId = $this->slotAssignmentIdFor($fieldId);
        $slotColumn = $this->slotColumnFor($slotAssignmentId);

        $this->seedSlotValues($modelId, $tableName, $slotColumn, 25);
        $this->tombstoneSlotAssignment($slotAssignmentId);

        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $logger = new StdoutNdjsonLogger(new SystemClock(), $stream);

        // chunkSize=10 → 3 chunks (sweep_chunk × 3) + sweep_started + sweep_complete.
        $this->makeLiberator(logger: $logger, chunkSize: 10)->tick();

        $events = $this->readNdjsonStream($stream);
        $names = array_map(static fn ($e) => $e['event'] ?? null, $events);

        self::assertSame(
            ['sweep_started', 'sweep_chunk', 'sweep_chunk', 'sweep_chunk', 'sweep_complete'],
            $names,
        );

        $correlationId = $events[0]['correlation_id'] ?? null;
        self::assertIsString($correlationId);
        foreach ($events as $event) {
            self::assertSame('liberator', $event['source'] ?? null);
            self::assertSame($correlationId, $event['correlation_id'] ?? null);
        }
    }

    public function testIdleTickEmitsNoEvents(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertNotFalse($stream);
        $logger = new StdoutNdjsonLogger(new SystemClock(), $stream);

        $this->makeLiberator(logger: $logger)->tick();

        self::assertSame([], $this->readNdjsonStream($stream), 'Idle path must emit no events (blueprint AC#13).');
    }

    private function slotAssignmentIdFor(int $fieldId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM stardust_slot_assignments WHERE field_id = ?');
        $stmt->execute([$fieldId]);
        return (int) $stmt->fetchColumn();
    }

    private function slotColumnFor(int $slotAssignmentId): string
    {
        $stmt = $this->pdo->prepare('SELECT slot_column FROM stardust_slot_assignments WHERE id = ?');
        $stmt->execute([$slotAssignmentId]);
        return (string) $stmt->fetchColumn();
    }
}
