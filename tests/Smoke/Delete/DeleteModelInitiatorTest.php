<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Delete;

use StarDust\Delete\DeleteCheckpointRepository;
use StarDust\Delete\ModelDeleteCheckpointRepository;
use StarDust\Exception\FieldDeletionInProgressException;
use StarDust\Exception\RenameInProgressException;
use StarDust\Exception\RetypeInProgressException;
use StarDust\Rename\RenameCheckpointRepository;
use StarDust\Retype\RetypeCheckpointRepository;
use StarDust\Tests\Smoke\Phase6bTestCase;

/**
 * ADR 0038 stage 3: the synchronous severance half.
 *
 * Mirrors `DeleteFieldInitiatorTest`, widened to every field of a model
 * and with one new marker on `stardust_models`.
 */
final class DeleteModelInitiatorTest extends Phase6bTestCase
{
    public function testInitiateCommitsTheRegistryTupleAtomically(): void
    {
        $this->provisionPage(['i_str_01']);
        $modelId = $this->createModel(1, 'invoice');
        $jsonOnly = $this->createField($modelId, 'string', false, 'colour');
        $filterable = $this->createField($modelId, 'string', true, 'shape');
        $this->reserveSlotFor($filterable);

        $versionBefore = $this->fetchSchemaVersion();

        self::assertTrue($this->makeDeleteModelInitiator()->initiate(1, $modelId));

        $model = $this->fetchModelRowOrNull($modelId);
        self::assertNotNull($model);
        self::assertNotNull($model['deleted_at'], 'The model marker must be set.');

        foreach ([$jsonOnly, $filterable] as $fieldId) {
            $field = $this->fetchFieldRowOrNull($fieldId);
            self::assertNotNull($field);
            self::assertNotNull($field['deleted_at'], 'Every field must be marked.');
            self::assertSame(0, (int) $field['is_filterable'], 'is_filterable must be cleared.');
        }

        // The slot must be tombstoned AND its field_id nulled, or the
        // final cascade fails errno 1451.
        $slot = $this->pdo->query(
            "SELECT status, field_id FROM stardust_slot_assignments WHERE slot_column = 'i_str_01'"
        )->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('tombstoned', $slot['status']);
        self::assertNull($slot['field_id']);

        $checkpoint = $this->fetchModelDeleteCheckpoint($modelId);
        self::assertNotNull($checkpoint);
        self::assertSame('running', $checkpoint['status']);
        self::assertSame(0, (int) $checkpoint['last_processed_id']);

        self::assertSame($versionBefore + 1, $this->fetchSchemaVersion(), 'Exactly one bump.');
    }

    public function testEmitsModelDeleteStartedOnceAfterCommit(): void
    {
        $this->provisionPage(['i_str_01']);
        $modelId = $this->createModel(1, 'invoice');
        $this->createField($modelId, 'string', false, 'colour');
        $filterable = $this->createField($modelId, 'string', true, 'shape');
        $this->reserveSlotFor($filterable);

        $logger = $this->makeRecordingLogger();
        self::assertTrue($this->makeDeleteModelInitiator($logger)->initiate(1, $modelId));

        $events = $this->recordsWithEvent($logger->records(), 'model_delete_started');
        self::assertCount(1, $events);

        $ctx = $events[0]['context'];
        self::assertSame('registry', $ctx['source']);
        self::assertSame(1, $ctx['tenant_id']);
        self::assertSame($modelId, $ctx['model_id']);
        self::assertSame('invoice', $ctx['model_name']);
        self::assertSame(2, $ctx['field_count']);
        self::assertSame(1, $ctx['slots_tombstoned'], 'Only the filterable field held a slot.');
    }

    /**
     * ADR rule 1's decisive case: a model registered with no fields is
     * legal, its severance marks zero field rows, and the purge must
     * still be able to claim it. A field-derived guard cannot express
     * this state at all.
     */
    public function testAFieldlessModelIsSeveredAndOpensAClaimableCheckpoint(): void
    {
        $modelId = $this->createModel(1, 'empty');

        self::assertTrue($this->makeDeleteModelInitiator()->initiate(1, $modelId));

        self::assertSame(0, $this->countFieldRows($modelId));
        self::assertNotNull($this->fetchModelRowOrNull($modelId)['deleted_at']);

        $claimed = (new ModelDeleteCheckpointRepository($this->pdo))->loadOneClaimable();
        self::assertNotNull($claimed, 'A fieldless model must still be claimable.');
        self::assertSame($modelId, $claimed->modelId);
        self::assertSame(1, $claimed->tenantId);
    }

    public function testAModelOfJsonOnlyFieldsTouchesNoSlot(): void
    {
        $this->provisionPage(['i_str_01']);
        $modelId = $this->createModel(1, 'json_only');
        $this->createField($modelId, 'string', false, 'a');
        $this->createField($modelId, 'string', false, 'b');

        self::assertTrue($this->makeDeleteModelInitiator()->initiate(1, $modelId));

        self::assertSame(
            0,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM stardust_slot_assignments WHERE status = 'tombstoned'"
            )->fetchColumn(),
        );
    }

    public function testUnknownModelReturnsFalseRatherThanThrowing(): void
    {
        self::assertFalse($this->makeDeleteModelInitiator()->initiate(1, 987_654));
    }

    public function testForeignTenantReturnsFalseAndChangesNothing(): void
    {
        $modelId = $this->createModel(1, 'invoice');

        self::assertFalse($this->makeDeleteModelInitiator()->initiate(2, $modelId));

        self::assertNull($this->fetchModelRowOrNull($modelId)['deleted_at']);
        self::assertNull($this->fetchModelDeleteCheckpoint($modelId));
    }

    public function testSecondDeleteReturnsFalseAndChangesNothing(): void
    {
        $modelId = $this->createModel(1, 'invoice');
        self::assertTrue($this->makeDeleteModelInitiator()->initiate(1, $modelId));

        $versionAfterFirst = $this->fetchSchemaVersion();
        $logger = $this->makeRecordingLogger();

        self::assertFalse($this->makeDeleteModelInitiator($logger)->initiate(1, $modelId));

        self::assertSame($versionAfterFirst, $this->fetchSchemaVersion(), 'No second bump.');
        self::assertCount(0, $this->recordsWithEvent($logger->records(), 'model_delete_started'));
    }

    // ---------------------------------------------------------------
    // Refuse, don't cancel
    // ---------------------------------------------------------------

    public function testRefusedWhileAnyFieldHasARenameRunning(): void
    {
        $modelId = $this->createModel(1, 'invoice');
        $fieldId = $this->createField($modelId, 'string', false, 'colour');
        $this->seedEntry(1, $modelId, ['colour' => 'red']);
        $this->makeRenameInitiator()->initiate(1, $fieldId, 'shade');

        $this->expectException(RenameInProgressException::class);
        $this->makeDeleteModelInitiator()->initiate(1, $modelId);
    }

    public function testRefusedWhileAnyFieldHasARetypeRunning(): void
    {
        $this->provisionPage(['i_str_01', 'i_int_01']);
        $modelId = $this->createModel(1, 'invoice');
        $fieldId = $this->createField($modelId, 'string', true, 'count');
        $this->reserveSlotFor($fieldId);
        $this->makeRetypeInitiator()->initiate(1, $fieldId, 'int', null);

        $this->expectException(RetypeInProgressException::class);
        $this->makeDeleteModelInitiator()->initiate(1, $modelId);
    }

    public function testRefusedWhileAnyFieldHasADeleteRunning(): void
    {
        $modelId = $this->createModel(1, 'invoice');
        $fieldId = $this->createField($modelId, 'string', false, 'colour');
        $this->seedEntry(1, $modelId, ['colour' => 'red']);
        self::assertTrue($this->makeDeleteFieldInitiator()->initiate(1, $fieldId));

        $this->expectException(FieldDeletionInProgressException::class);
        $this->makeDeleteModelInitiator()->initiate(1, $modelId);
    }

    /**
     * Refusal must not be a partial cancellation: the in-flight field
     * lifecycle has to remain runnable afterwards.
     */
    public function testARefusedDeleteMutatesNothing(): void
    {
        $modelId = $this->createModel(1, 'invoice');
        $fieldId = $this->createField($modelId, 'string', false, 'colour');
        $this->seedEntry(1, $modelId, ['colour' => 'red']);
        $this->makeRenameInitiator()->initiate(1, $fieldId, 'shade');

        $versionBefore = $this->fetchSchemaVersion();

        try {
            $this->makeDeleteModelInitiator()->initiate(1, $modelId);
            self::fail('Expected RenameInProgressException.');
        } catch (RenameInProgressException) {
            // expected
        }

        self::assertNull($this->fetchModelRowOrNull($modelId)['deleted_at']);
        self::assertNull($this->fetchFieldRowOrNull($fieldId)['deleted_at']);
        self::assertNull($this->fetchModelDeleteCheckpoint($modelId));
        self::assertSame($versionBefore, $this->fetchSchemaVersion());

        // The rename must still drain normally — the refusal cancelled
        // nothing.
        self::assertSame(
            'running',
            (new RenameCheckpointRepository($this->pdo))->statusForField($fieldId),
        );
        while ($this->runRenameTick() !== \StarDust\Reconciler\TickOutcome::IDLE) {
            // drain
        }
        self::assertSame(
            'completed',
            (new RenameCheckpointRepository($this->pdo))->statusForField($fieldId),
            'A refused model delete must leave the rename runnable.',
        );
    }

    /**
     * Three namespaces deep, N fields wide. The `delete_field_` leg is
     * new relative to ADR 0037 and is reachable: a field purge manually
     * marked `failed` leaves its row behind.
     */
    public function testClearsTerminalRowsForAllThreeJobNamesAcrossEveryField(): void
    {
        $modelId = $this->createModel(1, 'invoice');
        $a = $this->createField($modelId, 'string', false, 'a');
        $b = $this->createField($modelId, 'string', false, 'b');

        $this->seedTerminalCheckpoint(RenameCheckpointRepository::jobNameFor($a));
        $this->seedTerminalCheckpoint(RetypeCheckpointRepository::jobNameFor($a));
        $this->seedTerminalCheckpoint(DeleteCheckpointRepository::jobNameFor($b));

        self::assertTrue($this->makeDeleteModelInitiator()->initiate(1, $modelId));

        foreach ([
            RenameCheckpointRepository::jobNameFor($a),
            RetypeCheckpointRepository::jobNameFor($a),
            DeleteCheckpointRepository::jobNameFor($b),
        ] as $jobName) {
            self::assertSame(0, $this->countCheckpointsNamed($jobName), "{$jobName} must be gone.");
        }
    }

    public function testTerminalCleanupIsScopedToThisModelsFields(): void
    {
        $otherModel = $this->createModel(1, 'other');
        $otherField = $this->createField($otherModel, 'string', false, 'x');
        $this->seedTerminalCheckpoint(RenameCheckpointRepository::jobNameFor($otherField));

        $modelId = $this->createModel(1, 'invoice');
        $this->createField($modelId, 'string', false, 'a');

        self::assertTrue($this->makeDeleteModelInitiator()->initiate(1, $modelId));

        self::assertSame(
            1,
            $this->countCheckpointsNamed(RenameCheckpointRepository::jobNameFor($otherField)),
            "Another model's checkpoint must be untouched.",
        );
    }

    /**
     * All four prefixes are thirteen characters, so every claim query
     * shares the same `SUBSTRING(job_name, 14)` offset — and they are
     * pairwise disjoint under a prefix match.
     */
    public function testAllFourJobNamePrefixesAreThirteenCharactersAndDisjoint(): void
    {
        $prefixes = [
            RetypeCheckpointRepository::JOB_NAME_PREFIX,
            RenameCheckpointRepository::JOB_NAME_PREFIX,
            DeleteCheckpointRepository::JOB_NAME_PREFIX,
            ModelDeleteCheckpointRepository::JOB_NAME_PREFIX,
        ];

        foreach ($prefixes as $prefix) {
            self::assertSame(13, strlen($prefix), "{$prefix} must be 13 chars.");
        }

        foreach ($prefixes as $a) {
            foreach ($prefixes as $b) {
                if ($a === $b) {
                    continue;
                }
                self::assertStringStartsNotWith($a, $b . '42', "{$a} must not match {$b}.");
            }
        }
    }

    private function seedTerminalCheckpoint(string $jobName): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO backfill_checkpoints'
            . ' (job_name, last_processed_id, status, started_at, updated_at, completed_at)'
            . " VALUES (?, 10, 'completed', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $stmt->execute([$jobName]);
    }

    private function countCheckpointsNamed(string $jobName): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM backfill_checkpoints WHERE job_name = ?'
        );
        $stmt->execute([$jobName]);

        return (int) $stmt->fetchColumn();
    }
}
