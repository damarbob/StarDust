<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Retype;

use StarDust\Tests\Smoke\Phase6bTestCase;

/**
 * End-to-end tests for the Reconciler's retype backfill drain.
 *
 * Covers exit criteria:
 *   #3 read fallback to JSON_EXTRACT while the slot is `backfilling`;
 *   #4 ADR 0024 coercion matrix exercised against real entry_data;
 *   #5 idempotent resume after a mid-chunk interrupt;
 *   #8 promotion to `ready` + post-backfill `cardinality_sampled`.
 */
final class RetypeBackfillTest extends Phase6bTestCase
{
    public function testStringToIntBackfillCoercesAndStoresNullForInvalid(): void
    {
        // The subject field is filterable, so the retype's replacement
        // slot must be indexed (ADR 0016 commitment 1) — index the
        // i_int_01 column the string → int retype will land on.
        $this->provisionPage(['i_int_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'value');
        $this->reserveSlotFor($fieldId);

        // Seed four entries via real write path: two coercible, two not.
        $e1 = $this->seedEntry(1, $modelId, ['value' => '42']);
        $e2 = $this->seedEntry(1, $modelId, ['value' => '-1']);
        $e3 = $this->seedEntry(1, $modelId, ['value' => 'hello']);
        $e4 = $this->seedEntry(1, $modelId, ['value' => '3.5']);

        // Retype string → int.
        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: 'int',
            newIsFilterable: null,
        );

        $newSlot = $this->fetchLiveSlotForField($fieldId);
        self::assertNotNull($newSlot);
        $newSlotColumn = (string) $newSlot['slot_column'];
        $tableName = $this->pageTableNameFor((int) $newSlot['page_id']);

        // Drive backfill to completion.
        $logger = $this->makeRecordingLogger();
        $this->makeRetypeBackfillWorkSource($logger)->tickOne('test-correlation');
        $records = $logger->records();

        // Coerced values present in the new int slot column.
        self::assertSame('42', (string) $this->fetchSlotValue($tableName, $e1, $newSlotColumn));
        self::assertSame('-1', (string) $this->fetchSlotValue($tableName, $e2, $newSlotColumn));
        // Uncoercible values write NULL.
        self::assertNull($this->fetchSlotValue($tableName, $e3, $newSlotColumn));
        self::assertNull($this->fetchSlotValue($tableName, $e4, $newSlotColumn));

        // Exactly two coercion_null events fired.
        $nullEvents = $this->recordsWithEvent($records, 'coercion_null');
        self::assertCount(2, $nullEvents);

        $reasons = array_map(static fn ($r) => $r['context']['reason'], $nullEvents);
        sort($reasons);
        self::assertSame(['unparseable', 'unparseable'], $reasons);

        // After the final chunk: slot promoted, checkpoint completed,
        // promote_to_ready emitted, cardinality_sampled fired with
        // trigger=post_backfill.
        $slotAfter = $this->fetchSlotAssignment((int) $newSlot['id']);
        self::assertSame('ready', $slotAfter['status']);

        $cp = $this->fetchCheckpointForField($fieldId);
        self::assertSame('completed', $cp['status']);

        self::assertCount(1, $this->recordsWithEvent($records, 'promote_to_ready'));
        $cardinality = $this->recordsWithEvent($records, 'cardinality_sampled');
        self::assertNotEmpty($cardinality);
        self::assertSame('post_backfill', $cardinality[0]['context']['trigger']);

        // ADR 0031 post-relocation one-shot. A retype can move the model
        // onto a page it did not previously occupy, so the spread delta
        // is published as soon as the relocation completes rather than
        // waiting up to a day for the periodic sample.
        $spread = $this->recordsWithEvent($records, 'spread_sampled');
        self::assertNotEmpty($spread, 'A completed relocation must publish a spread sample.');
        self::assertSame('post_relocation', $spread[0]['context']['trigger']);
        self::assertSame(1, $spread[0]['context']['tenant_id']);
        self::assertSame($modelId, $spread[0]['context']['model_id']);
    }

    /**
     * The one-shot fires only when the relocation actually completes.
     * A retype still mid-backfill has not moved anything a query can
     * see, so publishing a spread delta then would be premature.
     */
    public function testNoSpreadSampleUntilThePromotionCommits(): void
    {
        $this->provisionPage(['i_int_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'value');
        $this->reserveSlotFor($fieldId);
        $this->seedEntry(1, $modelId, ['value' => '42']);

        $logger = $this->makeRecordingLogger();
        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: 'int',
            newIsFilterable: null,
        );

        // Initiation alone — no chunk ticked, so no promotion.
        self::assertSame([], $this->recordsWithEvent($logger->records(), 'spread_sampled'));
    }

    public function testReadDuringBackfillFallsBackToJsonPayload(): void
    {
        $this->provisionPage(['i_int_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'name');
        $this->reserveSlotFor($fieldId);

        $entryId = $this->seedEntry(1, $modelId, ['name' => 'Acme']);

        // Initiate retype string → int. We do NOT run the backfill —
        // the slot stays in `backfilling`.
        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: 'int',
            newIsFilterable: null,
        );

        $newSlot = $this->fetchLiveSlotForField($fieldId);
        self::assertNotNull($newSlot);
        self::assertSame('backfilling', $newSlot['status']);

        // Point read: the field comes back from the JSON payload
        // (ResultAssembler's JSON fallback for non-assigned/ready
        // statuses, satisfied per Phase 4 exit criterion).
        $entry = $this->reader()->get(1, $entryId);
        self::assertNotNull($entry);
        self::assertSame('Acme', $entry->fields['name']);
    }

    public function testIdempotentResumeAfterChunkSizeBoundary(): void
    {
        $this->provisionPage(['i_int_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'value');
        $this->reserveSlotFor($fieldId);

        // Seed 5 entries (more than our chunk size of 2 so we'll need
        // multiple ticks).
        $ids = [];
        foreach (['10', '20', '30', '40', '50'] as $v) {
            $ids[] = $this->seedEntry(1, $modelId, ['value' => $v]);
        }

        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: 'int',
            newIsFilterable: null,
        );

        $newSlot = $this->fetchLiveSlotForField($fieldId);
        $tableName = $this->pageTableNameFor((int) $newSlot['page_id']);
        $col = (string) $newSlot['slot_column'];

        $workSource = $this->makeRetypeBackfillWorkSource(chunkSize: 2);

        // Tick 1: processes entries 1-2.
        $workSource->tickOne('cor1');
        $cp = $this->fetchCheckpointForField($fieldId);
        self::assertSame('running', $cp['status']);
        self::assertSame($ids[1], (int) $cp['last_processed_id']);
        // Slot still backfilling — not final chunk.
        self::assertSame('backfilling', $this->fetchSlotAssignment((int) $newSlot['id'])['status']);

        // Tick 2: entries 3-4.
        $workSource->tickOne('cor2');
        $cp = $this->fetchCheckpointForField($fieldId);
        self::assertSame('running', $cp['status']);
        self::assertSame($ids[3], (int) $cp['last_processed_id']);

        // Tick 3: entry 5 (only 1 row processed < chunkSize=2 → final).
        $workSource->tickOne('cor3');
        $cp = $this->fetchCheckpointForField($fieldId);
        self::assertSame('completed', $cp['status']);
        self::assertSame('ready', $this->fetchSlotAssignment((int) $newSlot['id'])['status']);

        // Every row got its coerced value (idempotent UPSERT — no
        // double-writes).
        $expected = [10, 20, 30, 40, 50];
        foreach ($ids as $i => $entryId) {
            self::assertSame($expected[$i], (int) $this->fetchSlotValue($tableName, $entryId, $col));
        }
    }

    public function testSchemaVersionBumpedAtInitiationAndAgainAtPromotion(): void
    {
        $this->provisionPage(['i_int_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'value');
        $this->reserveSlotFor($fieldId);

        $this->seedEntry(1, $modelId, ['value' => '42']);

        $v0 = $this->fetchSchemaVersion();
        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: 'int',
            newIsFilterable: null,
        );
        $v1 = $this->fetchSchemaVersion();
        self::assertGreaterThan($v0, $v1);

        $this->makeRetypeBackfillWorkSource()->tickOne('cor-promote');
        $v2 = $this->fetchSchemaVersion();
        self::assertGreaterThan($v1, $v2);
    }
}
