<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Retype;

use StarDust\Tests\Smoke\Phase6bTestCase;

/**
 * Phase 6b exit criterion #6 — promoting a field from
 * `is_filterable = false` to `is_filterable = true` on a field that
 * already has data on its current unindexed slot.
 *
 * The pipeline:
 *   - old slot (unindexed) tombstones;
 *   - new slot must land on an indexed `(tenant_id, slot_column)`
 *     column (PageProvisioner's `ix_<table>_<slot>` composite);
 *   - declared_type stays the same, so no coercion is attempted;
 *   - on promotion, filter queries against the field use the new
 *     slot's index.
 */
final class RetypeFilterabilityPromotionTest extends Phase6bTestCase
{
    public function testPromotionMovesFieldFromUnindexedToIndexedSlot(): void
    {
        // Page 1 has i_str_01 filterable but our field reserves the
        // NEXT free slot — to force the field onto an unindexed slot
        // we provision a page where the str slot we land on is NOT
        // indexed. Simplest: provision a single page with NO
        // filterable slots, reserve our field there, then provision
        // a SECOND page that DOES make i_str_01 indexed.
        $unindexedPage = $this->provisionPage([]);                  // no indexed slots
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'name');
        $this->reserveSlotFor($fieldId);

        // Confirm the field landed on the unindexed page.
        $oldSlot = $this->fetchLiveSlotForField($fieldId);
        self::assertNotNull($oldSlot);
        self::assertSame($unindexedPage, (int) $oldSlot['page_id']);

        // Seed entries via the real write path.
        $a = $this->seedEntry(1, $modelId, ['name' => 'Acme']);
        $b = $this->seedEntry(1, $modelId, ['name' => 'Beta']);

        // Provision a SECOND page with i_str_01 indexed — that's where
        // promotion will reserve the new slot.
        $indexedPage = $this->provisionPage(['i_str_01']);

        // Promote.
        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: null,
            newIsFilterable: true,
        );

        // Old slot tombstoned.
        $oldAfter = $this->fetchSlotAssignment((int) $oldSlot['id']);
        self::assertSame('tombstoned', $oldAfter['status']);

        // New slot on the indexed page.
        $newSlot = $this->fetchLiveSlotForField($fieldId);
        self::assertNotNull($newSlot);
        self::assertSame($indexedPage, (int) $newSlot['page_id']);
        self::assertSame('i_str_01', $newSlot['slot_column']);
        self::assertSame('backfilling', $newSlot['status']);

        // is_filterable now true on the field row.
        self::assertSame(1, (int) $this->fetchFieldRow($fieldId)['is_filterable']);

        // Source declared_type recorded as 'string' (== target).
        $cp = $this->fetchCheckpointForField($fieldId);
        self::assertSame('string', $cp['source_declared_type']);

        // Drive backfill. Identity coercion — values copy verbatim.
        $this->makeRetypeBackfillWorkSource()->tickOne('cor');

        $newTable = $this->pageTableNameFor($indexedPage);
        self::assertSame('Acme', $this->fetchSlotValue($newTable, $a, 'i_str_01'));
        self::assertSame('Beta', $this->fetchSlotValue($newTable, $b, 'i_str_01'));

        // Slot promoted to ready.
        self::assertSame('ready', $this->fetchSlotAssignment((int) $newSlot['id'])['status']);
    }

    public function testPromotionDefersWhenNoIndexedSlotAvailable(): void
    {
        // Provision one unindexed page. The field reserves a slot on
        // it. We then promote — the initiator must find no indexed
        // free slot and defer (new slot reservation returns null,
        // field is left without a live slot).
        $this->provisionPage([]);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'name');
        $this->reserveSlotFor($fieldId);

        $this->seedEntry(1, $modelId, ['name' => 'Acme']);

        $logger = $this->makeRecordingLogger();
        $this->makeRetypeInitiator($logger)->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: null,
            newIsFilterable: true,
        );

        // Field has NO live slot after initiation — old tombstoned,
        // new not reserved.
        self::assertNull($this->fetchLiveSlotForField($fieldId));

        // Checkpoint exists and is running.
        $cp = $this->fetchCheckpointForField($fieldId);
        self::assertSame('running', $cp['status']);

        // retype_started carries deferred_assignment = true.
        $started = $this->recordsWithEvent($logger->records(), 'retype_started');
        self::assertCount(1, $started);
        self::assertTrue($started[0]['context']['deferred_assignment']);
    }
}
