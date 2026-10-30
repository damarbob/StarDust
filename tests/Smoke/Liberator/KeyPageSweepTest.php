<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Liberator;

use StarDust\Tests\Smoke\Phase6bTestCase;

/**
 * Reclaiming a string slot on a key page.
 *
 * The Liberator sweeps with `UPDATE ... SET slot = NULL`, and the server
 * recomputes the generated key from the new slot value. These tests drive the
 * whole lifecycle, demote, sweep, promote, backfill, because a key that
 * survived a sweep would not be a visible failure. It would be a second
 * field's lookups finding the first field's old values.
 */
final class KeyPageSweepTest extends Phase6bTestCase
{
    public function testASweepClearsTheKeyWithTheSlotAndTheNextOccupantGetsItsOwn(): void
    {
        // One indexed string slot, so both occupants share the same column.
        $pageId    = $this->provisionPage(['i_str_01']);
        $tableName = $this->pageTableNameFor($pageId);
        $modelId   = $this->createModel(1);

        $alpha = $this->createField($modelId, 'string', true, 'alpha');
        $this->reserveSlotFor($alpha);
        $beta = $this->createField($modelId, 'string', false, 'beta');

        $entryIds = [];
        for ($i = 1; $i <= 20; $i++) {
            $entryIds[$i] = $this->seedEntry(1, $modelId, ['alpha' => "a{$i}", 'beta' => "b{$i}"]);
        }

        $slot = $this->fetchLiveSlotForField($alpha);
        self::assertNotNull($slot);
        self::assertSame('i_str_01', $slot['slot_column']);
        self::assertSame(20, $this->countNonNullValues($tableName, 'i_str_01'));
        self::assertSame(20, $this->countNonNullValues($tableName, 'i_str_01_k'));
        self::assertSame([$entryIds[7]], $this->entryIdsFoundByKey($pageId, 'i_str_01', 'a7'));

        // First life ends. The sweep nulls the slot, so the key follows.
        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: $alpha,
            newDeclaredType: null,
            newIsFilterable: false,
        );
        $this->makeLiberator()->tick();

        self::assertSame('free', $this->fetchSlotAssignment((int) $slot['id'])['status']);
        self::assertSame(0, $this->countNonNullValues($tableName, 'i_str_01'));
        self::assertSame(0, $this->countNonNullValues($tableName, 'i_str_01_k'), 'A swept slot must not leave its keys behind.');

        // Second life starts on the same physical slot.
        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: $beta,
            newDeclaredType: null,
            newIsFilterable: true,
        );
        $recycled = $this->fetchLiveSlotForField($beta);
        self::assertNotNull($recycled);
        self::assertSame((int) $slot['id'], (int) $recycled['id'], 'Fixture precondition: beta must recycle the slot.');

        $this->makeRetypeBackfillWorkSource()->tickOne('cor');

        self::assertSame(20, $this->countNonNullValues($tableName, 'i_str_01_k'));
        self::assertSame([$entryIds[7]], $this->entryIdsFoundByKey($pageId, 'i_str_01', 'b7'));
        self::assertSame([], $this->entryIdsFoundByKey($pageId, 'i_str_01', 'a7'), 'The previous occupant\'s values must not be findable.');
    }
}
