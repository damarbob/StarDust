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
        $this->provisionLegacyPage();
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'int', true, 'count');
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

    /**
     * The bump condition is shared with the registry-only path (ADR
     * 0034), so pin the already-correct branch: a reservation that
     * succeeds bumps inside `SlotReserver` and must not be
     * double-counted by the initiator's compensating bump.
     */
    public function testFilterableRetypeStillBumpsSchemaVersionExactlyOnce(): void
    {
        $this->provisionPage(['i_str_01', 'i_int_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'value');
        $this->reserveSlotFor($fieldId);

        $before = $this->fetchSchemaVersion();

        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: 'int',
            newIsFilterable: null,
        );

        self::assertNotNull($this->fetchLiveSlotForField($fieldId), 'Reservation must have succeeded.');
        self::assertSame($before + 1, $this->fetchSchemaVersion());
    }

    public function testAtomicRegistryTransactionPopulatesEveryRow(): void
    {
        // i_str_01 holds the field's original slot; i_int_01 is the
        // replacement the string → int retype lands on, indexed because
        // the field is filterable (ADR 0016 commitment 1). Both must be
        // named: since ADR 0043 a page carries exactly its provisioned
        // columns, so omitting the string one leaves the field with no
        // old slot to tombstone.
        $this->provisionPage(['i_str_01', 'i_int_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'name');
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
        $this->provisionLegacyPage();
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'name');
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

    /**
     * A field must not be a one-way door.
     *
     * `ux_backfill_job_name` is UNIQUE and nothing removes a retype
     * checkpoint, so before `insertOrReset()` the *second*
     * filterable-target lifecycle for a field died on a raw
     * `PDOException` (errno 1062) once the first had completed —
     * `existsRunningForField()` reports `false` for a terminal row and
     * offered no protection. Reachable from three plain facade calls
     * with no compaction involved, which is the shape reproduced here.
     *
     * Sibling of
     * {@see \StarDust\Tests\Smoke\Rename\RenameInitiatorTest::testASecondRenameSucceedsAfterTheFirstCompletes}.
     */
    public function testASecondFilterableLifecycleSucceedsAfterTheFirstCompletes(): void
    {
        // Two indexed string slots: the first promotion takes one and
        // the demotion tombstones rather than frees it, so the second
        // promotion needs another.
        $this->provisionPage(['i_str_01', 'i_str_02']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'beta');
        $this->seedEntry(1, $modelId, ['beta' => 'beta-value']);

        $initiator = $this->makeRetypeInitiator();

        $initiator->initiate(tenantId: 1, fieldId: $fieldId, newDeclaredType: null, newIsFilterable: true);
        $this->makeRetypeReconciler()->tick();

        $first = $this->fetchCheckpointForField($fieldId);
        self::assertNotNull($first);
        self::assertSame('completed', $first['status']);
        self::assertGreaterThan(0, $first['last_processed_id'], 'The first drain must have moved the cursor.');

        // ADR 0034: a non-filterable target has nothing to backfill, so
        // the demotion writes no checkpoint and the terminal row stands.
        $initiator->initiate(tenantId: 1, fieldId: $fieldId, newDeclaredType: null, newIsFilterable: false);
        self::assertSame('completed', $this->fetchCheckpointForField($fieldId)['status']);

        $initiator->initiate(tenantId: 1, fieldId: $fieldId, newDeclaredType: null, newIsFilterable: true);

        $second = $this->fetchCheckpointForField($fieldId);
        self::assertNotNull($second);
        self::assertSame('running', $second['status'], 'The terminal row must be reset, not collided with.');
        self::assertSame(0, $second['last_processed_id'], 'Cursor must restart.');
        self::assertNull($second['completed_at'], 'The previous completion must be cleared.');
        self::assertNotNull($this->fetchLiveSlotForField($fieldId), 'The second promotion must hold a slot.');
    }

    /**
     * The one assertion that fails if the upsert omits
     * `source_declared_type = VALUES(source_declared_type)`.
     *
     * No sibling checkpoint repository has that column, so porting their
     * `ON DUPLICATE KEY UPDATE` verbatim leaves the reset row carrying
     * the *first* lifecycle's source type. `RetypeBackfillWorkSource`
     * reads it to pick the ADR 0024 matrix cell, so the second backfill
     * would coerce `int → string` values as though they were still
     * `string → int` — silently, with no event and no exception.
     */
    public function testResetCheckpointCarriesTheNewSourceDeclaredType(): void
    {
        // Indexed columns for both families, twice over for strings:
        // ADR 0016 commitment 1 means each replacement reservation
        // demands an indexed slot of the target family, and the vacated
        // ones are tombstoned rather than freed.
        $this->provisionPage(['i_str_01', 'i_str_02', 'i_int_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'value');
        $this->reserveSlotFor($fieldId);
        $this->seedEntry(1, $modelId, ['value' => '42']);

        $initiator = $this->makeRetypeInitiator();

        $initiator->initiate(tenantId: 1, fieldId: $fieldId, newDeclaredType: 'int', newIsFilterable: null);
        self::assertSame('string', $this->fetchCheckpointForField($fieldId)['source_declared_type']);

        $this->makeRetypeReconciler()->tick();
        self::assertSame('completed', $this->fetchCheckpointForField($fieldId)['status']);

        $initiator->initiate(tenantId: 1, fieldId: $fieldId, newDeclaredType: 'string', newIsFilterable: null);

        self::assertSame(
            'int',
            $this->fetchCheckpointForField($fieldId)['source_declared_type'],
            'The reset row must carry the second lifecycle\'s source type, not the first\'s.',
        );
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
        $pageId = $this->provisionLegacyPage();
        $modelId = $this->createModel(7); // model belongs to tenant 7
        $fieldId = $this->createField($modelId, 'string', true, 'name');
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
