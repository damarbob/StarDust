<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Liberator;

use StarDust\Tests\Smoke\Phase6aTestCase;

/**
 * Phase 6a exit-criterion #4 (resumption half): restarting the
 * Liberator against a partially-swept slot resumes from
 * `sweep_cursor_id + 1`. Nullification is idempotent (`UPDATE … SET
 * col = NULL`), but the cursor checkpoint is what proves no double
 * work happens across the restart boundary.
 */
final class LiberatorResumeTest extends Phase6aTestCase
{
    public function testRestartResumesFromCommittedCursor(): void
    {
        [$modelId, $fieldId, $pageId, $_fieldName] = $this->setupModelWithReservedField(1, 'string');
        $tableName = $this->pageTableNameFor($pageId);
        $slotAssignmentId = $this->slotAssignmentIdFor($fieldId);
        $slotColumn = $this->slotColumnFor($slotAssignmentId);

        $entryIds = $this->seedSlotValues($modelId, $tableName, $slotColumn, 20);
        $this->tombstoneSlotAssignment($slotAssignmentId);

        // Pre-set the cursor to the 10th entry id, mimicking a crashed
        // sweep that already drained rows 1–10. The cursor commits in
        // the same tx as the data DML, so this state is what a real
        // restart would observe.
        $tenthId = $entryIds[9];
        $stmt = $this->pdo->prepare(
            'UPDATE stardust_slot_assignments SET sweep_cursor_id = ? WHERE id = ?'
        );
        $stmt->execute([$tenthId, $slotAssignmentId]);

        // Manually null the first 10 to mirror the crashed sweep's
        // committed work — the helper to do this is just direct SQL.
        $placeholders = implode(',', array_fill(0, 10, '?'));
        $stmt = $this->pdo->prepare(
            "UPDATE {$tableName} SET {$slotColumn} = NULL WHERE entry_id IN ({$placeholders})"
        );
        $stmt->execute(array_slice($entryIds, 0, 10));

        // Restart: chunk=500 → resumes from id > 10, processes ids
        // 11–20 in one chunk, then a follow-up empty chunk closes
        // the sweep.
        $this->makeLiberator(chunkSize: 500)->tick();

        self::assertSame(0, $this->countNonNullValues($tableName, $slotColumn));
        $row = $this->fetchSlotAssignment($slotAssignmentId);
        self::assertSame('free', $row['status']);
        self::assertSame($entryIds[19], (int) $row['sweep_cursor_id']);
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
