<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Delete;

use Psr\Log\NullLogger;
use StarDust\Clock\SystemClock;
use StarDust\Compaction\FieldRelocation;
use StarDust\Config\Config;
use StarDust\Exception\FieldDeletionInProgressException;
use StarDust\Schema\SchemaBuilder;
use StarDust\StarDust;
use StarDust\Tests\Smoke\Phase6bTestCase;
use StarDust\Watcher\PendingDemandReader;

/**
 * ADR 0037 makes deletion the third field lifecycle, so exclusivity is
 * now an N×N obligation rather than a pair.
 *
 * The delete-refuses-others direction (rename/retype in flight blocks a
 * delete) lives in {@see DeleteFieldInitiatorTest}. This file covers the
 * inverse — a delete in flight blocks everything else — plus the name
 * reuse that `ux_fields_model_name` makes unavoidable during the window.
 */
final class DeleteConcurrencyTest extends Phase6bTestCase
{
    /** @return array{0: int, 1: int} [modelId, fieldId] */
    private function deletingFilterableField(): array
    {
        $this->provisionPage(['i_str_01', 'i_int_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'colour');
        $this->reserveSlotFor($fieldId);
        // Seed an entry so the purge does not finish on the first tick
        // and the window stays open for the duration of the test.
        $this->seedEntry(1, $modelId, ['colour' => 'red']);

        self::assertTrue($this->makeDeleteFieldInitiator()->initiate(1, $fieldId));

        return [$modelId, $fieldId];
    }

    public function testRenameIsRefusedWhileADeleteIsInFlight(): void
    {
        [, $fieldId] = $this->deletingFilterableField();

        $this->expectException(FieldDeletionInProgressException::class);
        $this->makeRenameInitiator()->initiate(1, $fieldId, 'shade');
    }

    public function testRetypeIsRefusedWhileADeleteIsInFlight(): void
    {
        [, $fieldId] = $this->deletingFilterableField();

        $this->expectException(FieldDeletionInProgressException::class);
        $this->makeRetypeInitiator()->initiate(1, $fieldId, 'int', null);
    }

    public function testPromotionIsRefusedWhileADeleteIsInFlight(): void
    {
        [, $fieldId] = $this->deletingFilterableField();

        $this->expectException(FieldDeletionInProgressException::class);
        $this->makeRetypeInitiator()->initiate(1, $fieldId, null, true);
    }

    public function testDemotionIsRefusedWhileADeleteIsInFlight(): void
    {
        [, $fieldId] = $this->deletingFilterableField();

        $this->expectException(FieldDeletionInProgressException::class);
        $this->makeRetypeInitiator()->initiate(1, $fieldId, null, false);
    }

    /**
     * **Compaction can never encounter a field being deleted, so the
     * guard is unreachable through this path — deliberately.**
     *
     * This started out asserting the opposite, by analogy with
     * `RenameConcurrencyTest::testCompactModelSurfacesTheGuardThroughTheFacade`,
     * and failed. The analogy does not hold: a *renaming* field keeps
     * its live slot, so the planner still sees it, whereas a *deleting*
     * field has been tombstoned and demoted, and
     * `CompactionRepository::loadModelSlots()` requires
     * `status IN ('assigned','ready') AND is_filterable = 1`. It is
     * doubly invisible.
     *
     * So the useful assertion is that compaction quietly does the right
     * thing: it compacts what remains and ignores the field on its way
     * out. The `deleted_at` guard in `RetypeInitiator::loadField()` is
     * still worth having — it closes the plan-then-delete race, where a
     * deletion commits between the planner reading the registry and
     * `initiateRelocation()` acting on it — but that race is not
     * constructible from a single-threaded test.
     */
    public function testCompactionIgnoresADeletingFieldRatherThanRefusing(): void
    {
        $page1 = $this->provisionPage(['i_str_01', 'i_str_02']);
        $page2 = $this->provisionPage(['i_str_01', 'i_str_02']);
        $modelId = $this->createModel(1);
        $alpha = $this->createField($modelId, 'string', true, 'alpha');
        $beta  = $this->createField($modelId, 'string', true, 'beta');
        $gamma = $this->createField($modelId, 'string', true, 'gamma');
        $this->reserveSlotFor($alpha);
        $this->reserveSlotFor($beta);
        $this->reserveSlotFor($gamma);

        // Strand beta and gamma on page 2, so the model spans two pages
        // where one would do. SlotReserver packs onto the oldest page
        // first, so a deliberately fragmented model cannot be built
        // through the reservation path — same documented bypass as
        // `SlotAffinityTest`.
        foreach ([$beta => 'i_str_01', $gamma => 'i_str_02'] as $fieldId => $column) {
            $this->pdo->exec(
                "UPDATE stardust_slot_assignments SET field_id = NULL, status = 'free'"
                . " WHERE field_id = {$fieldId}"
            );
            $this->pdo->exec(
                "UPDATE stardust_slot_assignments SET field_id = {$fieldId}, status = 'assigned'"
                . " WHERE page_id = {$page2} AND slot_column = '{$column}'"
            );
        }
        self::assertSame(
            [$page1, $page2],
            $this->distinctPagesFor($modelId),
            'Precondition: the model must actually be fragmented.',
        );

        $this->seedEntry(1, $modelId, ['alpha' => 'a', 'beta' => 'b', 'gamma' => 'c']);
        self::assertTrue($this->makeDeleteFieldInitiator()->initiate(1, $gamma));

        $plan = (new StarDust(new Config(pdo: $this->pdo)))
            ->compactModel(1, $modelId, dryRun: true);

        $names = array_map(
            static fn (FieldRelocation $r): string => $r->fieldName,
            $plan->relocations,
        );
        self::assertNotContains('gamma', $names, 'A deleting field holds no live slot to relocate.');
        self::assertContains('beta', $names, 'The rest of the model still compacts.');
    }

    /**
     * `ux_fields_model_name` is unconditional, so a field being deleted
     * still holds its name until the purge lands.
     *
     * The dangerous failure here is silent, not loud: `defineField()` is
     * get-or-create, so without the `deleted_at` predicate on its lookup
     * it would hand back the id of the field being deleted — and the
     * caller would adopt a field whose values are actively being erased
     * and whose row is about to be dropped.
     */
    public function testRegisteringTheNameAgainRaisesRatherThanResurrecting(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'colour');
        $this->seedEntry(1, $modelId, ['colour' => 'red']);
        self::assertTrue($this->makeDeleteFieldInitiator()->initiate(1, $fieldId));

        try {
            $this->schemaBuilder()->defineField($modelId, 'colour', 'string');
            self::fail('Expected FieldDeletionInProgressException.');
        } catch (FieldDeletionInProgressException $e) {
            self::assertStringContainsString('colour', $e->getMessage());
        }
    }

    /**
     * And once the purge lands, the name is free again.
     */
    public function testTheNameBecomesReusableOnceThePurgeCompletes(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'colour');
        $this->seedEntry(1, $modelId, ['colour' => 'red']);
        $this->makeDeleteFieldInitiator()->initiate(1, $fieldId);
        $this->runDeleteTick();
        self::assertNull($this->fetchFieldRowOrNull($fieldId));

        $newId = $this->schemaBuilder()->defineField($modelId, 'colour', 'string');

        self::assertNotSame($fieldId, $newId, 'A genuinely new field, not the old row.');
        self::assertSame('colour', $this->fetchFieldRow($newId)['name']);
        self::assertNull($this->fetchFieldRow($newId)['deleted_at']);
    }

    /**
     * A rename targeting the name a deleting field still holds gets the
     * deletion error rather than a raw duplicate-key `PDOException` —
     * the two need different responses (wait, versus pick another name).
     */
    public function testRenamingOntoADeletingFieldsNameIsRejectedTypeAware(): void
    {
        $modelId = $this->createModel(1);
        $victim = $this->createField($modelId, 'string', false, 'colour');
        $other  = $this->createField($modelId, 'string', false, 'shape');
        $this->seedEntry(1, $modelId, ['colour' => 'red', 'shape' => 'round']);

        self::assertTrue($this->makeDeleteFieldInitiator()->initiate(1, $victim));

        $this->expectException(FieldDeletionInProgressException::class);
        $this->makeRenameInitiator()->initiate(1, $other, 'colour');
    }

    /**
     * The delete must not become an unguarded back door in the other
     * direction either: a field mid-delete is not a field the Watcher
     * should be provisioning capacity for.
     */
    public function testADeletingFilterableFieldRegistersNoWatcherDemand(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'colour');
        $this->seedEntry(1, $modelId, ['colour' => 'red']);

        // Precondition: an unmapped filterable field IS demand.
        self::assertGreaterThan(
            0,
            $this->makePendingDemandReader()->read()->totalWaiters(),
            'Precondition: the field must register as demand before deletion.',
        );

        self::assertTrue($this->makeDeleteFieldInitiator()->initiate(1, $fieldId));

        self::assertSame(
            0,
            $this->makePendingDemandReader()->read()->totalWaiters(),
            'A field being deleted must never make the Watcher provision a page.',
        );
    }

    private function schemaBuilder(): SchemaBuilder
    {
        return new SchemaBuilder(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: new NullLogger(),
        );
    }

    private function makePendingDemandReader(): PendingDemandReader
    {
        return new PendingDemandReader($this->pdo);
    }

    /** @return list<int> */
    private function distinctPagesFor(int $modelId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT a.page_id FROM stardust_slot_assignments a'
            . ' JOIN stardust_fields f ON f.id = a.field_id'
            . " WHERE f.model_id = ? AND a.status IN ('assigned','ready')"
            . ' ORDER BY a.page_id'
        );
        $stmt->execute([$modelId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
