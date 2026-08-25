<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Delete;

use StarDust\Config\Config;
use StarDust\Exception\ModelDeletionInProgressException;
use StarDust\Export\ExportJobRequest;
use StarDust\Read\EntryQuery;
use StarDust\Schema\FieldDefinition;
use StarDust\StarDust;
use StarDust\Tests\Smoke\Phase6bTestCase;
use StarDust\Write\EntryPayload;

/**
 * ADR 0038 stage 2: every surface severed by the model marker.
 *
 * These tests set `stardust_models.deleted_at` directly with
 * `markModelDeleted()` rather than going through the (not yet built)
 * initiator. That is deliberate and is the design's gift: the drain
 * marker is a plain column, not a checkpoint row, so the whole severance
 * contract is testable with no lifecycle code at all.
 *
 * Two behaviours are asserted throughout, and the split is the ADR's:
 *
 * - **Writes are refused, loudly.** This *inverts* ADR 0037, where a
 *   deleted field's key is stripped rather than rejected. For a model
 *   there is no residual valid entry to preserve — the row would land
 *   behind the purge cursor (making acceptance a lie) or ahead of it
 *   (becoming a permanent orphan).
 * - **Reads go dark, silently.** `read()` returns an empty page and
 *   `get()` returns null, indistinguishable from a model that never
 *   existed — the posture `describeModel()` already takes for tenant
 *   isolation.
 */
final class ModelDeleteGuardsTest extends Phase6bTestCase
{
    private function engine(): StarDust
    {
        return new StarDust(new Config(pdo: $this->pdo));
    }

    /** @return array{0: int, 1: int} [modelId, entryId] */
    private function deletingModelWithAnEntry(): array
    {
        $modelId = $this->createModel(1, 'invoice');
        $this->createField($modelId, 'string', false, 'colour');
        $entryId = $this->seedEntry(1, $modelId, ['colour' => 'red']);

        $this->markModelDeleted($modelId);

        return [$modelId, $entryId];
    }

    // ---------------------------------------------------------------
    // Writes refuse
    // ---------------------------------------------------------------

    public function testWriteIsRefusedAndInsertsNothing(): void
    {
        [$modelId] = $this->deletingModelWithAnEntry();
        $before = $this->countEntryRows(1, $modelId);
        $queueBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_sync_queue')->fetchColumn();

        try {
            $this->engine()->write(new EntryPayload(
                tenantId: 1,
                modelId: $modelId,
                fields: ['colour' => 'blue'],
            ));
            self::fail('Expected ModelDeletionInProgressException.');
        } catch (ModelDeletionInProgressException) {
            // expected
        }

        self::assertSame($before, $this->countEntryRows(1, $modelId), 'No row may be inserted.');
        self::assertSame(
            $queueBefore,
            (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_sync_queue')->fetchColumn(),
            'No sync-queue row may be enqueued either.',
        );
    }

    public function testUpdateIsRefusedAndLeavesThePayloadByteIdentical(): void
    {
        [$modelId, $entryId] = $this->deletingModelWithAnEntry();
        $before = (string) $this->pdo
            ->query('SELECT fields FROM entry_data WHERE id = ' . $entryId)->fetchColumn();

        $this->expectException(ModelDeletionInProgressException::class);
        try {
            $this->engine()->updateEntry(1, $entryId, ['colour' => 'green']);
        } finally {
            self::assertSame(
                $before,
                (string) $this->pdo
                    ->query('SELECT fields FROM entry_data WHERE id = ' . $entryId)->fetchColumn(),
                'The stored payload must be untouched.',
            );
            self::assertFalse($this->pdo->inTransaction(), 'The write must have rolled back.');
        }
    }

    /**
     * A strip would have silently succeeded here, which is exactly the
     * ADR 0037 behaviour this rule inverts. Asserting the row count is
     * what distinguishes "refused" from "accepted with the key removed".
     */
    public function testTheRefusalIsARejectionRatherThanASilentStrip(): void
    {
        [$modelId] = $this->deletingModelWithAnEntry();
        $before = $this->countEntryRows(1, $modelId);

        try {
            $this->engine()->write(new EntryPayload(
                tenantId: 1,
                modelId: $modelId,
                fields: ['colour' => 'blue'],
            ));
        } catch (ModelDeletionInProgressException) {
            // expected
        }

        self::assertSame($before, $this->countEntryRows(1, $modelId));
    }

    public function testSyncBulkWriteRefusesBeforeAnyChunkCommits(): void
    {
        $liveId = $this->createModel(1, 'live_model');
        $this->createField($liveId, 'string', false, 'colour');
        [$deletingId] = $this->deletingModelWithAnEntry();

        $payloads = [];
        for ($i = 0; $i < 40; $i++) {
            $payloads[] = new EntryPayload(1, $liveId, ['colour' => "c{$i}"]);
        }
        // One offender, late in the batch.
        $payloads[] = new EntryPayload(1, $deletingId, ['colour' => 'bad']);

        $liveBefore = $this->countEntryRows(1, $liveId);

        $this->expectException(ModelDeletionInProgressException::class);
        try {
            $this->engine()->bulkWrite($payloads);
        } finally {
            // The point of the up-front check: without it the offender
            // reaches `EntryWriter` inside a chunk, `processChunk()`
            // catches the throw and rolls that whole chunk back, so the
            // good entries beside it are discarded and the caller gets a
            // per-chunk failureReason string rather than an exception.
            self::assertSame(
                $liveBefore,
                $this->countEntryRows(1, $liveId),
                'Nothing may commit — the refusal must precede every chunk.',
            );
        }
    }

    public function testAsyncBulkSubmitRefusesAndWritesNoArtifact(): void
    {
        $artifactDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'stardust-adr0038-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($artifactDir, 0o775, true));
        $liveId = $this->createModel(1, 'live_async');
        $this->createField($liveId, 'string', false, 'colour');
        [$deletingId] = $this->deletingModelWithAnEntry();

        $payloads = [];
        for ($i = 0; $i < 5; $i++) {
            $payloads[] = new EntryPayload(1, $liveId, ['colour' => "c{$i}"]);
        }
        $payloads[] = new EntryPayload(1, $deletingId, ['colour' => 'bad']);

        $engine = new StarDust(new Config(pdo: $this->pdo, artifactDir: $artifactDir));

        $this->expectException(ModelDeletionInProgressException::class);
        try {
            $engine->submitBulkWrite(1, $payloads);
        } finally {
            $files = array_values(array_diff((array) scandir($artifactDir), ['.', '..']));
            @rmdir($artifactDir);
            self::assertSame([], $files, 'A refused submission must leave no artifact on disk.');
            self::assertSame(
                0,
                (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_import_jobs')->fetchColumn(),
                'A refused submission must insert no import job.',
            );
        }
    }

    // ---------------------------------------------------------------
    // deleteEntry() returns false rather than throwing
    // ---------------------------------------------------------------

    public function testDeleteEntryReturnsFalseAndDoesNotStampDeletedAt(): void
    {
        [, $entryId] = $this->deletingModelWithAnEntry();

        self::assertFalse($this->engine()->deleteEntry(1, $entryId));

        $stamp = $this->pdo
            ->query('SELECT deleted_at FROM entry_data WHERE id = ' . $entryId)->fetchColumn();
        self::assertNull($stamp, 'Soft-deleting a row about to be hard-purged achieves nothing.');
    }

    // ---------------------------------------------------------------
    // Reads go dark
    // ---------------------------------------------------------------

    public function testReadReturnsAnEmptyPageEvenThoughTheRowsAreStillPresent(): void
    {
        [$modelId] = $this->deletingModelWithAnEntry();
        self::assertSame(1, $this->countEntryRows(1, $modelId), 'Fixture guard: the row is still there.');

        $page = $this->engine()->read(new EntryQuery(tenantId: 1, modelId: $modelId, pageSize: 50));

        self::assertSame([], $page->rows);
        self::assertNull($page->nextCursor);
    }

    /**
     * The match-all case specifically. `SearchService` resolves the
     * snapshot only when a filter is present, so a guard placed there
     * rather than in the driver would silently do nothing here.
     */
    public function testAnUnfilteredSearchAlsoGoesDark(): void
    {
        [$modelId] = $this->deletingModelWithAnEntry();

        $page = $this->engine()->read(new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
            filter: null,
            pageSize: 50,
        ));

        self::assertSame([], $page->rows);
    }

    public function testGetReturnsNullWhileTheRowIsStillPhysicallyPresent(): void
    {
        [, $entryId] = $this->deletingModelWithAnEntry();

        self::assertNull($this->engine()->get(1, $entryId));

        self::assertNotFalse(
            $this->pdo->query('SELECT id FROM entry_data WHERE id = ' . $entryId)->fetchColumn(),
            'The row is still physically present — only the API is dark.',
        );
    }

    /**
     * Without the ordering fix in `MysqlNativeDriver::get()`, a model
     * deletion marks every field, so `canonicalisePayloadKeys()` would
     * strip every key and hand back a real Entry with `fields: []` — a
     * positive claim that the entry exists.
     */
    public function testGetDoesNotReturnAnEntryWithAnEmptyFieldMap(): void
    {
        [, $entryId] = $this->deletingModelWithAnEntry();

        $entry = $this->engine()->get(1, $entryId);

        self::assertNotInstanceOf(\StarDust\Read\Entry::class, $entry);
        self::assertNull($entry);
    }

    // ---------------------------------------------------------------
    // Introspection and definition
    // ---------------------------------------------------------------

    public function testListModelsOmitsADeletingModel(): void
    {
        $liveId = $this->createModel(1, 'kept');
        [$deletingId] = $this->deletingModelWithAnEntry();

        $ids = array_map(
            static fn (object $m): int => $m->modelId,
            $this->engine()->listModels(1),
        );

        self::assertContains($liveId, $ids);
        self::assertNotContains($deletingId, $ids);
    }

    public function testDescribeModelReturnsNullForADeletingModel(): void
    {
        [$modelId] = $this->deletingModelWithAnEntry();

        self::assertNull($this->engine()->describeModel(1, $modelId));
    }

    public function testRegisteringTheNameAgainRaisesRatherThanResurrecting(): void
    {
        [$modelId] = $this->deletingModelWithAnEntry();

        try {
            $this->engine()->schemaBuilder()->createModel(1, 'invoice');
            self::fail('Expected ModelDeletionInProgressException.');
        } catch (ModelDeletionInProgressException $e) {
            self::assertStringContainsString('invoice', $e->getMessage());
        }

        self::assertSame(
            1,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM stardust_models WHERE tenant_id = 1 AND name = 'invoice'"
            )->fetchColumn(),
            'Get-or-create must not silently create a second model under the held name.',
        );
        self::assertNotNull($this->fetchModelRowOrNull($modelId));
    }

    /**
     * The errno-1451 chain closer, and the reason the model needs its own
     * marker rather than relying on the field ones: **a field created
     * after severance is not marked.**
     */
    public function testAddingAFieldToADeletingModelIsRefusedWithTheModelLevelError(): void
    {
        [$modelId] = $this->deletingModelWithAnEntry();

        try {
            $this->engine()->schemaBuilder()->defineField($modelId, 'brand_new', 'string', true);
            self::fail('Expected ModelDeletionInProgressException.');
        } catch (ModelDeletionInProgressException $e) {
            // The exception TYPE is the assertion. Reporting a
            // field-deletion error here would tell the caller to wait for
            // a name that is never coming back.
            self::assertStringContainsString((string) $modelId, $e->getMessage());
        }

        self::assertSame(
            0,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM stardust_fields WHERE model_id = {$modelId} AND name = 'brand_new'"
            )->fetchColumn(),
        );
    }

    /**
     * The consequence the guard above exists to prevent, asserted
     * directly: the Watcher must see no demand from a severed model, so
     * nothing can reserve it a slot and re-take the foreign key the
     * purge's final DELETE needs released.
     */
    public function testADeletingModelRegistersNoWatcherDemandEvenAfterAFieldInsertAttempt(): void
    {
        $modelId = $this->createModel(1, 'demand_model');
        $this->createField($modelId, 'string', true, 'filterable_one');

        $before = $this->makeUnmappedFilterableFieldCount();
        self::assertGreaterThan(0, $before, 'Fixture guard: the field must register as demand first.');

        $this->markModelDeleted($modelId);
        $this->pdo->exec(
            'UPDATE stardust_fields SET deleted_at = UTC_TIMESTAMP(), is_filterable = 0'
            . ' WHERE model_id = ' . $modelId
        );

        try {
            $this->engine()->schemaBuilder()->defineField($modelId, 'sneaky', 'string', true);
        } catch (ModelDeletionInProgressException) {
            // expected
        }

        self::assertSame(0, $this->makeUnmappedFilterableFieldCount());
    }

    private function makeUnmappedFilterableFieldCount(): int
    {
        return (int) $this->pdo->query(
            'SELECT COUNT(*) FROM stardust_fields f'
            . ' LEFT JOIN stardust_slot_assignments a ON a.field_id = f.id'
            . "   AND a.status IN ('assigned','backfilling','ready')"
            . ' WHERE f.is_filterable = 1 AND f.deleted_at IS NULL AND a.id IS NULL'
        )->fetchColumn();
    }

    // ---------------------------------------------------------------
    // Rename, compaction, export
    // ---------------------------------------------------------------

    public function testRenamingADeletingModelIsRefused(): void
    {
        [$modelId] = $this->deletingModelWithAnEntry();

        $this->expectException(\StarDust\Exception\ModelNotFoundException::class);
        $this->engine()->renameModel(1, $modelId, 'receipt');
    }

    /**
     * Negative space, and the assertion most likely to be "fixed" wrongly
     * later. `ux_models_tenant_name` is unconditional, so a deleting model
     * still holds its name — renaming another model onto it must raise
     * the typed conflict, not sail through into a raw errno 1062.
     */
    public function testRenamingAnotherModelOntoADeletingModelsNameStillRaisesTheTypedConflict(): void
    {
        $this->deletingModelWithAnEntry(); // registers 'invoice', now deleting
        $otherId = $this->createModel(1, 'other');

        $this->expectException(\StarDust\Exception\ModelNameConflictException::class);
        $this->engine()->renameModel(1, $otherId, 'invoice');
    }

    public function testCompactionRefusesRatherThanReportingASuccessfulEmptyPlan(): void
    {
        [$modelId] = $this->deletingModelWithAnEntry();

        $this->expectException(ModelDeletionInProgressException::class);
        $this->engine()->compactModel(1, $modelId, dryRun: true);
    }

    public function testCompactionRefusesOnTheExecutingPathToo(): void
    {
        [$modelId] = $this->deletingModelWithAnEntry();

        $this->expectException(ModelDeletionInProgressException::class);
        $this->engine()->compactModel(1, $modelId, dryRun: false);
    }

    public function testSubmitExportIsRefusedAndBurnsNoCapSlot(): void
    {
        [$modelId] = $this->deletingModelWithAnEntry();

        $this->expectException(ModelDeletionInProgressException::class);
        try {
            $this->engine()->submitExport(new ExportJobRequest(
                tenantId: 1,
                modelId: $modelId,
                format: 'csv',
            ));
        } finally {
            self::assertSame(
                0,
                (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_export_jobs')->fetchColumn(),
                'A refused submission must insert no job and burn no cap slot.',
            );
        }
    }

    // ---------------------------------------------------------------
    // The invariant that protects every pre-existing fixture
    // ---------------------------------------------------------------

    /**
     * **A missing `stardust_models` row must never read as "deleting".**
     *
     * `entry_data.model_id` is a deliberate logical-only reference with
     * no foreign key, so rows carrying a `model_id` with no registry row
     * are legal and several fixtures rely on it (`BootstrapTest`,
     * `EmptyTableGuardTest`, `Phase6aTestCase::seedSlotValues()`,
     * `Phase7TestCase::seedEntryDataBatch()`). Phrasing any guard as
     * "join to a live model" would change all of them at once, silently.
     *
     * One test walks every guarded surface so that regression is caught
     * here rather than somewhere unrelated.
     */
    public function testEveryGuardIgnoresAModelIdWithNoRegistryRow(): void
    {
        $absent = 987_654;
        self::assertNull($this->fetchModelRowOrNull($absent));

        // An entry_data row with a dangling model_id, as several fixtures build.
        $this->pdo->exec(
            'INSERT INTO entry_data (tenant_id, model_id, created_at, updated_at, fields)'
            . " VALUES (1, {$absent}, UTC_TIMESTAMP(), UTC_TIMESTAMP(), JSON_OBJECT('a', 'b'))"
        );
        $entryId = (int) $this->pdo->lastInsertId();

        // Reads: dark for a *deleting* model, but normal here.
        $page = $this->engine()->read(new EntryQuery(tenantId: 1, modelId: $absent, pageSize: 50));
        self::assertCount(1, $page->rows, 'An absent model still reads its orphaned rows.');
        self::assertNotNull($this->engine()->get(1, $entryId));

        // Writes: permitted, because absent is not deleting.
        $written = $this->engine()->write(new EntryPayload(1, $absent, ['a' => 'c']));
        self::assertGreaterThan(0, $written->entryId);

        // deleteEntry(): must still soft-delete. This is the one that
        // would silently break under an INNER join.
        self::assertTrue(
            $this->engine()->deleteEntry(1, $entryId),
            'deleteEntry() must still work for an entry whose model row does not exist.',
        );

        // Introspection: absent, not refused.
        self::assertNull($this->engine()->describeModel(1, $absent));

        // Export submission: no model-deletion refusal.
        $id = $this->engine()->submitExport(new ExportJobRequest(
            tenantId: 1,
            modelId: $absent,
            format: 'csv',
        ));
        self::assertGreaterThan(0, $id->jobId);
    }

    public function testSchemaBuilderStillCreatesFieldsOnALiveModel(): void
    {
        $model = $this->engine()->schemaBuilder()->createModel(1, 'still_fine', [
            new FieldDefinition('colour', 'string', isFilterable: false),
        ]);

        self::assertGreaterThan(0, $model->fieldId('colour'));
    }
}
