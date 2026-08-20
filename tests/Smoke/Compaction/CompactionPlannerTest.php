<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Compaction;

use PHPUnit\Framework\TestCase;
use StarDust\Compaction\CompactionPlanner;
use StarDust\Compaction\ModelSlot;
use StarDust\Exception\CompactionCapacityException;

/**
 * ADR 0033 compaction planning, in isolation.
 *
 * Intentionally DB-free: the planner is pure policy over an
 * already-loaded registry projection, the same posture as
 * `ProvisioningPlannerTest` and `SpreadFormulaTest`. The arithmetic here
 * decides whether a real data migration runs, so it is worth pinning
 * separately from the machinery that executes it.
 */
final class CompactionPlannerTest extends TestCase
{
    /** A model already at its family-ceiling floor wants nothing. */
    public function testModelAtTheFloorPlansNothing(): void
    {
        $plan = CompactionPlanner::plan(1, 7, [
            $this->slot(1, 'str', pageId: 5),
            $this->slot(2, 'str', pageId: 5),
        ], [5 => ['str' => 10]]);

        self::assertTrue($plan->isNoop());
        self::assertSame(1, $plan->pagesBefore);
        self::assertSame(1, $plan->theoreticalMinPages);
        self::assertSame(2, $plan->noopCount);
        self::assertSame(0, $plan->excessPagesRemoved());
    }

    /** A model with no live filterable slot is trivially compact. */
    public function testEmptyModelPlansNothing(): void
    {
        $plan = CompactionPlanner::plan(1, 7, [], []);

        self::assertTrue($plan->isNoop());
        self::assertSame(0, $plan->pagesBefore);
        self::assertSame([], $plan->targetPageIds);
    }

    /**
     * The headline case: three fields scattered over three pages that
     * could all fit on one.
     */
    public function testConsolidatesAScatteredModelOntoOnePage(): void
    {
        $plan = CompactionPlanner::plan(1, 7, [
            $this->slot(1, 'str', pageId: 1),
            $this->slot(2, 'str', pageId: 2),
            $this->slot(3, 'str', pageId: 3),
        ], [
            1 => ['str' => 20],   // roomiest — should win
            2 => ['str' => 1],
            3 => ['str' => 1],
        ]);

        self::assertFalse($plan->isNoop());
        self::assertSame(3, $plan->pagesBefore);
        self::assertSame(1, $plan->theoreticalMinPages);
        self::assertSame([1], $plan->targetPageIds);
        self::assertSame(1, $plan->pagesAfter());
        self::assertSame(2, $plan->excessPagesRemoved());

        // The field already on the target page is a no-op, not a move.
        self::assertSame(2, $plan->relocationCount());
        self::assertSame(1, $plan->noopCount);
        foreach ($plan->relocations as $relocation) {
            self::assertSame(1, $relocation->toPageId);
            self::assertNotSame(1, $relocation->fromPageId);
        }
    }

    /**
     * Family ceilings are a floor the planner must respect: 30 string
     * fields cannot fit on one page, so the target set is two and the
     * plan does not chase an impossible minimum.
     */
    public function testRespectsFamilyCeilings(): void
    {
        $slots = [];
        for ($i = 1; $i <= 30; $i++) {
            $slots[] = $this->slot($i, 'str', pageId: $i <= 10 ? 1 : ($i <= 20 ? 2 : 3));
        }

        $plan = CompactionPlanner::plan(1, 7, $slots, [
            1 => ['str' => 15],
            2 => ['str' => 15],
            3 => ['str' => 15],
        ]);

        self::assertSame(2, $plan->theoreticalMinPages, '30 string fields need at least 2 pages.');
        self::assertSame(2, $plan->pagesAfter());
        self::assertSame(3, $plan->pagesBefore);
    }

    /**
     * The capacity check is the whole reason a plan can be refused, and
     * it must refuse *before* anything moves. Here the free capacity is
     * too thin anywhere to consolidate.
     */
    public function testThrowsWhenNoSmallerPageSetCanAbsorbTheMoves(): void
    {
        $this->expectException(CompactionCapacityException::class);

        CompactionPlanner::plan(1, 7, [
            $this->slot(1, 'str', pageId: 1),
            $this->slot(2, 'str', pageId: 2),
            $this->slot(3, 'str', pageId: 3),
        ], [
            // No page has room for anyone else's field.
            1 => ['str' => 0],
            2 => ['str' => 0],
            3 => ['str' => 0],
        ]);
    }

    /**
     * Double-occupancy, the trap ADR 0033 names: the slot a field is
     * about to vacate becomes `tombstoned`, not free, so it must not be
     * counted as capacity. The repository only ever counts rows that are
     * `free` now — this asserts the planner does not add them back.
     */
    public function testDoesNotSpendCapacityItIsAboutToRelease(): void
    {
        $this->expectException(CompactionCapacityException::class);

        // Page 1 hosts one field and has exactly zero free slots. If the
        // planner wrongly assumed page 2's field could take the slot
        // page 1's field is vacating, it would plan a move that the
        // pinned reservation then refuses mid-flight.
        CompactionPlanner::plan(1, 7, [
            $this->slot(1, 'str', pageId: 1),
            $this->slot(2, 'str', pageId: 2),
        ], [
            1 => ['str' => 0],
            2 => ['str' => 0],
        ]);
    }

    /** A relocation must target a page with capacity in its own family. */
    public function testRelocationRespectsSlotFamilies(): void
    {
        $plan = CompactionPlanner::plan(1, 7, [
            $this->slot(1, 'str', pageId: 1),
            $this->slot(2, 'int', pageId: 2),
        ], [
            1 => ['str' => 5, 'int' => 5],
            2 => ['str' => 0, 'int' => 0],
        ]);

        self::assertSame([1], $plan->targetPageIds);
        self::assertSame(1, $plan->relocationCount());
        self::assertSame('int', $plan->relocations[0]->slotType);
        self::assertSame(2, $plan->relocations[0]->fromPageId);
        self::assertSame(1, $plan->relocations[0]->toPageId);
    }

    /**
     * A family with no capacity anywhere blocks the whole plan, even
     * when another family would fit — partial compaction is not a thing.
     */
    public function testOneStarvedFamilyBlocksThePlan(): void
    {
        $this->expectException(CompactionCapacityException::class);

        CompactionPlanner::plan(1, 7, [
            $this->slot(1, 'str', pageId: 1),
            $this->slot(2, 'str', pageId: 2),
            $this->slot(3, 'dt', pageId: 2),
        ], [
            1 => ['str' => 5, 'dt' => 0],   // no datetime room
            2 => ['str' => 0, 'dt' => 0],
        ]);
    }

    /** Planning is deterministic — a re-run must produce the same plan. */
    public function testPlanningIsDeterministic(): void
    {
        $slots = [
            $this->slot(1, 'str', pageId: 3),
            $this->slot(2, 'str', pageId: 1),
            $this->slot(3, 'str', pageId: 2),
        ];
        $capacity = [1 => ['str' => 9], 2 => ['str' => 9], 3 => ['str' => 9]];

        $first  = CompactionPlanner::plan(1, 7, $slots, $capacity);
        $second = CompactionPlanner::plan(1, 7, $slots, $capacity);

        self::assertSame($first->targetPageIds, $second->targetPageIds);
        self::assertSame(
            array_map(static fn ($r) => [$r->fieldId, $r->toPageId], $first->relocations),
            array_map(static fn ($r) => [$r->fieldId, $r->toPageId], $second->relocations),
        );
    }

    private function slot(int $fieldId, string $slotType, int $pageId): ModelSlot
    {
        return new ModelSlot(
            fieldId: $fieldId,
            fieldName: 'field_' . $fieldId,
            slotType: $slotType,
            pageId: $pageId,
            slotColumn: sprintf('i_%s_%02d', $slotType, $fieldId),
        );
    }
}
