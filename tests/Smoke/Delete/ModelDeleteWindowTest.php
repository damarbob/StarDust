<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Delete;

use StarDust\Config\Config;
use StarDust\Exception\ModelDeletionInProgressException;
use StarDust\Exception\UnknownFieldException;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Read\EntryQuery;
use StarDust\Reconciler\TickOutcome;
use StarDust\StarDust;
use StarDust\Tests\Smoke\Phase6bTestCase;
use StarDust\Write\EntryPayload;

/**
 * ADR 0038: the mid-purge window.
 *
 * Every test here stops the drain half-way, so some entries are
 * physically gone and some are still there. The contract is that **no
 * first-class surface can tell** — severance is immediate and total,
 * and only a raw table dump shows the residue.
 *
 * ## The vacuous-pass hazard is worse here than for a field
 *
 * `DeleteWindowTest` guards against one way a window test can pass for
 * free: the purge already finished. A *model* window test has a second,
 * and it is far easier to hit — **a model that never existed behaves
 * identically.** `read()` on an unknown model id returns an empty page
 * and `describeModel()` returns null today, with no code change at all.
 * So a file whose assertions are all "the API does not show it" proves
 * nothing whatsoever.
 *
 * {@see self::halfPurgedModel()} therefore asserts five things about its
 * own fixture before returning, and
 * {@see self::testTheDarkAssertionsCannotDistinguishAModelThatNeverExisted()}
 * records the reason in code rather than in a comment.
 */
final class ModelDeleteWindowTest extends Phase6bTestCase
{
    private function engine(): StarDust
    {
        return new StarDust(new Config(pdo: $this->pdo));
    }

    /**
     * Seeds a model with entries, deletes it, and drains exactly one
     * chunk — leaving the purge genuinely half-done.
     *
     * @return array{0: int, 1: list<int>, 2: list<int>} [modelId, fieldIds, entryIds]
     */
    private function halfPurgedModel(int $count = 6, int $chunk = 2): array
    {
        $modelId = $this->createModel(1, 'invoice');
        $fieldIds = [
            $this->createField($modelId, 'string', false, 'colour'),
            $this->createField($modelId, 'string', false, 'shape'),
        ];

        $entryIds = [];
        for ($i = 1; $i <= $count; $i++) {
            $entryIds[] = $this->seedEntry(1, $modelId, [
                'colour' => "c{$i}",
                'shape'  => 'round',
            ]);
        }

        self::assertTrue($this->makeDeleteModelInitiator()->initiate(1, $modelId));
        self::assertSame(TickOutcome::WORK_DONE, $this->runModelPurgeTick(null, $chunk));

        // === the five assertions that make every test below non-vacuous ===
        self::assertNull(
            $this->fetchEntryRowOrNull($entryIds[0]),
            'Rows behind the cursor must be physically gone.',
        );
        self::assertNotNull(
            $this->fetchEntryRowOrNull($entryIds[$count - 1]),
            'Rows ahead of the cursor must still be physically present — otherwise the purge'
            . ' finished and every "the API does not show it" assertion below is vacuous.',
        );
        self::assertNotNull(
            $this->fetchModelRowOrNull($modelId),
            'The model row must still exist, or these tests are indistinguishable from'
            . ' reading a model id that was never registered.',
        );
        self::assertSame(2, $this->countFieldRows($modelId), 'The fields must not have cascaded yet.');
        self::assertSame('running', $this->fetchModelDeleteCheckpoint($modelId)['status']);

        return [$modelId, $fieldIds, $entryIds];
    }

    /**
     * The negative control. Recorded as a test rather than a comment so
     * the reason `halfPurgedModel()` asserts residue cannot be lost.
     */
    public function testTheDarkAssertionsCannotDistinguishAModelThatNeverExisted(): void
    {
        $page = $this->engine()->read(new EntryQuery(tenantId: 1, modelId: 987_654, pageSize: 50));

        self::assertSame([], $page->rows);
        self::assertNull($this->engine()->describeModel(1, 987_654));
    }

    public function testPaginatedReadsReturnNothingForPurgedAndUnpurgedRowsAlike(): void
    {
        [$modelId] = $this->halfPurgedModel();

        $page = $this->engine()->read(new EntryQuery(tenantId: 1, modelId: $modelId, pageSize: 50));

        self::assertSame([], $page->rows);
        self::assertNull($page->nextCursor);
    }

    public function testGetReturnsNullForAnEntryStillPhysicallyPresent(): void
    {
        [, , $entryIds] = $this->halfPurgedModel();
        $survivor = $entryIds[count($entryIds) - 1];

        self::assertNotNull($this->fetchEntryRowOrNull($survivor), 'Fixture guard.');
        self::assertNull($this->engine()->get(1, $survivor));
    }

    public function testWritingToTheModelThrowsRatherThanStripping(): void
    {
        [$modelId] = $this->halfPurgedModel();
        $before = $this->countEntryRows(1, $modelId);

        try {
            $this->engine()->write(new EntryPayload(1, $modelId, ['colour' => 'new']));
            self::fail('Expected ModelDeletionInProgressException.');
        } catch (ModelDeletionInProgressException) {
            // expected
        }

        // A strip would have quietly succeeded and left a row the purge
        // cursor has already passed — a permanent orphan.
        self::assertSame($before, $this->countEntryRows(1, $modelId));
    }

    public function testUpdatingAnUnpurgedEntryThrowsAndLeavesThePayloadByteIdentical(): void
    {
        [, , $entryIds] = $this->halfPurgedModel();
        $survivor = $entryIds[count($entryIds) - 1];

        $before = (string) $this->pdo
            ->query('SELECT fields FROM entry_data WHERE id = ' . $survivor)->fetchColumn();

        try {
            $this->engine()->updateEntry(1, $survivor, ['colour' => 'changed']);
            self::fail('Expected ModelDeletionInProgressException.');
        } catch (ModelDeletionInProgressException) {
            // expected
        }

        self::assertSame(
            $before,
            (string) $this->pdo
                ->query('SELECT fields FROM entry_data WHERE id = ' . $survivor)->fetchColumn(),
        );
    }

    public function testDeleteEntryReturnsFalseAndDoesNotStampDeletedAt(): void
    {
        [, , $entryIds] = $this->halfPurgedModel();
        $survivor = $entryIds[count($entryIds) - 1];

        self::assertFalse($this->engine()->deleteEntry(1, $survivor));
        self::assertNull($this->fetchEntryRowOrNull($survivor)['deleted_at']);
    }

    public function testIntrospectionOmitsTheModelImmediately(): void
    {
        [$modelId] = $this->halfPurgedModel();

        self::assertNull($this->engine()->describeModel(1, $modelId));

        $ids = array_map(
            static fn (object $m): int => $m->modelId,
            $this->engine()->listModels(1),
        );
        self::assertNotContains($modelId, $ids);
    }

    /**
     * A filter is rejected rather than going dark, and that asymmetry
     * with the unfiltered read is correct.
     *
     * Severance marks every field, so `FieldRefResolver` cannot resolve
     * the leaf and pre-flight raises before the driver's dark check is
     * ever reached. That is the same answer an unknown model id gives
     * today — its fields are unknown too — so the model still cannot be
     * distinguished from one that never existed. And per ADR 0036's
     * ruling, a rejected filter loses nothing while a rejected write
     * loses data, which is why only the write path is loud on purpose.
     */
    public function testFilteringOnAFieldOfTheModelIsRejected(): void
    {
        [$modelId] = $this->halfPurgedModel();

        $this->expectException(UnknownFieldException::class);
        $this->engine()->read(new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
            filter: LeafNode::local('colour', 'eq', 'c1'),
            pageSize: 50,
        ));
    }

    /**
     * The documented carve-out, pinned so it stays a decision. A raw
     * table dump shows the rows the purge has not reached — and the
     * fixture assertions above are what prove there are any.
     */
    public function testARawTableDumpStillShowsTheUnpurgedRows(): void
    {
        [$modelId, , $entryIds] = $this->halfPurgedModel();

        $remaining = $this->countEntryRows(1, $modelId);
        self::assertGreaterThan(0, $remaining, 'Residue is the point of this test.');
        self::assertLessThan(count($entryIds), $remaining, 'And some rows really were purged.');
    }

    /** Draining the rest closes the window and completes the deletion. */
    public function testDrainingTheRestCompletesTheDeletion(): void
    {
        [$modelId, $fieldIds] = $this->halfPurgedModel();

        $this->drainModelPurge(2);

        self::assertSame(0, $this->countEntryRows(1, $modelId));
        self::assertNull($this->fetchModelRowOrNull($modelId));
        self::assertNull($this->fetchModelDeleteCheckpoint($modelId));
        foreach ($fieldIds as $fieldId) {
            self::assertNull($this->fetchFieldRowOrNull($fieldId));
        }
    }

    /** The name is held for the whole window, and freed by completion. */
    public function testTheModelNameBecomesReusableOnlyOnceThePurgeCompletes(): void
    {
        [$modelId] = $this->halfPurgedModel();

        try {
            $this->engine()->schemaBuilder()->createModel(1, 'invoice');
            self::fail('Expected ModelDeletionInProgressException during the window.');
        } catch (ModelDeletionInProgressException) {
            // expected
        }

        $this->drainModelPurge(2);

        $fresh = $this->engine()->schemaBuilder()->createModel(1, 'invoice');
        self::assertGreaterThan(0, $fresh->modelId);
        self::assertNotSame($modelId, $fresh->modelId, 'A new model, not a resurrection.');
    }
}
