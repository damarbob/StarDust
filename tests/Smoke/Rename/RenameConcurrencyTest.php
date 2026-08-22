<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Rename;

use StarDust\Config\Config;
use StarDust\Exception\RenameInProgressException;
use StarDust\StarDust;
use StarDust\Tests\Smoke\Phase6bTestCase;

/**
 * A field may have at most one lifecycle in flight.
 *
 * This is a correctness guard rather than hygiene: the retype backfill
 * locates values by field NAME, so if it ran during a rename window
 * every row behind the rename cursor would read as "value absent" and
 * its slot would be written NULL — silently, with no `coercion_null`
 * event, because no coercion was ever attempted.
 */
final class RenameConcurrencyTest extends Phase6bTestCase
{
    /** @return array{0: int, 1: int} [modelId, fieldId] */
    private function filterableField(): array
    {
        $this->provisionPage(['i_str_01', 'i_int_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'title');
        $this->reserveSlotFor($fieldId);
        return [$modelId, $fieldId];
    }

    public function testRetypeIsRefusedWhileARenameIsRunning(): void
    {
        [, $fieldId] = $this->filterableField();
        $this->makeRenameInitiator()->initiate(1, $fieldId, 'headline');

        $this->expectException(RenameInProgressException::class);
        $this->makeRetypeInitiator()->initiate(1, $fieldId, 'int', null);
    }

    public function testDemotionIsRefusedWhileARenameIsRunning(): void
    {
        [, $fieldId] = $this->filterableField();
        $this->makeRenameInitiator()->initiate(1, $fieldId, 'headline');

        $this->expectException(RenameInProgressException::class);
        $this->makeRetypeInitiator()->initiate(1, $fieldId, null, false);
    }

    /**
     * The reason the guard lives inside `RetypeInitiator::runTuple()`
     * rather than on the `StarDust` facade: compaction reaches the
     * initiator through `initiateRelocation()`, so a facade-level check
     * would leave it as an unguarded back door into the same NULL-ing
     * bug.
     */
    public function testCompactionRelocationIsRefusedWhileARenameIsRunning(): void
    {
        [, $fieldId] = $this->filterableField();
        $pageId = $this->provisionPage(['i_str_01']);
        $this->makeRenameInitiator()->initiate(1, $fieldId, 'headline');

        $this->expectException(RenameInProgressException::class);
        $this->makeRetypeInitiator()->initiateRelocation(1, $fieldId, $pageId);
    }

    /**
     * The facade-level proof. Needs a genuinely *fragmented* model —
     * with nothing to compact the planner returns an empty plan and
     * `initiateRelocation()` is never reached, so the test would pass
     * vacuously.
     *
     * `SlotReserver` packs onto the oldest page first (ADR 0032), so a
     * fragmented model cannot be built through the normal reservation
     * path at all; the second slot is moved by direct registry UPDATE,
     * the same documented bypass `SpreadSamplerTest` and
     * `SlotAffinityTest` use.
     */
    public function testCompactModelSurfacesTheGuardThroughTheFacade(): void
    {
        $page1 = $this->provisionPage(['i_str_01', 'i_str_02']);
        $page2 = $this->provisionPage(['i_str_01', 'i_str_02']);
        $modelId = $this->createModel(1);
        $alpha = $this->createField($modelId, 'string', true, 'alpha');
        $beta  = $this->createField($modelId, 'string', true, 'beta');
        $this->reserveSlotFor($alpha);
        $this->reserveSlotFor($beta);

        // Strand beta on page 2 so the model spans two pages where one
        // would do — excess_pages = 1, which is what gives compaction
        // something to relocate.
        $this->pdo->exec(
            "UPDATE stardust_slot_assignments SET field_id = NULL, status = 'free'"
            . " WHERE field_id = {$beta}"
        );
        $this->pdo->exec(
            "UPDATE stardust_slot_assignments SET field_id = {$beta}, status = 'assigned'"
            . " WHERE page_id = {$page2} AND slot_column = 'i_str_01'"
        );
        self::assertSame(
            [$page1, $page2],
            $this->distinctPagesFor($modelId),
            'Precondition: the model must actually be fragmented.',
        );

        $this->makeRenameInitiator()->initiate(1, $beta, 'gamma');

        $engine = new StarDust(new Config(pdo: $this->pdo));

        $this->expectException(RenameInProgressException::class);
        $engine->compactModel(1, $modelId);
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

    /**
     * The slot itself is untouched by a rename, so a filterable field
     * keeps its live slot and stays queryable throughout — the guard
     * exists to stop a *second lifecycle*, not to quarantine the field.
     */
    public function testTheFieldsSlotSurvivesTheRenameUntouched(): void
    {
        [, $fieldId] = $this->filterableField();
        $before = $this->liveSlotRowFor($fieldId);

        $this->makeRenameInitiator()->initiate(1, $fieldId, 'headline');

        self::assertEquals(
            $before,
            $this->liveSlotRowFor($fieldId),
            'A rename must not tombstone, reserve, or relocate any slot.',
        );
    }

    /** @return array{id: int, page_id: int, slot_column: string, status: string}|null */
    private function liveSlotRowFor(int $fieldId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, page_id, slot_column, status FROM stardust_slot_assignments'
            . ' WHERE field_id = ?'
        );
        $stmt->execute([$fieldId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id'          => (int) $row['id'],
            'page_id'     => (int) $row['page_id'],
            'slot_column' => (string) $row['slot_column'],
            'status'      => (string) $row['status'],
        ];
    }
}
