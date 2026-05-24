<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use StarDust\Read\EntryQuery;

final class JsonExtractFallbackTest extends ReadPathTestCase
{
    public function testBackfillingFieldSourcesValueFromJsonPayloadNotSlot(): void
    {
        // Phase 4 exit criterion #6: a field in `backfilling` state
        // returns a value sourced via the JSON payload; the slot
        // column is not consulted.
        [$modelId, $fieldId, $pageId, $fieldName] = $this->setupFilterableStringField();
        $entryId = $this->seedEntry(1, $modelId, [$fieldName => 'true_value_in_json']);

        // Deliberately put a *different* value into the slot column
        // before flipping the status, so the assembler will pick the
        // wrong source if it consults the slot.
        $this->pdo->prepare(
            'UPDATE entry_slots_page_1 SET i_str_01 = ? WHERE entry_id = ?'
        )->execute(['wrong_value_in_slot', $entryId]);

        $this->pdo->prepare(
            'UPDATE stardust_slot_assignments SET status = ? WHERE field_id = ?'
        )->execute(['backfilling', $fieldId]);

        $page = $this->reader()->read(new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
            selectFields: [$fieldName],
        ));

        self::assertCount(1, $page->rows);
        self::assertSame('true_value_in_json', $page->rows[0]->fields[$fieldName]);
    }

    public function testTombstonedFieldSourcesValueFromJsonPayloadNotSlot(): void
    {
        [$modelId, $fieldId, , $fieldName] = $this->setupFilterableStringField();
        $entryId = $this->seedEntry(1, $modelId, [$fieldName => 'json_truth']);

        // Same drift as above but for the `tombstoned` state, which
        // also blocks slot reads per the schema reference state table.
        $this->pdo->prepare(
            'UPDATE entry_slots_page_1 SET i_str_01 = ? WHERE entry_id = ?'
        )->execute(['slot_after_tombstone', $entryId]);

        $this->pdo->prepare(
            'UPDATE stardust_slot_assignments SET status = ?, tombstoned_at = UTC_TIMESTAMP() WHERE field_id = ?'
        )->execute(['tombstoned', $fieldId]);

        $page = $this->reader()->read(new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
            selectFields: [$fieldName],
        ));

        self::assertCount(1, $page->rows);
        self::assertSame('json_truth', $page->rows[0]->fields[$fieldName]);
    }

    public function testUnmappedFieldStillReturnedFromJsonPayload(): void
    {
        // An unknown field on write is silently persisted in
        // entry_data.fields per ADR 0013. Reading it back via
        // selectFields requires the field to be registered, so we
        // create a field but DO NOT reserve a slot — its slot status
        // will be NULL (unmapped) which forces the JSON fallback.
        $pageId = $this->provisionPage();
        $modelId = $this->createModel(1);
        $unmappedField = 'description';
        $this->createField($modelId, 'string', false, $unmappedField);
        // (no reserveSlotFor — leaves the field unmapped)

        // Seed via the writer; PayloadSplitter will silently drop the
        // value into entry_data.fields only (no slot available).
        $this->seedEntry(1, $modelId, [$unmappedField => 'from_json_only']);

        $page = $this->reader()->read(new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
            selectFields: [$unmappedField],
        ));

        self::assertCount(1, $page->rows);
        self::assertSame('from_json_only', $page->rows[0]->fields[$unmappedField]);
    }
}
