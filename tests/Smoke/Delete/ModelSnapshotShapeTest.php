<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Delete;

use StarDust\Read\EntryQuery;
use StarDust\Tests\Smoke\Phase6bTestCase;

/**
 * ADR 0038 stage 1: the shape of the rewritten read and write snapshots.
 *
 * Both `SlotResolver::load()` and `LiveSlotMap::loadFor()` used to drive
 * off `stardust_fields WHERE model_id = ?`. They now drive off
 * `stardust_models` and outer-join out to the fields, because a query
 * rooted at the fields cannot tell a model with no fields from a model
 * that does not exist — and the ADR 0038 deletion marker has to be
 * readable in exactly that case.
 *
 * That rewrite has two silent failure modes and this file is the only
 * thing in the suite that catches either.
 *
 * 1. **The all-null outer-join row.** `stardust_fields.name` is NOT NULL,
 *    so `(string) $row['field_name']` yields `''` rather than tripping
 *    anything, and a phantom descriptor is registered under the empty
 *    string. `ResultAssembler` then emits `'' => null` on every row of
 *    every read of that model. No pre-existing test reads a fieldless
 *    model, so nothing else would fail.
 * 2. **Alias collapse.** `f.deleted_at` and `m.deleted_at` under one
 *    `FETCH_ASSOC` key keep only the last, silently conflating "this
 *    field is being deleted" with "this model is". The two happen to
 *    agree during a model purge, which is exactly why the mixed case
 *    needs pinning.
 *
 * The third assertion here is the invariant the whole change rests on:
 * **a missing `stardust_models` row must never read as "deleting".**
 * Several existing fixtures seed `entry_data` with a fabricated
 * `model_id`, and an inner join would change all of them at once.
 */
final class ModelSnapshotShapeTest extends Phase6bTestCase
{
    public function testAFieldlessModelYieldsAnEmptyFieldMapRatherThanAPhantomField(): void
    {
        $modelId = $this->createModel(1, 'fieldless');

        $snapshot = $this->snapshotFor($modelId);

        self::assertSame([], $snapshot->fieldsByName);
        self::assertSame([], $snapshot->pendingDeletionNames);
        self::assertArrayNotHasKey('', $snapshot->fieldsByName);
    }

    /**
     * The end-to-end consequence of trap 1, asserted through the public
     * read surface rather than the snapshot — this is what a consumer
     * would actually have seen.
     *
     * The projection is **empty**, not `['unregistered' => …]`:
     * `ResultAssembler` projects `array_keys($snapshot->fieldsByName)`,
     * so a paginated read returns registered fields only and an
     * unregistered payload key is never surfaced here. That is the
     * pre-existing strict-projection rule, unchanged by this work — and
     * it is exactly what makes the phantom dangerous. `[]` is the whole
     * correct answer for a fieldless model, so a stray `'' => null` is
     * the only thing that could ever appear, on every row, with nothing
     * legitimate alongside it to make the anomaly look plausible.
     */
    public function testReadingAFieldlessModelProjectsNothingRatherThanAPhantomColumn(): void
    {
        $modelId = $this->createModel(1, 'fieldless_read');
        $entryId = $this->seedEntry(1, $modelId, ['unregistered' => 'kept']);
        $this->seedEntry(1, $modelId, ['unregistered' => 'also kept']);

        $page = $this->reader()->read(new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
            pageSize: 10,
        ));

        self::assertCount(2, $page->rows);
        foreach ($page->rows as $entry) {
            self::assertArrayNotHasKey('', $entry->fields, 'A phantom empty-named field leaked into the result.');
            self::assertSame([], $entry->fields, 'A fieldless model projects no fields at all.');
        }

        // The point read is the surface that does return the payload
        // verbatim (ADR 0013), so it is the one that would show a phantom
        // key alongside real data rather than on its own.
        $entry = $this->reader()->get(1, $entryId);
        self::assertNotNull($entry);
        self::assertArrayNotHasKey('', $entry->fields);
        self::assertSame(['unregistered' => 'kept'], $entry->fields);
    }

    public function testAFieldlessModelIsNotReportedAsDeleting(): void
    {
        $modelId = $this->createModel(1, 'fieldless_live');

        self::assertFalse($this->snapshotFor($modelId)->isModelDeleting());
        self::assertFalse($this->liveSlotMapFor($modelId)->isModelDeleting());
    }

    /**
     * Absent is not deleting. Pinned once, centrally, because several
     * fixtures elsewhere seed `entry_data` rows whose `model_id` has no
     * registry row at all, and an inner join would silently change every
     * one of them.
     */
    public function testAModelIdWithNoRegistryRowIsNotReportedAsDeleting(): void
    {
        $absent = 987_654;
        self::assertNull($this->fetchModelRowOrNull($absent));

        $snapshot = $this->snapshotFor($absent);
        self::assertFalse($snapshot->isModelDeleting());
        self::assertSame([], $snapshot->fieldsByName);

        $map = $this->liveSlotMapFor($absent);
        self::assertFalse($map->isModelDeleting());
        self::assertFalse($map->isKnown('anything'));
    }

    /**
     * The alias-collapse regression. One field marked deleted, the model
     * NOT marked: the two markers must read independently.
     *
     * Under a shared `deleted_at` key this returns the wrong pair in one
     * direction or the other depending on join order — and during a real
     * model purge both are true, so only the mixed case exposes it.
     */
    public function testTheFieldMarkerAndTheModelMarkerAreReadIndependently(): void
    {
        $modelId = $this->createModel(1, 'mixed_markers');
        $keptId  = $this->createField($modelId, 'string', false, 'kept');
        $goneId  = $this->createField($modelId, 'string', false, 'gone');
        self::assertNotSame($keptId, $goneId);

        $this->pdo->exec(
            'UPDATE stardust_fields SET deleted_at = UTC_TIMESTAMP() WHERE id = ' . $goneId
        );

        $snapshot = $this->snapshotFor($modelId);
        self::assertTrue($snapshot->hasPendingDeletions(), 'The field marker must be seen.');
        self::assertSame(['gone'], $snapshot->pendingDeletionNames);
        self::assertFalse($snapshot->isModelDeleting(), 'The model is NOT being deleted.');
        self::assertSame(['kept'], array_keys($snapshot->fieldsByName));

        $map = $this->liveSlotMapFor($modelId);
        self::assertTrue($map->hasPendingDeletions());
        self::assertFalse($map->isModelDeleting());
        self::assertTrue($map->isKnown('kept'));
        self::assertFalse($map->isKnown('gone'));
    }

    /** The inverse mixed case: model marked, fields untouched. */
    public function testTheModelMarkerIsReadWhenNoFieldIsMarked(): void
    {
        $modelId = $this->createModel(1, 'model_only_marker');
        $this->createField($modelId, 'string', false, 'kept');

        $this->markModelDeleted($modelId);

        $snapshot = $this->snapshotFor($modelId);
        self::assertTrue($snapshot->isModelDeleting());
        self::assertFalse($snapshot->hasPendingDeletions(), 'No field marker was set.');
        self::assertSame(['kept'], array_keys($snapshot->fieldsByName));

        $map = $this->liveSlotMapFor($modelId);
        self::assertTrue($map->isModelDeleting());
        self::assertFalse($map->hasPendingDeletions());
    }

    /** The `LiveSlotMap` twin of the phantom-field trap. */
    public function testAWriteToAFieldlessModelStoresUnknownKeysWithoutAPhantomField(): void
    {
        $modelId = $this->createModel(1, 'fieldless_write');

        $entryId = $this->seedEntry(1, $modelId, ['unregistered' => 'kept']);

        self::assertSame(['unregistered' => 'kept'], $this->rawPayload($entryId));
        self::assertFalse($this->liveSlotMapFor($modelId)->isKnown(''));
        self::assertSame([], $this->liveSlotMapFor($modelId)->all());
    }

    /**
     * The third `LiveSlotMap::loadFor()` caller — the Reconciler's
     * sync-queue drain helper. It resolves `model_id` from the row rather
     * than from a caller, so it is the one path that could be handed a
     * fieldless model without anything upstream noticing.
     */
    public function testABackfillOfAnEntryInAFieldlessModelIsANoop(): void
    {
        $modelId = $this->createModel(1, 'fieldless_backfill');
        $entryId = $this->seedEntry(1, $modelId, ['unregistered' => 'kept']);

        $result = $this->makeBackfillExecutor()->backfill($entryId);

        self::assertSame([], $result->slotsWritten);
        self::assertSame([], $result->stillUnmapped);
        self::assertSame(['unregistered' => 'kept'], $this->rawPayload($entryId));
    }
}
