<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Rename;

use StarDust\Config\Config;
use StarDust\Exception\UnknownFieldException;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Read\EntryQuery;
use StarDust\Reconciler\TickOutcome;
use StarDust\StarDust;
use StarDust\Tests\Smoke\Phase6bTestCase;
use StarDust\Write\EntryPayload;

/**
 * The mid-rename window — the part of ADR 0036 that a naive
 * implementation gets wrong silently.
 *
 * Every test here deliberately stops the drain half-migrated, so some
 * rows carry the old payload key and some the new.
 */
final class RenameWindowTest extends Phase6bTestCase
{
    private function engine(): StarDust
    {
        return new StarDust(new Config(pdo: $this->pdo));
    }

    /**
     * Seeds `$count` JSON-only entries, renames, and drains exactly one
     * chunk of `$chunk` rows.
     *
     * @return array{0: int, 1: int}  [modelId, fieldId]
     */
    private function halfMigrated(int $count = 6, int $chunk = 2): array
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'title');
        for ($i = 1; $i <= $count; $i++) {
            $this->seedEntry(1, $modelId, ['title' => "v{$i}"]);
        }

        $this->makeRenameInitiator()->initiate(1, $fieldId, 'headline');
        self::assertSame(TickOutcome::WORK_DONE, $this->runRenameTick(null, $chunk));

        return [$modelId, $fieldId];
    }

    public function testReadsReturnTheNewNameForMigratedAndUnmigratedRowsAlike(): void
    {
        [$modelId] = $this->halfMigrated();

        $page = $this->engine()->read(new EntryQuery(tenantId: 1, modelId: $modelId, pageSize: 50));

        self::assertCount(6, $page->rows);
        foreach ($page->rows as $entry) {
            self::assertArrayHasKey('headline', $entry->fields);
            self::assertNotNull(
                $entry->fields['headline'],
                "Entry {$entry->id} read null — the read-path fallback is not covering the window.",
            );
            self::assertArrayNotHasKey('title', $entry->fields, 'The old name must not surface to callers.');
        }
    }

    /**
     * The point read returns the payload verbatim and loads no snapshot
     * of its own, so without an explicit fix it disagrees with `read()`
     * on the very same entry.
     */
    public function testPointReadAgreesWithPaginatedReadDuringTheWindow(): void
    {
        [$modelId] = $this->halfMigrated();

        $page = $this->engine()->read(new EntryQuery(tenantId: 1, modelId: $modelId, pageSize: 50));
        foreach ($page->rows as $entry) {
            $point = $this->engine()->get(1, $entry->id);
            self::assertNotNull($point);
            self::assertSame(
                $entry->fields['headline'],
                $point->fields['headline'] ?? null,
                "get() and read() disagree on entry {$entry->id}.",
            );
            self::assertArrayNotHasKey('title', $point->fields);
        }
    }

    public function testReadsStillResolveAfterTheWindowCloses(): void
    {
        [$modelId, $fieldId] = $this->halfMigrated();

        while ($this->runRenameTick(null, 2) === TickOutcome::WORK_DONE) {
        }
        self::assertNull($this->fetchFieldRow($fieldId)['previous_name']);

        $page = $this->engine()->read(new EntryQuery(tenantId: 1, modelId: $modelId, pageSize: 50));
        foreach ($page->rows as $entry) {
            self::assertNotNull($entry->fields['headline'] ?? null);
        }
    }

    /**
     * A client that has not redeployed keeps sending the old name. That
     * write must land under the NEW key: for a row the backfill cursor
     * has already passed, a stale key would never be migrated and the
     * value would vanish when `previous_name` is cleared.
     */
    public function testWritingWithTheOldNameMidWindowLandsUnderTheNewKey(): void
    {
        [$modelId, $fieldId] = $this->halfMigrated();

        $result = $this->engine()->write(new EntryPayload(
            tenantId: 1,
            modelId: $modelId,
            fields: ['title' => 'late-writer'],
        ));

        self::assertSame(
            ['headline' => 'late-writer'],
            $this->rawPayload($result->entryId),
            'The write path must canonicalise a pre-rename key before persisting.',
        );

        // And it must survive the rest of the drain plus the clear.
        while ($this->runRenameTick(null, 2) === TickOutcome::WORK_DONE) {
        }
        self::assertNull($this->fetchFieldRow($fieldId)['previous_name']);
        self::assertSame(['headline' => 'late-writer'], $this->rawPayload($result->entryId));

        $point = $this->engine()->get(1, $result->entryId);
        self::assertNotNull($point);
        self::assertSame('late-writer', $point->fields['headline']);
    }

    public function testUpdateWithTheOldNameDoesNotClearTheRenamedField(): void
    {
        [$modelId] = $this->halfMigrated();
        $entryId = $this->seedEntry(1, $modelId, ['title' => 'original']);

        $this->engine()->updateEntry(1, $entryId, ['title' => 'replaced']);

        self::assertSame(
            ['headline' => 'replaced'],
            $this->rawPayload($entryId),
            'A PUT using the old name must replace the renamed field, not orphan it.',
        );
    }

    /**
     * A rename never touches the slot, so a filter on the new name is
     * correct the instant the registry flips. A filter on the OLD name
     * is rejected on purpose — a rejected query loses nothing, whereas a
     * rejected write would lose data, so the asymmetry with the write
     * path above is deliberate.
     */
    public function testFilterOnTheNewNameWorksImmediatelyAndTheOldNameIsRejected(): void
    {
        $this->provisionPage(['i_str_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'title');
        $this->reserveSlotFor($fieldId);
        $this->seedEntry(1, $modelId, ['title' => 'findme']);
        $this->seedEntry(1, $modelId, ['title' => 'other']);

        $this->makeRenameInitiator()->initiate(1, $fieldId, 'headline');

        $hit = $this->engine()->read(new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
            filter: LeafNode::local('headline', 'eq', 'findme'),
            pageSize: 50,
        ));
        self::assertCount(1, $hit->rows, 'Filtering on the new name must work before the backfill runs.');

        $this->expectException(UnknownFieldException::class);
        $this->engine()->read(new EntryQuery(
            tenantId: 1,
            modelId: $modelId,
            filter: LeafNode::local('title', 'eq', 'findme'),
            pageSize: 50,
        ));
    }
}
