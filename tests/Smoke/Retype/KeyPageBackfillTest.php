<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Retype;

use StarDust\Tests\Smoke\Phase6bTestCase;

/**
 * Backfills that land a string field on a key page.
 *
 * A backfill writes the slot and the server fills the key, so the interesting
 * cases are the ones that cross page shapes: a promotion onto a key page, and a
 * relocation from a prefix page to a key page. A deployment keeps both shapes
 * for good, and compaction is the operation that moves fields between pages.
 */
final class KeyPageBackfillTest extends Phase6bTestCase
{
    public function testAPromotionBackfillFillsTheKeyOnAKeyPage(): void
    {
        $pageId    = $this->provisionPage(['i_str_01']);
        $tableName = $this->pageTableNameFor($pageId);
        $modelId   = $this->createModel(1);
        $fieldId   = $this->createField($modelId, 'string', false, 'code');

        $entryIds = [];
        foreach (['A-1', 'B-2', 'C-3'] as $value) {
            $entryIds[$value] = $this->seedEntry(1, $modelId, ['code' => $value]);
        }
        self::assertSame(0, $this->countNonNullValues($tableName, 'i_str_01_k'), 'Not filterable yet, so no slot and no key.');

        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: null,
            newIsFilterable: true,
        );
        $this->makeRetypeBackfillWorkSource()->tickOne('promotion');

        self::assertSame(3, $this->countNonNullValues($tableName, 'i_str_01_k'));
        self::assertSame([$entryIds['B-2']], $this->entryIdsFoundByKey($pageId, 'i_str_01', 'b-2'));
    }

    /**
     * The compaction building block across shapes. The replacement slot is on
     * the only page with a free indexed string slot, which is the key page, so
     * the values have to arrive there with their keys and still be found.
     */
    public function testARelocationFromAPrefixPageToAKeyPageKeepsEveryValueFindable(): void
    {
        $prefixPage = $this->provisionPrefixPage(['i_str_01']);
        $modelId    = $this->createModel(1);
        $fieldId    = $this->createField($modelId, 'string', true, 'code');
        $this->reserveSlotFor($fieldId);
        $keyPage    = $this->provisionPage(['i_str_01']);

        $entryIds = [];
        foreach (['A-1', 'B-2', 'C-3'] as $value) {
            $entryIds[$value] = $this->seedEntry(1, $modelId, ['code' => $value]);
        }
        self::assertSame($prefixPage, (int) $this->fetchLiveSlotForField($fieldId)['page_id']);

        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: 'string',
            newIsFilterable: null,
        );
        $this->makeRetypeBackfillWorkSource()->tickOne('relocation');

        $moved = $this->fetchLiveSlotForField($fieldId);
        self::assertNotNull($moved);
        self::assertSame($keyPage, (int) $moved['page_id'], 'Fixture precondition: the field must have crossed shapes.');
        self::assertSame('ready', (string) $moved['status']);

        self::assertSame(3, $this->countNonNullValues($this->pageTableNameFor($keyPage), 'i_str_01_k'));
        foreach ($entryIds as $value => $entryId) {
            self::assertSame([$entryId], $this->entryIdsFoundByKey($keyPage, 'i_str_01', $value), $value);
        }
    }
}
