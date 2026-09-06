<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Delete;

use StarDust\Delete\ModelDeleteCheckpointRepository;
use StarDust\Reconciler\TickOutcome;
use StarDust\Tests\Smoke\Phase6bTestCase;

/**
 * ADR 0038 stage 3: the asynchronous purge half.
 *
 * The most destructive operation in the engine — it deletes `entry_data`
 * rows outright, cascades into every extension page the model touches,
 * and its final chunk drops the `stardust_models` row, taking every field
 * with it through `fk_fields_model`.
 */
final class ModelPurgeTest extends Phase6bTestCase
{
    public function testDrainsAcrossMultipleChunksThenDeletesTheModelRow(): void
    {
        $modelId = $this->createModel(1, 'invoice');
        $fieldId = $this->createField($modelId, 'string', false, 'colour');
        for ($i = 1; $i <= 5; $i++) {
            $this->seedEntry(1, $modelId, ['colour' => "c{$i}"]);
        }

        self::assertTrue($this->makeDeleteModelInitiator()->initiate(1, $modelId));

        // 5 rows, chunk 2 → 2 + 2 + 1, then IDLE.
        self::assertSame(TickOutcome::WORK_DONE, $this->runModelPurgeTick(null, 2));
        self::assertSame(3, $this->countEntryRows(1, $modelId));
        self::assertNotNull(
            $this->fetchModelRowOrNull($modelId),
            'Mid-drain the model row must survive — the purge reads its tenant from it.',
        );
        self::assertSame(1, $this->countFieldRows($modelId), 'Fields must not cascade early.');

        self::assertSame(TickOutcome::WORK_DONE, $this->runModelPurgeTick(null, 2));
        self::assertSame(1, $this->countEntryRows(1, $modelId));

        self::assertSame(TickOutcome::WORK_DONE, $this->runModelPurgeTick(null, 2));
        self::assertSame(0, $this->countEntryRows(1, $modelId));
        self::assertNull($this->fetchModelRowOrNull($modelId), 'The model row must be gone.');
        self::assertSame(0, $this->countFieldRows($modelId), 'Fields cascade with the model.');
        self::assertNull($this->fetchFieldRowOrNull($fieldId));
        self::assertNull($this->fetchModelDeleteCheckpoint($modelId), 'The checkpoint dies too.');

        self::assertSame(TickOutcome::IDLE, $this->runModelPurgeTick(null, 2));
    }

    public function testAModelWithNoEntriesCompletesOnTheFirstTick(): void
    {
        $modelId = $this->createModel(1, 'empty');
        $this->createField($modelId, 'string', false, 'colour');

        self::assertTrue($this->makeDeleteModelInitiator()->initiate(1, $modelId));
        self::assertSame(TickOutcome::WORK_DONE, $this->runModelPurgeTick());

        self::assertNull($this->fetchModelRowOrNull($modelId));
        self::assertSame(0, $this->countFieldRows($modelId));
        self::assertSame(TickOutcome::IDLE, $this->runModelPurgeTick());
    }

    /** ADR rule 1's decisive case, drained end to end. */
    public function testAFieldlessModelWithEntriesStillDrainsAndCompletes(): void
    {
        $modelId = $this->createModel(1, 'fieldless');
        for ($i = 1; $i <= 3; $i++) {
            $this->seedEntry(1, $modelId, ['unregistered' => "v{$i}"]);
        }

        self::assertTrue($this->makeDeleteModelInitiator()->initiate(1, $modelId));
        $this->drainModelPurge(2);

        self::assertSame(0, $this->countEntryRows(1, $modelId));
        self::assertNull($this->fetchModelRowOrNull($modelId));
    }

    public function testExtensionPageRowsAreCascadedAwayWithTheirEntries(): void
    {
        $pageId = $this->provisionPage(['i_str_01']);
        $table = $this->pageTableNameFor($pageId);
        $modelId = $this->createModel(1, 'invoice');
        $fieldId = $this->createField($modelId, 'string', true, 'shape');
        $this->reserveSlotFor($fieldId);

        $entryIds = [];
        for ($i = 1; $i <= 3; $i++) {
            $entryIds[] = $this->seedEntry(1, $modelId, ['shape' => "s{$i}"]);
        }
        self::assertSame(3, $this->countPageRowsFor($table, $entryIds), 'Fixture guard.');

        self::assertTrue($this->makeDeleteModelInitiator()->initiate(1, $modelId));
        $this->drainModelPurge(2);

        self::assertSame(
            0,
            $this->countPageRowsFor($table, $entryIds),
            'fk_<page>_entry ON DELETE CASCADE removes them for free.',
        );
    }

    /**
     * The counter-test for the whole sync-queue rule. Without the
     * in-transaction delete, `SyncQueueWorkSource` finds no `entry_data`
     * row for each survivor and files a `missing_entry_data` dead-letter
     * row — the purge manufacturing DLQ noise in proportion to pending
     * writes.
     */
    public function testSyncQueueRowsDieWithTheirEntriesAndProduceNoDlqRows(): void
    {
        // A filterable field with no slot is ADR 0007 demand, so every
        // write enqueues.
        $modelId = $this->createModel(1, 'invoice');
        $this->createField($modelId, 'string', true, 'shape');

        $entryIds = [];
        for ($i = 1; $i <= 4; $i++) {
            $entryIds[] = $this->seedEntry(1, $modelId, ['shape' => "s{$i}"]);
        }
        self::assertSame(4, $this->countSyncQueueRowsFor($entryIds), 'Fixture guard: rows are queued.');

        self::assertTrue($this->makeDeleteModelInitiator()->initiate(1, $modelId));
        $this->drainModelPurge(2);

        self::assertSame(0, $this->countSyncQueueRowsFor($entryIds));

        // And the drain that would have quarantined them finds nothing.
        $this->makeSyncQueueWorkSource()->tickOne('test-sync-' . bin2hex(random_bytes(4)));

        self::assertSame(
            0,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM stardust_reconciler_dlq WHERE reason = 'missing_entry_data'"
            )->fetchColumn(),
            'The purge must not manufacture dead-letter rows.',
        );
    }

    public function testSoftDeletedEntriesAreHardDeletedToo(): void
    {
        $modelId = $this->createModel(1, 'invoice');
        $this->createField($modelId, 'string', false, 'colour');
        $keep = $this->seedEntry(1, $modelId, ['colour' => 'red']);
        $soft = $this->seedEntry(1, $modelId, ['colour' => 'blue']);
        $this->pdo->exec('UPDATE entry_data SET deleted_at = UTC_TIMESTAMP() WHERE id = ' . $soft);

        self::assertTrue($this->makeDeleteModelInitiator()->initiate(1, $modelId));
        $this->drainModelPurge(2);

        self::assertNull($this->fetchEntryRowOrNull($keep));
        self::assertNull(
            $this->fetchEntryRowOrNull($soft),
            'The chunk query carries no deleted_at predicate, by design.',
        );
    }

    public function testOtherModelsAndTenantsAreUntouched(): void
    {
        $target = $this->createModel(1, 'target');
        $this->createField($target, 'string', false, 'colour');
        $sibling = $this->createModel(1, 'sibling');
        $this->createField($sibling, 'string', false, 'colour');
        $otherTenant = $this->createModel(2, 'target');
        $this->createField($otherTenant, 'string', false, 'colour');

        $this->seedEntry(1, $target, ['colour' => 'a']);
        $this->seedEntry(1, $sibling, ['colour' => 'b']);
        $this->seedEntry(2, $otherTenant, ['colour' => 'c']);

        self::assertTrue($this->makeDeleteModelInitiator()->initiate(1, $target));
        $this->drainModelPurge(2);

        self::assertSame(0, $this->countEntryRows(1, $target));
        self::assertSame(1, $this->countEntryRows(1, $sibling));
        self::assertSame(1, $this->countEntryRows(2, $otherTenant));
        self::assertNotNull($this->fetchModelRowOrNull($sibling));
        self::assertNotNull($this->fetchModelRowOrNull($otherTenant));
    }

    public function testEmitsChunkEventsAndExactlyOneModelDeleteComplete(): void
    {
        $modelId = $this->createModel(1, 'invoice');
        $this->createField($modelId, 'string', false, 'colour');
        for ($i = 1; $i <= 3; $i++) {
            $this->seedEntry(1, $modelId, ['colour' => "c{$i}"]);
        }

        self::assertTrue($this->makeDeleteModelInitiator()->initiate(1, $modelId));

        $logger = $this->makeRecordingLogger();
        do {
            $outcome = $this->makeModelPurgeWorkSource($logger, 2)
                ->tickOne('test-' . bin2hex(random_bytes(4)));
        } while ($outcome !== TickOutcome::IDLE);

        $records = $logger->records();
        $complete = $this->recordsWithEvent($records, 'model_delete_complete');
        self::assertCount(1, $complete, 'Exactly one completion event.');
        self::assertSame('registry', $complete[0]['context']['source']);
        self::assertSame($modelId, $complete[0]['context']['model_id']);
        self::assertSame(1, $complete[0]['context']['fields_dropped']);

        $chunks = $this->recordsWithEvent($records, 'chunk_complete');
        self::assertNotEmpty($chunks);
        foreach ($chunks as $chunk) {
            self::assertSame('model_delete_purge', $chunk['context']['queue']);
            self::assertArrayHasKey('rows_deleted', $chunk['context']);
            self::assertArrayHasKey('sync_rows_deleted', $chunk['context']);
            self::assertArrayHasKey('rows_scanned', $chunk['context']);
        }

        $claims = $this->recordsWithEvent($records, 'chunk_claimed');
        self::assertNotEmpty($claims);
        self::assertSame('model_delete_purge', $claims[0]['context']['queue']);
    }

    public function testTombstonedSlotsStillReclaimAfterTheModelRowIsGone(): void
    {
        $pageId = $this->provisionPage(['i_str_01']);
        $modelId = $this->createModel(1, 'invoice');
        $fieldId = $this->createField($modelId, 'string', true, 'shape');
        $this->reserveSlotFor($fieldId);
        $this->seedEntry(1, $modelId, ['shape' => 'round']);

        $slotId = (int) $this->pdo->query(
            "SELECT id FROM stardust_slot_assignments WHERE slot_column = 'i_str_01'"
        )->fetchColumn();

        self::assertTrue($this->makeDeleteModelInitiator()->initiate(1, $modelId));
        $this->drainModelPurge(2);
        self::assertNull($this->fetchModelRowOrNull($modelId), 'Fixture guard: the model is gone.');

        // The Liberator never joins stardust_fields (ADR 0029), so it
        // reclaims the slot with the field row already cascaded away.
        $this->setTombstonedAt($slotId, '2020-01-01 00:00:00');
        $this->makeLiberator()->tick();

        self::assertSame('free', $this->fetchSlotAssignment($slotId)['status']);
    }

    /**
     * The final chunk re-asserts severance rather than trusting it. This
     * seeds the exact anomaly ADR 0038 measured: a `tombstoned` slot row
     * that still carries a `field_id` re-breaks the cascade with errno
     * 1451 — and by then every earlier chunk has already committed its
     * deletes, with no dead-letter route out.
     */
    public function testTheFinalChunkReAssertsSeveranceWhenASlotHasReacquiredAFieldId(): void
    {
        $this->provisionPage(['i_str_01']);
        $modelId = $this->createModel(1, 'invoice');
        $fieldId = $this->createField($modelId, 'string', true, 'shape');
        $this->reserveSlotFor($fieldId);
        $this->seedEntry(1, $modelId, ['shape' => 'round']);

        self::assertTrue($this->makeDeleteModelInitiator()->initiate(1, $modelId));

        // Re-plant the field_id the initiator cleared.
        $slotId = (int) $this->pdo->query(
            "SELECT id FROM stardust_slot_assignments WHERE slot_column = 'i_str_01'"
        )->fetchColumn();
        $this->pdo->exec(
            "UPDATE stardust_slot_assignments SET field_id = {$fieldId} WHERE id = {$slotId}"
        );

        $this->drainModelPurge(2);

        self::assertNull($this->fetchModelRowOrNull($modelId), 'The cascade must still succeed.');
        self::assertNull($this->fetchSlotAssignment($slotId)['field_id']);
    }

    public function testTheFinalChunkReAssertsSeveranceWhenAFieldHasLostItsMarker(): void
    {
        $modelId = $this->createModel(1, 'invoice');
        $fieldId = $this->createField($modelId, 'string', false, 'colour');
        $this->seedEntry(1, $modelId, ['colour' => 'red']);

        self::assertTrue($this->makeDeleteModelInitiator()->initiate(1, $modelId));

        $this->pdo->exec(
            "UPDATE stardust_fields SET deleted_at = NULL, is_filterable = 1 WHERE id = {$fieldId}"
        );

        $this->drainModelPurge(2);

        self::assertNull($this->fetchModelRowOrNull($modelId));
        self::assertNull($this->fetchFieldRowOrNull($fieldId));
    }

    /**
     * The escaped `LIKE`. `job_name` is operator-supplied for Backfill
     * Pump jobs and `_` is a single-character wildcard, so without the
     * escape this operator job is claimable — and the consequence here is
     * an unrecoverable DELETE, not a spurious rewrite.
     */
    public function testAnEscapedLikeDoesNotClaimAnOperatorNamedJob(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO backfill_checkpoints'
            . ' (job_name, last_processed_id, status, started_at, updated_at)'
            . " VALUES (?, 0, 'running', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $stmt->execute(['deleteXmodelY_rebuild']);

        self::assertNull(
            (new ModelDeleteCheckpointRepository($this->pdo))->loadOneClaimable(),
            'An operator-named job must not be claimable by the model purge.',
        );
        self::assertSame(TickOutcome::IDLE, $this->runModelPurgeTick());
    }

    /**
     * A model whose entries were seeded with a *later* id range than a
     * sibling's, so the chunk cursor genuinely has to walk past the
     * sibling's rows without touching them.
     */
    public function testTheCursorSkipsInterleavedRowsOfOtherModels(): void
    {
        $target = $this->createModel(1, 'target');
        $this->createField($target, 'string', false, 'colour');
        $sibling = $this->createModel(1, 'sibling');
        $this->createField($sibling, 'string', false, 'colour');

        $siblingIds = [];
        for ($i = 1; $i <= 6; $i++) {
            $this->seedEntry(1, $target, ['colour' => "t{$i}"]);
            $siblingIds[] = $this->seedEntry(1, $sibling, ['colour' => "s{$i}"]);
        }

        self::assertTrue($this->makeDeleteModelInitiator()->initiate(1, $target));
        $this->drainModelPurge(2);

        self::assertSame(0, $this->countEntryRows(1, $target));
        self::assertSame(6, $this->countEntryRows(1, $sibling));
        foreach ($siblingIds as $id) {
            self::assertNotNull($this->fetchEntryRowOrNull($id));
        }
    }

    /**
     * ADR 0045 at the engine's SECOND tombstone site. The final chunk
     * re-asserts severance for a slot that regained a live status during
     * the drain window, and that re-assertion must clear the sweep
     * annotations exactly as `LiveSlotTombstoner` does — otherwise a
     * model deletion is a route back to the stale-cursor exposure.
     */
    public function testTheFinalChunksReAssertedTombstoneClearsTheSweepAnnotations(): void
    {
        $this->provisionPage(['i_str_01']);
        $modelId = $this->createModel(1, 'invoice');
        $fieldId = $this->createField($modelId, 'string', true, 'shape');
        $this->reserveSlotFor($fieldId);
        $entryId = $this->seedEntry(1, $modelId, ['shape' => 'round']);

        self::assertTrue($this->makeDeleteModelInitiator()->initiate(1, $modelId));

        $slotId = (int) $this->pdo->query(
            "SELECT id FROM stardust_slot_assignments WHERE slot_column = 'i_str_01'"
        )->fetchColumn();

        // Resurrect the slot to a live status with dirty annotations —
        // the anomaly the final chunk exists to re-assert against.
        $stmt = $this->pdo->prepare(
            'UPDATE stardust_slot_assignments'
            . " SET status = 'assigned', field_id = ?,"
            . '     sweep_cursor_id = ?, sweep_gap_count = 9'
            . ' WHERE id = ?'
        );
        $stmt->execute([$fieldId, $entryId, $slotId]);

        $this->drainModelPurge(2);

        $slot = $this->fetchSlotAssignment($slotId);
        self::assertSame('tombstoned', $slot['status']);
        self::assertNull($slot['sweep_cursor_id']);
        self::assertSame(0, (int) $slot['sweep_gap_count']);
    }

    /**
     * The other side of the same guard. A slot that is ALREADY
     * `tombstoned` when the purge runs may be mid-sweep, and its cursor
     * is correct for that sweep — the purge must not restart it. The
     * `status IN ('assigned','backfilling','ready')` predicate is what
     * makes the reset above safe, and this is the assertion that keeps
     * someone from widening it.
     */
    public function testThePurgeDoesNotResetAnAlreadyTombstonedSlotsCursor(): void
    {
        $this->provisionPage(['i_str_01']);
        $modelId = $this->createModel(1, 'invoice');
        $fieldId = $this->createField($modelId, 'string', true, 'shape');
        $this->reserveSlotFor($fieldId);
        $entryId = $this->seedEntry(1, $modelId, ['shape' => 'round']);

        $slotId = (int) $this->pdo->query(
            "SELECT id FROM stardust_slot_assignments WHERE slot_column = 'i_str_01'"
        )->fetchColumn();

        self::assertTrue($this->makeDeleteModelInitiator()->initiate(1, $modelId));

        // A sweep is under way: the slot is tombstoned and has walked
        // as far as $entryId.
        $stmt = $this->pdo->prepare(
            'UPDATE stardust_slot_assignments SET sweep_cursor_id = ?, sweep_gap_count = 2 WHERE id = ?'
        );
        $stmt->execute([$entryId, $slotId]);

        $this->drainModelPurge(2);

        $slot = $this->fetchSlotAssignment($slotId);
        self::assertSame('tombstoned', $slot['status']);
        self::assertSame($entryId, (int) $slot['sweep_cursor_id']);
        self::assertSame(2, (int) $slot['sweep_gap_count']);
    }
}
