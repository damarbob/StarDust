<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Delete;

use StarDust\Chronicler\HeaderResolver;
use StarDust\Config\Config;
use StarDust\Exception\UnknownFieldException;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Read\EntryQuery;
use StarDust\Reconciler\TickOutcome;
use StarDust\StarDust;
use StarDust\Tests\Smoke\Phase6bTestCase;
use StarDust\Write\EntryPayload;

/**
 * The mid-delete window — the part of ADR 0037 a naive implementation
 * gets wrong silently.
 *
 * Every test here deliberately stops the purge half-drained, so some
 * rows still physically carry the deleted field's key. The contract is
 * that **no first-class read surface can tell**: severance is immediate
 * and total, and only a raw table dump or a JSON export artifact shows
 * the residue.
 */
final class DeleteWindowTest extends Phase6bTestCase
{
    private function engine(): StarDust
    {
        return new StarDust(new Config(pdo: $this->pdo));
    }

    /**
     * Seeds `$count` JSON-only entries, deletes the field, and drains
     * exactly one chunk of `$chunk` rows.
     *
     * @return array{0: int, 1: int, 2: list<int>}  [modelId, fieldId, entryIds]
     */
    private function halfPurged(int $count = 6, int $chunk = 2): array
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'colour');
        $this->createField($modelId, 'string', false, 'shape');

        $entryIds = [];
        for ($i = 1; $i <= $count; $i++) {
            $entryIds[] = $this->seedEntry(1, $modelId, ['colour' => "c{$i}", 'shape' => 'round']);
        }

        self::assertTrue($this->makeDeleteFieldInitiator()->initiate(1, $fieldId));
        self::assertSame(TickOutcome::WORK_DONE, $this->runDeleteTick(null, $chunk));

        return [$modelId, $fieldId, $entryIds];
    }

    /**
     * The fixture must actually be half-purged, or every assertion below
     * passes vacuously.
     */
    public function testFixtureLeavesResidueInStorage(): void
    {
        [, , $entryIds] = $this->halfPurged();

        $purged = $this->rawPayload($entryIds[0]);
        $residual = $this->rawPayload($entryIds[5]);

        self::assertIsArray($purged);
        self::assertIsArray($residual);
        self::assertArrayNotHasKey('colour', $purged, 'Row behind the cursor is purged.');
        self::assertArrayHasKey('colour', $residual, 'Row ahead of the cursor still carries the key.');
    }

    public function testPaginatedReadsOmitTheFieldOnPurgedAndUnpurgedRowsAlike(): void
    {
        [$modelId] = $this->halfPurged();

        $page = $this->engine()->read(new EntryQuery(tenantId: 1, modelId: $modelId, pageSize: 50));

        self::assertCount(6, $page->rows);
        foreach ($page->rows as $entry) {
            self::assertArrayNotHasKey(
                'colour',
                $entry->fields,
                'The registry mapping is severed; nothing may resolve the field.',
            );
            self::assertSame('round', $entry->fields['shape'], 'Siblings keep working.');
        }
    }

    /**
     * The point read returns the stored payload verbatim, so without
     * `SnapshotEntry::canonicalisePayloadKeys()` stripping the key it
     * would disagree with `read()` for the whole window.
     */
    public function testPointReadAgreesWithPaginatedReadDuringTheWindow(): void
    {
        [$modelId, , $entryIds] = $this->halfPurged();
        $engine = $this->engine();

        $page = $engine->read(new EntryQuery(tenantId: 1, modelId: $modelId, pageSize: 50));
        $byId = [];
        foreach ($page->rows as $entry) {
            $byId[$entry->id] = $entry->fields;
        }

        foreach ($entryIds as $entryId) {
            $point = $engine->get(1, $entryId);
            self::assertNotNull($point);
            self::assertArrayNotHasKey(
                'colour',
                $point->fields,
                'get() must not leak a deleted field the paginated read hides.',
            );
            self::assertSame(
                array_keys($byId[$entryId]),
                array_keys($point->fields),
                'The two read surfaces must agree on the key set.',
            );
        }
    }

    /**
     * `selectFields` is caller-supplied and is *not* validated against
     * the snapshot, so it is the one route by which a deleted field's
     * name can re-enter the assembler after `SlotResolver` excluded it.
     * Without the filter in `ResultAssembler` this returns the residual
     * value for every row the purge has not reached — the field reads as
     * gone through a default read and present through an explicit one.
     */
    public function testExplicitlySelectingTheDeletedFieldDoesNotLeakResidualValues(): void
    {
        [$modelId, , $entryIds] = $this->halfPurged();
        // A row the purge has NOT reached, so the value is still stored.
        $unpurged = $entryIds[5];
        self::assertArrayHasKey('colour', (array) $this->rawPayload($unpurged));

        $page = $this->engine()->read(new EntryQuery(
            tenantId:     1,
            modelId:      $modelId,
            selectFields: ['colour', 'shape'],
            pageSize:     50,
        ));

        foreach ($page->rows as $entry) {
            self::assertArrayNotHasKey('colour', $entry->fields);
            self::assertSame(['shape'], array_keys($entry->fields));
        }
    }

    /**
     * Fail loudly, not quietly. A rejected filter tells the caller
     * immediately; a filter that silently matched nothing would look
     * like an empty result set.
     */
    public function testFilteringOnTheDeletedFieldIsRejected(): void
    {
        [$modelId] = $this->halfPurged();

        $this->expectException(UnknownFieldException::class);
        $this->engine()->read(new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
            filter: LeafNode::local('colour', 'eq', 'c1'),
            pageSize: 10,
        ));
    }

    /**
     * A client that has not caught up and still sends the deleted key
     * must have it dropped, not stored. Storing it would resurrect the
     * key on a row the purge cursor has already passed, where nothing
     * would ever remove it again.
     */
    public function testWritingWithTheDeletedKeyDropsItSilently(): void
    {
        [$modelId] = $this->halfPurged();

        $result = $this->engine()->write(new EntryPayload(
            tenantId: 1,
            modelId: $modelId,
            fields: ['colour' => 'resurrected', 'shape' => 'square'],
        ));

        self::assertSame(
            ['shape' => 'square'],
            $this->rawPayload($result->entryId),
            'PayloadSplitter must treat the deleted name as an unknown key.',
        );
    }

    /**
     * The same hazard on the update path, which additionally has
     * `withClearedSlots()` to worry about.
     */
    public function testUpdatingWithTheDeletedKeyDropsItSilently(): void
    {
        [$modelId, , $entryIds] = $this->halfPurged();
        // Pick a row the purge has NOT reached, so a resurrection here
        // would be indistinguishable from residue.
        $target = $entryIds[5];

        $this->engine()->updateEntry(1, $target, ['colour' => 'resurrected', 'shape' => 'square']);

        self::assertSame(['shape' => 'square'], $this->rawPayload($target));
    }

    /**
     * No slot may be reserved for a deleting field. If one were, it
     * would re-take the RESTRICT foreign key and the purge's final
     * DELETE would fail with errno 1451 — permanently, since nothing
     * retries it.
     */
    public function testAWriteDuringTheWindowNeverReservesASlotForTheField(): void
    {
        $this->provisionPage(['i_str_01', 'i_str_02']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'colour');
        $this->reserveSlotFor($fieldId);
        $this->seedEntry(1, $modelId, ['colour' => 'red']);

        $this->makeDeleteFieldInitiator()->initiate(1, $fieldId);

        $this->engine()->write(new EntryPayload(
            tenantId: 1,
            modelId: $modelId,
            fields: ['colour' => 'blue'],
        ));

        self::assertNull(
            $this->fetchLiveSlotForField($fieldId),
            'The field must hold no live slot at any point after severance.',
        );

        // And the purge must therefore still be able to finish.
        $this->runDeleteTick();
        self::assertNull($this->fetchFieldRowOrNull($fieldId));
    }

    public function testIntrospectionOmitsTheFieldImmediately(): void
    {
        [$modelId] = $this->halfPurged();

        $description = $this->engine()->describeModel(1, $modelId);

        self::assertNotNull($description);
        self::assertNull($description->field('colour'), 'describeModel() must not report it.');
        self::assertNotNull($description->field('shape'));
    }

    public function testCsvExportHeaderOmitsTheField(): void
    {
        [$modelId] = $this->halfPurged();

        $header = (new HeaderResolver($this->pdo))->resolve(1, $modelId);

        self::assertSame(['shape'], $header);
    }
}
