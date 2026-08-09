<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Retype;

use StarDust\Tests\Smoke\Phase6bTestCase;

/**
 * Phase 6b exit criterion #6 — promoting a field from
 * `is_filterable = false` to `is_filterable = true`.
 *
 * The pipeline:
 *   - a grandfathered (pre-ADR-0034) unindexed slot, if the field
 *     still carries one, tombstones;
 *   - new slot must land on an indexed `(tenant_id, slot_column)`
 *     column (PageProvisioner's `ix_<table>_<slot>` composite);
 *   - declared_type stays the same, so no coercion is attempted;
 *   - on promotion, filter queries against the field use the new
 *     slot's index.
 *
 * Both shapes are covered: the legacy one (field arrives holding an
 * unindexed slot) and the ADR 0034 normal one (a non-filterable field
 * is JSON-only, so promotion has no old slot to tombstone).
 */
final class RetypeFilterabilityPromotionTest extends Phase6bTestCase
{
    public function testPromotionOfGrandfatheredUnindexedSlotMovesToIndexedSlot(): void
    {
        // A non-filterable field holding a live unindexed slot is the
        // pre-ADR-0034 legacy shape — SlotReserver refuses to create
        // it now, so force it directly. Provision an unindexed page
        // first so the forced slot lands there, then a SECOND page
        // that DOES make i_str_01 indexed for the promotion to target.
        $unindexedPage = $this->provisionPage([]);                  // no indexed slots
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'name');
        $this->forceGrandfatheredSlotFor($fieldId);

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
        // Provision one unindexed page. The field is JSON-only and
        // holds no slot at all — the ADR 0034 normal shape for a
        // non-filterable field. We then promote: the initiator must
        // find no indexed free slot and defer (new slot reservation
        // returns null, field is left without a live slot).
        $this->provisionPage([]);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'name');

        $this->seedEntry(1, $modelId, ['name' => 'Acme']);

        $logger = $this->makeRecordingLogger();
        $this->makeRetypeInitiator($logger)->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: null,
            newIsFilterable: true,
        );

        // Field has NO live slot after initiation — it had none to
        // begin with, and the new one could not be reserved.
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
