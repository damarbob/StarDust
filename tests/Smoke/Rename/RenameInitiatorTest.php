<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Rename;

use InvalidArgumentException;
use StarDust\Exception\FieldNameConflictException;
use StarDust\Exception\FieldNotFoundException;
use StarDust\Exception\RenameInProgressException;
use StarDust\Exception\RetypeInProgressException;
use StarDust\Tests\Smoke\Phase6bTestCase;

/**
 * ADR 0036 registry transaction: the synchronous half of a field rename.
 */
final class RenameInitiatorTest extends Phase6bTestCase
{
    public function testInitiateCommitsRegistryTupleAtomically(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'title');
        $versionBefore = $this->fetchSchemaVersion();

        $this->makeRenameInitiator()->initiate(1, $fieldId, 'headline');

        $field = $this->fetchFieldRow($fieldId);
        self::assertSame('headline', $field['name'], 'Registry name flips immediately.');
        self::assertSame('title', $field['previous_name'], 'Old name is stashed for the window.');

        $checkpoint = $this->fetchRenameCheckpointForField($fieldId);
        self::assertIsArray($checkpoint);
        self::assertSame('running', $checkpoint['status']);
        self::assertSame(0, $checkpoint['last_processed_id']);

        self::assertGreaterThan(
            $versionBefore,
            $this->fetchSchemaVersion(),
            'Schema version must bump or cached snapshots keep the stale name map.',
        );
    }

    public function testEmitsRenameStartedOnceAfterCommit(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'title');

        $logger = $this->makeRecordingLogger();
        $this->makeRenameInitiator($logger)->initiate(1, $fieldId, 'headline');

        $records = $this->recordsWithEvent($logger->records(), 'rename_started');
        self::assertCount(1, $records);
        self::assertSame('registry', $records[0]['context']['source']);
        self::assertSame('title', $records[0]['context']['old_name']);
        self::assertSame('headline', $records[0]['context']['new_name']);
    }

    public function testRenamingToTheSameNameIsASilentNoOp(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'title');
        $versionBefore = $this->fetchSchemaVersion();

        $logger = $this->makeRecordingLogger();
        $this->makeRenameInitiator($logger)->initiate(1, $fieldId, 'title');

        self::assertSame($versionBefore, $this->fetchSchemaVersion(), 'No-op must not bump the version.');
        self::assertNull($this->fetchRenameCheckpointForField($fieldId), 'No-op must not open a checkpoint.');
        self::assertSame([], $this->recordsWithEvent($logger->records(), 'rename_started'));
        self::assertNull($this->fetchFieldRow($fieldId)['previous_name']);
    }

    public function testRejectsForeignTenant(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'title');

        $this->expectException(FieldNotFoundException::class);
        $this->makeRenameInitiator()->initiate(2, $fieldId, 'headline');
    }

    public function testRejectsEmptyName(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'title');

        $this->expectException(InvalidArgumentException::class);
        $this->makeRenameInitiator()->initiate(1, $fieldId, '   ');
    }

    public function testRejectsCollisionWithASiblingsCurrentName(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'title');
        $this->createField($modelId, 'string', false, 'headline');

        $this->expectException(FieldNameConflictException::class);
        $this->makeRenameInitiator()->initiate(1, $fieldId, 'headline');
    }

    /**
     * The case `ux_fields_model_name` cannot see, and the one a naive
     * implementation passes by accident.
     *
     * Renaming `a → b` frees the name `a` as far as the unique index is
     * concerned, because `a` now lives in `previous_name`. Allowing a
     * sibling `y → a` would then make field `b`'s read-path fallback
     * (new key, else old key) resolve to field `y`'s value on every row
     * the first backfill has not yet reached.
     */
    public function testRejectsCollisionWithASiblingsPreviousNameMidRename(): void
    {
        $modelId = $this->createModel(1);
        $a = $this->createField($modelId, 'string', false, 'a');
        $y = $this->createField($modelId, 'string', false, 'y');

        $initiator = $this->makeRenameInitiator();
        $initiator->initiate(1, $a, 'b');

        // The name 'a' is now free per the unique index — prove it.
        $free = (int) $this->pdo
            ->query("SELECT COUNT(*) FROM stardust_fields WHERE model_id = {$modelId} AND name = 'a'")
            ->fetchColumn();
        self::assertSame(0, $free, 'Precondition: the unique index no longer sees the old name.');

        $this->expectException(FieldNameConflictException::class);
        $initiator->initiate(1, $y, 'a');
    }

    public function testRejectsASecondRenameWhileOneIsRunning(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'title');

        $initiator = $this->makeRenameInitiator();
        $initiator->initiate(1, $fieldId, 'headline');

        $this->expectException(RenameInProgressException::class);
        $initiator->initiate(1, $fieldId, 'caption');
    }

    public function testRejectsRenameWhileARetypeIsRunning(): void
    {
        $this->provisionPage(['i_str_01', 'i_int_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'title');
        $this->reserveSlotFor($fieldId);

        $this->makeRetypeInitiator()->initiate(1, $fieldId, 'int', null);

        $this->expectException(RetypeInProgressException::class);
        $this->makeRenameInitiator()->initiate(1, $fieldId, 'headline');
    }

    /**
     * Regression guard for the defect the retype repository still has:
     * nothing ever deletes from `backfill_checkpoints`, so a plain
     * INSERT would throw a raw PDOException on the second lifecycle for
     * a field once the first has completed.
     */
    public function testASecondRenameSucceedsAfterTheFirstCompletes(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'title');

        $initiator = $this->makeRenameInitiator();
        $initiator->initiate(1, $fieldId, 'headline');
        $this->runRenameTick();

        self::assertSame('completed', $this->fetchRenameCheckpointForField($fieldId)['status']);

        $initiator->initiate(1, $fieldId, 'caption');

        $checkpoint = $this->fetchRenameCheckpointForField($fieldId);
        self::assertSame('running', $checkpoint['status'], 'The terminal row must be reset, not collided with.');
        self::assertSame(0, $checkpoint['last_processed_id'], 'Cursor must restart.');
        self::assertSame('headline', $this->fetchFieldRow($fieldId)['previous_name']);
    }
}
