<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Delete;

use StarDust\Delete\DeleteCheckpointRepository;
use StarDust\Exception\RenameInProgressException;
use StarDust\Exception\RetypeInProgressException;
use StarDust\Rename\RenameCheckpointRepository;
use StarDust\Retype\RetypeCheckpointRepository;
use StarDust\Tests\Smoke\Phase6bTestCase;

/**
 * ADR 0037 registry transaction: the synchronous half of a field delete.
 */
final class DeleteFieldInitiatorTest extends Phase6bTestCase
{
    public function testInitiateCommitsRegistryTupleAtomically(): void
    {
        $this->provisionPage(['i_str_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'colour');
        $this->reserveSlotFor($fieldId);
        $slotId = (int) $this->fetchLiveSlotForField($fieldId)['id'];
        $versionBefore = $this->fetchSchemaVersion();

        self::assertTrue($this->makeDeleteFieldInitiator()->initiate(1, $fieldId));

        $field = $this->fetchFieldRow($fieldId);
        self::assertNotNull($field['deleted_at'], 'Deletion marker is set immediately.');
        self::assertSame(
            0,
            (int) $field['is_filterable'],
            'Cleared in the same UPDATE, or the Watcher reads the field as pending demand.',
        );

        $slot = $this->fetchSlotAssignment($slotId);
        self::assertSame('tombstoned', $slot['status']);
        self::assertNull($slot['field_id'], 'field_id must be nulled, or the FK blocks the final DELETE.');
        self::assertNull($this->fetchLiveSlotForField($fieldId));

        $checkpoint = $this->fetchDeleteCheckpointForField($fieldId);
        self::assertIsArray($checkpoint);
        self::assertSame('running', $checkpoint['status']);
        self::assertSame(0, $checkpoint['last_processed_id']);

        self::assertGreaterThan(
            $versionBefore,
            $this->fetchSchemaVersion(),
            'Schema version must bump or cached snapshots keep serving the deleted field.',
        );
    }

    public function testEmitsDeleteStartedOnceAfterCommit(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'colour');

        $logger = $this->makeRecordingLogger();
        $this->makeDeleteFieldInitiator($logger)->initiate(1, $fieldId);

        $records = $this->recordsWithEvent($logger->records(), 'delete_started');
        self::assertCount(1, $records);
        self::assertSame('registry', $records[0]['context']['source']);
        self::assertSame('colour', $records[0]['context']['field_name']);
        self::assertSame($fieldId, $records[0]['context']['field_id']);
        self::assertNull(
            $records[0]['context']['old_slot_assignment_id'],
            'A JSON-only field holds no slot under ADR 0034.',
        );
    }

    /**
     * The common case behind a UI, and the cheap one: under ADR 0034 a
     * non-filterable field has no slot at all, so there is nothing to
     * tombstone and nothing for the Liberator to reclaim.
     */
    public function testDeletingAJsonOnlyFieldTouchesNoSlot(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'colour');

        self::assertTrue($this->makeDeleteFieldInitiator()->initiate(1, $fieldId));

        self::assertNull($this->fetchLiveSlotForField($fieldId));
        $tombstoned = $this->pdo
            ->query("SELECT COUNT(*) FROM stardust_slot_assignments WHERE status = 'tombstoned'")
            ->fetchColumn();
        self::assertSame(0, (int) $tombstoned);
    }

    public function testUnknownFieldReturnsFalseRatherThanThrowing(): void
    {
        self::assertFalse($this->makeDeleteFieldInitiator()->initiate(1, 987_654));
    }

    public function testForeignTenantReturnsFalse(): void
    {
        $modelId = $this->createModel(2);
        $fieldId = $this->createField($modelId, 'string', false, 'colour');

        self::assertFalse(
            $this->makeDeleteFieldInitiator()->initiate(1, $fieldId),
            'Indistinguishable from "does not exist", per the tenant-isolation rule.',
        );
        self::assertNull($this->fetchFieldRow($fieldId)['deleted_at']);
    }

    /**
     * Idempotence, matching `deleteEntry()`: a repeated delete has
     * already achieved what the caller wanted.
     */
    public function testSecondDeleteReturnsFalseAndChangesNothing(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'colour');

        $initiator = $this->makeDeleteFieldInitiator();
        self::assertTrue($initiator->initiate(1, $fieldId));

        $versionAfterFirst = $this->fetchSchemaVersion();
        $cursorAfterFirst = $this->fetchDeleteCheckpointForField($fieldId);

        $logger = $this->makeRecordingLogger();
        self::assertFalse($this->makeDeleteFieldInitiator($logger)->initiate(1, $fieldId));

        self::assertSame($versionAfterFirst, $this->fetchSchemaVersion(), 'No-op must not bump.');
        self::assertSame($cursorAfterFirst, $this->fetchDeleteCheckpointForField($fieldId));
        self::assertSame([], $this->recordsWithEvent($logger->records(), 'delete_started'));
    }

    public function testRefusedWhileARenameIsRunning(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'colour');
        $this->makeRenameInitiator()->initiate(1, $fieldId, 'shade');

        $this->expectException(RenameInProgressException::class);
        $this->makeDeleteFieldInitiator()->initiate(1, $fieldId);
    }

    public function testRefusedWhileARetypeIsRunning(): void
    {
        $this->provisionPage(['i_str_01', 'i_int_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'count');
        $this->reserveSlotFor($fieldId);
        $this->makeRetypeInitiator()->initiate(1, $fieldId, 'int', null);

        $this->expectException(RetypeInProgressException::class);
        $this->makeDeleteFieldInitiator()->initiate(1, $fieldId);
    }

    /**
     * A refused delete must leave the field completely untouched — the
     * whole point of refusing rather than cancelling is that the
     * in-flight lifecycle stays runnable.
     */
    public function testARefusedDeleteMutatesNothing(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'colour');
        $this->makeRenameInitiator()->initiate(1, $fieldId, 'shade');
        $versionBefore = $this->fetchSchemaVersion();

        try {
            $this->makeDeleteFieldInitiator()->initiate(1, $fieldId);
            self::fail('Expected RenameInProgressException.');
        } catch (RenameInProgressException) {
            // expected
        }

        self::assertNull($this->fetchFieldRow($fieldId)['deleted_at']);
        self::assertSame($versionBefore, $this->fetchSchemaVersion());
        self::assertNull($this->fetchDeleteCheckpointForField($fieldId));
        self::assertSame(
            'running',
            $this->fetchRenameCheckpointForField($fieldId)['status'],
            'The rename must remain drainable.',
        );
    }

    /**
     * Terminal rename/retype rows are harmless while their field exists
     * and become permanently unclaimable orphans the moment it does not,
     * because both sibling repositories recover the field id by INNER
     * JOIN on a substring of `job_name`.
     */
    public function testClearsTerminalLifecycleCheckpointsInTheSameTransaction(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'colour');

        // Drive a real rename to completion so its checkpoint is
        // terminal. The model has no entries, so one tick finishes it.
        $this->makeRenameInitiator()->initiate(1, $fieldId, 'shade');
        $this->runRenameTick();
        self::assertSame('completed', $this->fetchRenameCheckpointForField($fieldId)['status']);

        self::assertTrue($this->makeDeleteFieldInitiator()->initiate(1, $fieldId));

        self::assertNull(
            $this->fetchRenameCheckpointForField($fieldId),
            'A terminal rename row for a field being deleted is an orphan in waiting.',
        );
        self::assertIsArray(
            $this->fetchDeleteCheckpointForField($fieldId),
            'The delete checkpoint itself must survive — the purge has not run yet.',
        );
    }

    /**
     * The three job-name namespaces must stay disjoint: clearing one
     * field's terminal rows may not touch another field's.
     */
    public function testTerminalCleanupIsScopedToTheDeletedField(): void
    {
        $modelId = $this->createModel(1);
        $victim = $this->createField($modelId, 'string', false, 'colour');
        $bystander = $this->createField($modelId, 'string', false, 'shape');

        $this->makeRenameInitiator()->initiate(1, $bystander, 'form');

        $this->makeDeleteFieldInitiator()->initiate(1, $victim);

        self::assertIsArray(
            $this->fetchRenameCheckpointForField($bystander),
            "Another field's checkpoint must be untouched.",
        );
    }

    public function testJobNamePrefixesAreDisjoint(): void
    {
        self::assertNotSame(
            DeleteCheckpointRepository::JOB_NAME_PREFIX,
            RenameCheckpointRepository::JOB_NAME_PREFIX,
        );
        self::assertStringStartsNotWith(
            RetypeCheckpointRepository::jobNameFor(1),
            DeleteCheckpointRepository::jobNameFor(1),
        );
        self::assertSame('delete_field_7', DeleteCheckpointRepository::jobNameFor(7));
    }
}
