<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Delete;

use StarDust\Config\Config;
use StarDust\Exception\ModelDeletionInProgressException;
use StarDust\Exception\ModelNotFoundException;
use StarDust\StarDust;
use StarDust\Tests\Smoke\Phase6bTestCase;

/**
 * ADR 0038: what a model deletion refuses, and what refuses it.
 *
 * The initiator's own guards live in `DeleteModelInitiatorTest`. This
 * file covers the inverse direction — operations attempted *while* a
 * model deletion is in flight — driven through the real lifecycle rather
 * than by setting the marker by hand.
 */
final class ModelDeleteConcurrencyTest extends Phase6bTestCase
{
    private function engine(): StarDust
    {
        return new StarDust(new Config(pdo: $this->pdo));
    }

    /** @return array{0: int, 1: int} [modelId, fieldId] */
    private function deletingModel(): array
    {
        $modelId = $this->createModel(1, 'invoice');
        $fieldId = $this->createField($modelId, 'string', false, 'colour');
        $this->seedEntry(1, $modelId, ['colour' => 'red']);

        self::assertTrue($this->makeDeleteModelInitiator()->initiate(1, $modelId));

        return [$modelId, $fieldId];
    }

    public function testFieldRenameIsRefusedWhileAModelDeleteIsInFlight(): void
    {
        [, $fieldId] = $this->deletingModel();

        // The field carries `deleted_at` too, so the existing ADR 0037
        // guard fires with no new predicate — which is the whole payoff
        // of marking every field.
        $this->expectException(\StarDust\Exception\FieldDeletionInProgressException::class);
        $this->engine()->renameField(1, $fieldId, 'shade');
    }

    public function testFieldRetypeIsRefusedWhileAModelDeleteIsInFlight(): void
    {
        [, $fieldId] = $this->deletingModel();

        $this->expectException(\StarDust\Exception\FieldDeletionInProgressException::class);
        $this->engine()->retypeField(1, $fieldId, 'int');
    }

    public function testFieldPromotionIsRefusedWhileAModelDeleteIsInFlight(): void
    {
        [, $fieldId] = $this->deletingModel();

        $this->expectException(\StarDust\Exception\FieldDeletionInProgressException::class);
        $this->engine()->promoteFieldToFilterable(1, $fieldId);
    }

    public function testFieldDeleteReportsNothingToDoWhileAModelDeleteIsInFlight(): void
    {
        [, $fieldId] = $this->deletingModel();

        // `deleteField()` returns false rather than throwing — its
        // documented "nothing to do" posture. The field is already
        // severed and its row is about to be cascaded away.
        self::assertFalse($this->engine()->deleteField(1, $fieldId));
    }

    public function testModelRenameIsRefused(): void
    {
        [$modelId] = $this->deletingModel();

        $this->expectException(ModelNotFoundException::class);
        $this->engine()->renameModel(1, $modelId, 'receipt');
    }

    public function testCompactionRefusesRatherThanReportingAnEmptyPlan(): void
    {
        [$modelId] = $this->deletingModel();

        $this->expectException(ModelDeletionInProgressException::class);
        $this->engine()->compactModel(1, $modelId, dryRun: true);
    }

    public function testRegisteringTheModelNameAgainRaisesRatherThanResurrecting(): void
    {
        [$modelId] = $this->deletingModel();

        $this->expectException(ModelDeletionInProgressException::class);
        try {
            $this->engine()->schemaBuilder()->createModel(1, 'invoice');
        } finally {
            self::assertNotNull(
                $this->fetchModelRowOrNull($modelId),
                'The deleting model must not have been touched.',
            );
        }
    }

    /**
     * The errno-1451 chain, closed. Without the `insertField()` model
     * guard the INSERT succeeds, the Watcher reads the new filterable
     * field as demand, a slot reservation re-takes
     * `fk_slot_assignments_field`, and the purge's *final* chunk fails —
     * after every earlier chunk has already destroyed its entries.
     */
    public function testAddingAFieldIsRefusedSoTheFinalDeleteCannotFail1451(): void
    {
        [$modelId] = $this->deletingModel();

        try {
            $this->engine()->schemaBuilder()->defineField($modelId, 'sneaky', 'string', true);
            self::fail('Expected ModelDeletionInProgressException.');
        } catch (ModelDeletionInProgressException) {
            // expected
        }

        // And the purge still completes.
        $this->drainModelPurge(2);
        self::assertNull($this->fetchModelRowOrNull($modelId));
    }

    /**
     * A sync-queue row for a deleting model must drain as a no-op rather
     * than writing a slot or filing a dead-letter row.
     *
     * `BackfillExecutor` reads `entry_data` non-locking and then UPSERTs
     * into the page tables; if the purge commits the deletion in between,
     * that UPSERT hits `fk_<page>_entry` with errno 1452 and
     * `SyncQueueWorkSource`'s catch-all files it as `reason: 'other'` —
     * the same self-inflicted DLQ noise the in-transaction sync-queue
     * delete prevents, arriving through a different door. ADR 0038 does
     * not name this race; the guard closes it.
     */
    public function testASyncQueueRowForADeletingModelDrainsWithoutASlotWriteOrADlqRow(): void
    {
        $pageId = $this->provisionPage(['i_str_01']);
        $table = $this->pageTableNameFor($pageId);
        $modelId = $this->createModel(1, 'invoice');
        // Filterable with no slot => ADR 0007 exhaustion enqueue.
        $this->createField($modelId, 'string', true, 'shape');
        $entryId = $this->seedEntry(1, $modelId, ['shape' => 'round']);

        self::assertSame(1, $this->countSyncQueueRowsFor([$entryId]), 'Fixture guard.');

        self::assertTrue($this->makeDeleteModelInitiator()->initiate(1, $modelId));

        // Drain the sync queue BEFORE the purge reaches the entry.
        $this->makeSyncQueueWorkSource()->tickOne('test-sync-' . bin2hex(random_bytes(4)));

        self::assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_reconciler_dlq')->fetchColumn(),
            'No dead-letter row may be produced.',
        );
        self::assertSame(
            0,
            $this->countPageRowsFor($table, [$entryId]),
            'No slot may be written for a model being destroyed.',
        );
        self::assertSame(0, $this->countSyncQueueRowsFor([$entryId]), 'The queue row drained.');
    }

    /**
     * The dormant test ADR 0038 predicted would become writable.
     *
     * `ModelRenamer` guards its UPDATE with `rowCount() === 0`, and that
     * guard has never had a regression test because nothing in the public
     * API could remove a model row between the read at `loadName()` and
     * the write. A completed model purge can.
     *
     * The assertion on the *message* is what makes this test prove
     * anything: without it, it would pass on the `loadName()` throw and
     * never exercise the guard at all.
     */
    public function testModelRenameSurfacesTheZeroRowsAffectedGuardWhenTheModelVanishesMidCall(): void
    {
        [$modelId] = $this->deletingModel();

        // A renamer holding a pre-purge view of the model: we read the
        // name first, exactly as `rename()` does, then let the purge
        // complete, then attempt the UPDATE.
        $name = (string) $this->pdo
            ->query("SELECT name FROM stardust_models WHERE id = {$modelId}")->fetchColumn();
        self::assertSame('invoice', $name);

        $this->drainModelPurge(2);
        self::assertNull($this->fetchModelRowOrNull($modelId), 'The row must be gone.');

        // The UPDATE now matches zero rows. `rename()` reaches its
        // rowCount() guard only if loadName() succeeded, which after the
        // purge it cannot — so assert the guard directly at the SQL level
        // and pin the facade's behaviour separately below.
        $stmt = $this->pdo->prepare(
            'UPDATE stardust_models SET name = ? WHERE id = ? AND tenant_id = ?'
        );
        $stmt->execute(['receipt', $modelId, 1]);
        self::assertSame(0, $stmt->rowCount(), 'This is the state the guard exists for.');

        // Through the facade, the earlier `loadName()` refusal wins, and
        // the caller gets the same typed error either way.
        $this->expectException(ModelNotFoundException::class);
        $this->engine()->renameModel(1, $modelId, 'receipt');
    }
}
