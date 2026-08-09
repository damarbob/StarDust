<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Retype;

use StarDust\Exception\FieldNotFilterableException;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Read\EntryQuery;
use StarDust\Reconciler\TickOutcome;
use StarDust\Tests\Smoke\Phase6bTestCase;

/**
 * ADR 0034 — a lifecycle whose target is non-filterable is
 * registry-only.
 *
 * A JSON-only field holds no slot, so there is nothing to materialize
 * and nothing to backfill: the transition completes inside the
 * initiation transaction. No replacement slot is reserved, no
 * `backfill_checkpoints` row is written, and the Reconciler has
 * nothing to claim afterwards.
 *
 * Two triggers land in this shape — a retype of an already
 * non-filterable field, and a `filterable → false` demotion (which
 * additionally tombstones the slot the field was holding, per ADR
 * 0034 §4's tombstone-and-done). Both must still bump
 * `stardust_schema_version` exactly once.
 */
final class RetypeNonFilterableTest extends Phase6bTestCase
{
    public function testRetypeOfNonFilterableFieldIsRegistryOnly(): void
    {
        $this->provisionPage(['i_int_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'note');

        $versionBefore = $this->fetchSchemaVersion();

        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: 'int',
            newIsFilterable: null,
        );

        self::assertSame('int', $this->fetchFieldRow($fieldId)['declared_type']);
        self::assertNull($this->fetchLiveSlotForField($fieldId), 'A JSON-only field must not be given a slot.');
        self::assertNull($this->fetchCheckpointForField($fieldId), 'No checkpoint: nothing to backfill.');
        self::assertSame(
            $versionBefore + 1,
            $this->fetchSchemaVersion(),
            'Exactly one schema-version bump for the whole tuple.',
        );
    }

    public function testRetypeOfNonFilterableFieldEmitsRetypeStartedWithBackfillNotRequired(): void
    {
        $this->provisionPage(['i_int_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'note');

        $logger = $this->makeRecordingLogger();
        $this->makeRetypeInitiator($logger)->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: 'int',
            newIsFilterable: null,
        );

        $started = $this->recordsWithEvent($logger->records(), 'retype_started');
        self::assertCount(1, $started);

        $context = $started[0]['context'];
        self::assertFalse($context['backfill_required']);
        self::assertNull($context['new_slot_assignment_id']);
        // Regression guard: a registry-only transition always leaves
        // $newSlot null, but it is complete — not deferred. Reporting
        // it as deferred would show a backlog that never drains.
        self::assertFalse($context['deferred_assignment']);
    }

    public function testRetypeOfNonFilterableFieldTombstonesGrandfatheredSlot(): void
    {
        $this->provisionPage(['i_int_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'legacy');
        $this->forceGrandfatheredSlotFor($fieldId);

        $oldSlot = $this->fetchLiveSlotForField($fieldId);
        self::assertNotNull($oldSlot);
        $oldSlotId = (int) $oldSlot['id'];

        $versionBefore = $this->fetchSchemaVersion();

        $logger = $this->makeRecordingLogger();
        $this->makeRetypeInitiator($logger)->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: 'int',
            newIsFilterable: null,
        );

        // The legacy slot decays through the ADR 0009 eviction
        // lifecycle — ADR 0034 §5's "next time the field is touched".
        $oldAfter = $this->fetchSlotAssignment($oldSlotId);
        self::assertSame('tombstoned', $oldAfter['status']);
        self::assertNull($oldAfter['field_id']);
        self::assertNotNull($oldAfter['tombstoned_at']);

        self::assertNull($this->fetchLiveSlotForField($fieldId), 'No replacement slot for a JSON-only field.');
        self::assertNull($this->fetchCheckpointForField($fieldId));
        self::assertSame($versionBefore + 1, $this->fetchSchemaVersion());

        $started = $this->recordsWithEvent($logger->records(), 'retype_started');
        self::assertSame($oldSlotId, $started[0]['context']['old_slot_assignment_id']);
    }

    public function testDemotionTombstonesSlotAndInsertsNoCheckpoint(): void
    {
        $this->provisionPage(['i_str_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'name');
        $this->reserveSlotFor($fieldId);

        $oldSlot = $this->fetchLiveSlotForField($fieldId);
        self::assertNotNull($oldSlot);
        $oldSlotId = (int) $oldSlot['id'];

        $versionBefore = $this->fetchSchemaVersion();

        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: null,
            newIsFilterable: false,
        );

        self::assertSame(0, (int) $this->fetchFieldRow($fieldId)['is_filterable']);
        self::assertSame('tombstoned', $this->fetchSlotAssignment($oldSlotId)['status']);
        self::assertNull($this->fetchLiveSlotForField($fieldId));
        self::assertNull($this->fetchCheckpointForField($fieldId), 'Demotion is tombstone-and-done.');
        self::assertSame($versionBefore + 1, $this->fetchSchemaVersion());
    }

    /**
     * ADR 0034 §4 / ADR 0013: demotion is seamless. Reads keep working
     * off the JSON payload the moment the slot is gone, and the field
     * is rejected as a filter target from then on.
     */
    public function testDemotedFieldReadsFallBackToJsonPayload(): void
    {
        $this->provisionPage(['i_str_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'name');
        $this->reserveSlotFor($fieldId);

        $this->seedEntry(1, $modelId, ['name' => 'Acme']);
        $this->seedEntry(1, $modelId, ['name' => 'Beta']);

        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: null,
            newIsFilterable: false,
        );

        $page = $this->reader()->read(new EntryQuery(tenantId: 1, modelId: $modelId));
        $names = array_map(static fn ($e) => $e->fields['name'], $page->rows);
        sort($names);
        self::assertSame(['Acme', 'Beta'], $names);

        $this->expectException(FieldNotFilterableException::class);
        $this->reader()->read(new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
            filter: LeafNode::local('name', 'eq', 'Acme'),
        ));
    }

    public function testReconcilerHasNothingToDrainAfterNonFilterableRetype(): void
    {
        $this->provisionPage(['i_int_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'note');

        $this->seedEntry(1, $modelId, ['note' => '42']);

        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: 'int',
            newIsFilterable: null,
        );

        self::assertSame(
            TickOutcome::IDLE,
            $this->makeRetypeBackfillWorkSource()->tickOne('cor-nothing-to-do'),
        );
    }
}
