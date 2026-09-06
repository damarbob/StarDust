<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Liberator;

use StarDust\Tests\Smoke\Phase6bTestCase;

/**
 * ADR 0045 — tombstoning resets a slot's sweep annotations.
 *
 * Before it, nothing in `src/` reset `sweep_cursor_id`: the value
 * survived `tombstoned -> free -> assigned -> tombstoned`, so a
 * recycled column's SECOND sweep began at `entry_id > <first sweep's
 * final cursor>`, every row at or below it kept the previous occupant's
 * values, and `sweep_complete` fired normally over the empty range.
 *
 * Driven entirely through the real lifecycle — demote, Liberator,
 * promote, backfill, demote, Liberator — so it exercises
 * `LiveSlotTombstoner` rather than the direct-SQL test helper. Note
 * that `Phase6aTestCase::tombstoneSlotAssignment()` mirrors the reset,
 * so a fixture built on the helper cannot reproduce the defect either;
 * the facade path is the point of this class.
 */
final class RecycledSlotSweepTest extends Phase6bTestCase
{
    public function testSecondSweepOfARecycledColumnNullifiesEveryRow(): void
    {
        // One indexed string column on the page, so both occupants are
        // forced onto the same physical slot.
        $pageId    = $this->provisionPage(['i_str_01']);
        $tableName = $this->pageTableNameFor($pageId);
        $modelId   = $this->createModel(1);

        $fieldA = $this->createField($modelId, 'string', true, 'alpha');
        $this->reserveSlotFor($fieldA);
        $fieldB = $this->createField($modelId, 'string', false, 'beta');

        // Entries carry both fields: alpha materialises into i_str_01,
        // beta lives in the JSON payload until it is promoted.
        $entryIds = [];
        for ($i = 1; $i <= 20; $i++) {
            $entryIds[] = $this->seedEntry(1, $modelId, [
                'alpha' => "a{$i}",
                'beta'  => "b{$i}",
            ]);
        }

        $slotA = $this->fetchLiveSlotForField($fieldA);
        self::assertNotNull($slotA);
        self::assertSame('i_str_01', $slotA['slot_column']);
        $slotAssignmentId = (int) $slotA['id'];
        self::assertSame(20, $this->countNonNullValues($tableName, 'i_str_01'));

        // --- First life: demote alpha, sweep the column clean. -------
        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: $fieldA,
            newDeclaredType: null,
            newIsFilterable: false,
        );
        $this->makeLiberator()->tick();

        $afterFirst = $this->fetchSlotAssignment($slotAssignmentId);
        self::assertSame('free', $afterFirst['status']);
        self::assertSame(0, $this->countNonNullValues($tableName, 'i_str_01'));
        self::assertSame(
            $entryIds[19],
            (int) $afterFirst['sweep_cursor_id'],
            'The first sweep should have walked to the last entry id.'
        );

        // --- Second life: promote beta onto the SAME slot. -----------
        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: $fieldB,
            newDeclaredType: null,
            newIsFilterable: true,
        );
        $slotB = $this->fetchLiveSlotForField($fieldB);
        self::assertNotNull($slotB);
        self::assertSame(
            $slotAssignmentId,
            (int) $slotB['id'],
            'Fixture precondition: beta must recycle alphas slot, or this test is vacuous.'
        );

        $this->makeRetypeBackfillWorkSource()->tickOne('cor');
        self::assertSame(
            20,
            $this->countNonNullValues($tableName, 'i_str_01'),
            'Fixture precondition: the promotion backfill must have filled the recycled column.'
        );

        // --- Second death: demote beta, sweep again. ----------------
        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: $fieldB,
            newDeclaredType: null,
            newIsFilterable: false,
        );
        $this->makeLiberator()->tick();

        $afterSecond = $this->fetchSlotAssignment($slotAssignmentId);
        self::assertSame('free', $afterSecond['status']);
        self::assertSame(
            0,
            $this->countNonNullValues($tableName, 'i_str_01'),
            'A reclaimed slot must hold no data from its previous occupant.'
        );
    }

    /**
     * The annotation lifecycle in isolation: cleared by the tombstone,
     * advanced by the sweep, preserved across the reclaim.
     *
     * The preservation half is what makes the reset belong on the
     * tombstone rather than on the reclaim — an operator reading a
     * `free` or re-reserved slot still sees the gap count of the sweep
     * that produced it.
     */
    public function testTombstoneClearsAnnotationsAndTheReclaimPreservesThem(): void
    {
        $pageId    = $this->provisionPage(['i_str_01']);
        $tableName = $this->pageTableNameFor($pageId);
        $modelId   = $this->createModel(1);

        $fieldId = $this->createField($modelId, 'string', true, 'alpha');
        $this->reserveSlotFor($fieldId);
        $entryIds = [];
        for ($i = 1; $i <= 5; $i++) {
            $entryIds[] = $this->seedEntry(1, $modelId, ['alpha' => "a{$i}"]);
        }

        $slotAssignmentId = (int) $this->fetchLiveSlotForField($fieldId)['id'];

        // Dirty both annotations on the live slot, as a previous
        // occupant's sweep would have left them.
        $stmt = $this->pdo->prepare(
            'UPDATE stardust_slot_assignments'
            . ' SET sweep_cursor_id = ?, sweep_gap_count = 7 WHERE id = ?'
        );
        $stmt->execute([$entryIds[4], $slotAssignmentId]);

        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: null,
            newIsFilterable: false,
        );

        $tombstoned = $this->fetchSlotAssignment($slotAssignmentId);
        self::assertSame('tombstoned', $tombstoned['status']);
        self::assertNull(
            $tombstoned['sweep_cursor_id'],
            'The tombstone must clear the cursor, or the sweep starts mid-page.'
        );
        self::assertSame(
            0,
            (int) $tombstoned['sweep_gap_count'],
            'The gap count describes one sweep, so it resets with the cursor.'
        );

        $this->makeLiberator()->tick();

        $reclaimed = $this->fetchSlotAssignment($slotAssignmentId);
        self::assertSame('free', $reclaimed['status']);
        self::assertSame(0, $this->countNonNullValues($tableName, 'i_str_01'));
        self::assertSame(
            $entryIds[4],
            (int) $reclaimed['sweep_cursor_id'],
            'The reclaim preserves the annotations of the sweep that produced it.'
        );
    }
}
