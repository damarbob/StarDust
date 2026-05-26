<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Retype;

use StarDust\Exception\FieldNotFoundException;
use StarDust\Exception\IncompatibleRetypeException;
use StarDust\Exception\RetypeInProgressException;
use StarDust\Tests\Smoke\Phase6bTestCase;

/**
 * Exercises {@see \StarDust\Retype\RetypeInitiator::initiate()} —
 * the atomic registry transaction at the start of the Phase 6b
 * lifecycle.
 *
 * Covers exit criteria:
 *   #1 categorical-rejection guard at registry-write time;
 *   #2 atomic transaction shape (field updated, old slot tombstoned,
 *      new slot in `backfilling`, schema_version bumped, checkpoint
 *      inserted, `retype_started` emitted);
 *   #9 second initiation while one is running throws.
 */
final class RetypeInitiatorTest extends Phase6bTestCase
{
    public function testIncompatibleRetypeIsRejectedBeforeAnyMutation(): void
    {
        // Field starts as int; attempt to retype to datetime.
        $this->provisionPage();
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'int', false, 'count');
        $this->reserveSlotFor($fieldId);

        $versionBefore = $this->fetchSchemaVersion();

        try {
            $this->makeRetypeInitiator()->initiate(
                tenantId: 1,
                fieldId: $fieldId,
                newDeclaredType: 'datetime',
                newIsFilterable: null,
            );
            self::fail('Expected IncompatibleRetypeException');
        } catch (IncompatibleRetypeException) {
            // Expected.
        }

        // Nothing changed.
        self::assertSame($versionBefore, $this->fetchSchemaVersion());
        self::assertSame('int', $this->fetchFieldRow($fieldId)['declared_type']);
        self::assertNull($this->fetchCheckpointForField($fieldId));
        self::assertNotNull($this->fetchLiveSlotForField($fieldId));
    }

    public function testAtomicRegistryTransactionPopulatesEveryRow(): void
    {
        $this->provisionPage();
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'name');
        $this->reserveSlotFor($fieldId);

        $oldSlot = $this->fetchLiveSlotForField($fieldId);
        self::assertNotNull($oldSlot);
        $oldSlotId      = (int) $oldSlot['id'];
        $versionBefore  = $this->fetchSchemaVersion();

        $logger = $this->makeRecordingLogger();
        $this->makeRetypeInitiator($logger)->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: 'int',
            newIsFilterable: null,
        );
        $records = $logger->records();

        // 1. stardust_fields updated.
        self::assertSame('int', $this->fetchFieldRow($fieldId)['declared_type']);

        // 2. Old slot tombstoned and field_id cleared.
        $oldSlotAfter = $this->fetchSlotAssignment($oldSlotId);
        self::assertSame('tombstoned', $oldSlotAfter['status']);
        self::assertNull($oldSlotAfter['field_id']);
        self::assertNotNull($oldSlotAfter['tombstoned_at']);

        // 3. New slot in backfilling, of the target slot_type family.
        $newSlot = $this->fetchLiveSlotForField($fieldId);
        self::assertNotNull($newSlot);
        self::assertSame('backfilling', $newSlot['status']);
        self::assertSame('int', $newSlot['slot_type']);

        // 4. schema_version bumped (slot reservation + field update
        //    share one bump because the reserver's bump fires inside
        //    our outer tx; we don't double-bump when the reservation
        //    succeeds).
        self::assertGreaterThan($versionBefore, $this->fetchSchemaVersion());

        // 5. Checkpoint row inserted with source_declared_type recorded.
        $checkpoint = $this->fetchCheckpointForField($fieldId);
        self::assertIsArray($checkpoint);
        self::assertSame('running', $checkpoint['status']);
        self::assertSame(0, (int) $checkpoint['last_processed_id']);
        self::assertSame('string', $checkpoint['source_declared_type']);
        self::assertSame('retype_field_' . $fieldId, $checkpoint['job_name']);

        // 6. retype_started event emitted.
        $started = $this->recordsWithEvent($records, 'retype_started');
        self::assertCount(1, $started, 'Exactly one retype_started event');
        self::assertSame('string', $started[0]['context']['old_declared_type']);
        self::assertSame('int', $started[0]['context']['new_declared_type']);
        self::assertSame(false, $started[0]['context']['deferred_assignment']);
    }

    public function testSecondInitiationForSameFieldThrowsRetypeInProgress(): void
    {
        $this->provisionPage();
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'name');
        $this->reserveSlotFor($fieldId);

        $initiator = $this->makeRetypeInitiator();
        $initiator->initiate(tenantId: 1, fieldId: $fieldId, newDeclaredType: 'int', newIsFilterable: null);

        $versionBefore = $this->fetchSchemaVersion();

        $this->expectException(RetypeInProgressException::class);
        try {
            $initiator->initiate(tenantId: 1, fieldId: $fieldId, newDeclaredType: 'numeric', newIsFilterable: null);
        } finally {
            // Registry untouched between attempts (declared_type still 'int' from first call).
            self::assertSame('int', $this->fetchFieldRow($fieldId)['declared_type']);
            self::assertSame($versionBefore, $this->fetchSchemaVersion());
        }
    }

    public function testInitiationForUnknownFieldThrowsFieldNotFound(): void
    {
        $this->expectException(FieldNotFoundException::class);
        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: 99_999,
            newDeclaredType: 'int',
            newIsFilterable: null,
        );
    }

    public function testInitiationFromOtherTenantThrowsFieldNotFound(): void
    {
        $pageId = $this->provisionPage();
        $modelId = $this->createModel(7); // model belongs to tenant 7
        $fieldId = $this->createField($modelId, 'string', false, 'name');
        $this->reserveSlotFor($fieldId);

        $this->expectException(FieldNotFoundException::class);
        $this->makeRetypeInitiator()->initiate(
            tenantId: 99,   // wrong tenant
            fieldId: $fieldId,
            newDeclaredType: 'int',
            newIsFilterable: null,
        );
    }
}
