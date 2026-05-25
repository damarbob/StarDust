<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Liberator;

use StarDust\Tests\Smoke\Phase6aTestCase;

/**
 * Phase 6a exit-criterion #6: `tombstoned_at ASC, page_id, slot_column`
 * processing order. Restart against unchanged registry state yields
 * the same sequence (the Liberator is deterministic).
 */
final class LiberatorOrderingTest extends Phase6aTestCase
{
    public function testSweepBatchOrderedByTombstonedAtThenPageThenColumn(): void
    {
        // Three slots on one page, three different tombstoned_at
        // timestamps. The repository must surface them oldest-first.
        $pageId = $this->provisionPage();
        $modelId = $this->createModel(1);

        $fieldA = $this->createField($modelId, 'string', false, 'a');
        $fieldB = $this->createField($modelId, 'string', false, 'b');
        $fieldC = $this->createField($modelId, 'string', false, 'c');
        $this->reserveSlotFor($fieldA);
        $this->reserveSlotFor($fieldB);
        $this->reserveSlotFor($fieldC);

        $slotA = $this->slotAssignmentIdFor($fieldA);
        $slotB = $this->slotAssignmentIdFor($fieldB);
        $slotC = $this->slotAssignmentIdFor($fieldC);

        $this->tombstoneSlotAssignment($slotA);
        $this->tombstoneSlotAssignment($slotB);
        $this->tombstoneSlotAssignment($slotC);

        // Set explicit tombstoned_at: C oldest, then A, then B.
        $this->setTombstonedAt($slotC, '2026-01-01 00:00:00');
        $this->setTombstonedAt($slotA, '2026-02-01 00:00:00');
        $this->setTombstonedAt($slotB, '2026-03-01 00:00:00');

        $batch = $this->makeTombstonedSlotRepository()->loadBatch();
        $orderedIds = array_map(static fn ($s) => $s->slotAssignmentId, $batch);

        self::assertSame([$slotC, $slotA, $slotB], $orderedIds);

        // Sanity: page_id is identical across the three, so the
        // tie-break path falls through to slot_column. Verify by
        // seeding three slots with identical tombstoned_at and
        // observing column-name ordering.
        $sharedTs = '2026-04-01 00:00:00';
        $this->setTombstonedAt($slotA, $sharedTs);
        $this->setTombstonedAt($slotB, $sharedTs);
        $this->setTombstonedAt($slotC, $sharedTs);

        $batch = $this->makeTombstonedSlotRepository()->loadBatch();
        $columns = array_map(static fn ($s) => $s->slotColumn, $batch);
        $sorted = $columns;
        sort($sorted);
        self::assertSame($sorted, $columns, 'Equal tombstoned_at must tie-break by slot_column ASC.');
    }

    private function slotAssignmentIdFor(int $fieldId): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM stardust_slot_assignments WHERE field_id = ?');
        $stmt->execute([$fieldId]);
        return (int) $stmt->fetchColumn();
    }
}
