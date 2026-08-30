<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use Psr\Log\NullLogger;
use StarDust\Config\Config;
use StarDust\Exception\FieldNotSortableException;
use StarDust\Exception\UnknownFieldException;
use StarDust\Read\EntryQuery;
use StarDust\Read\SortDirection;
use StarDust\Read\SortSpec;
use StarDust\StarDust;

/**
 * Ordering acceptance for `EntryQuery::$sort`.
 *
 * Covers the two intrinsic targets and all four slot families, both
 * directions, plus the NULL-slot placement and the rejection rules that
 * make ADR 0004's long-vacuous sort clause real.
 */
final class SortOrderTest extends ReadPathTestCase
{
    /**
     * @return list<int>
     */
    private function idsInOrder(?SortSpec $sort, int $modelId, int $tenantId = 1): array
    {
        $page = $this->reader()->read(new EntryQuery(
            tenantId: $tenantId,
            modelId:  $modelId,
            pageSize: 50,
            sort:     $sort,
        ));
        return array_map(static fn ($e): int => $e->id, $page->rows);
    }

    public function testDefaultOrderIsUnchangedWhenNoSortIsGiven(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        $a = $this->seedEntry(1, $modelId, [$names['string'] => 'c']);
        $b = $this->seedEntry(1, $modelId, [$names['string'] => 'a']);
        $c = $this->seedEntry(1, $modelId, [$names['string'] => 'b']);

        // Insertion order, exactly as every read produced before sorting
        // existed. This is the regression guard on the default.
        self::assertSame([$a, $b, $c], $this->idsInOrder(null, $modelId));
    }

    public function testSortsByStringSlotBothDirections(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        $c = $this->seedEntry(1, $modelId, [$names['string'] => 'charlie']);
        $a = $this->seedEntry(1, $modelId, [$names['string'] => 'alpha']);
        $b = $this->seedEntry(1, $modelId, [$names['string'] => 'bravo']);

        self::assertSame(
            [$a, $b, $c],
            $this->idsInOrder(SortSpec::byField($names['string']), $modelId),
        );
        self::assertSame(
            [$c, $b, $a],
            $this->idsInOrder(SortSpec::byField($names['string'], SortDirection::Desc), $modelId),
        );
    }

    public function testSortsByIntSlotNumericallyNotLexicographically(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        $nine   = $this->seedEntry(1, $modelId, [$names['int'] => 9]);
        $eighty = $this->seedEntry(1, $modelId, [$names['int'] => 80]);
        $seven  = $this->seedEntry(1, $modelId, [$names['int'] => 7]);

        // 7, 9, 80 — a lexicographic sort would say 7, 80, 9, which is
        // what ordering the JSON payload instead of the typed slot would
        // have produced.
        self::assertSame(
            [$seven, $nine, $eighty],
            $this->idsInOrder(SortSpec::byField($names['int']), $modelId),
        );
    }

    public function testSortsByNumericSlot(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        $mid  = $this->seedEntry(1, $modelId, [$names['numeric'] => 10.5]);
        $low  = $this->seedEntry(1, $modelId, [$names['numeric'] => 2.25]);
        $high = $this->seedEntry(1, $modelId, [$names['numeric'] => 10.75]);

        self::assertSame(
            [$low, $mid, $high],
            $this->idsInOrder(SortSpec::byField($names['numeric']), $modelId),
        );
    }

    public function testSortsByDatetimeSlot(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        $later   = $this->seedEntry(1, $modelId, [$names['datetime'] => '2026-03-01T00:00:00Z']);
        $earlier = $this->seedEntry(1, $modelId, [$names['datetime'] => '2025-01-01T00:00:00Z']);
        $middle  = $this->seedEntry(1, $modelId, [$names['datetime'] => '2026-01-01T00:00:00Z']);

        self::assertSame(
            [$earlier, $middle, $later],
            $this->idsInOrder(SortSpec::byField($names['datetime']), $modelId),
        );
    }

    public function testSortsByIntrinsicIdDescendingForNewestFirst(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        $a = $this->seedEntry(1, $modelId, [$names['string'] => 'a']);
        $b = $this->seedEntry(1, $modelId, [$names['string'] => 'b']);
        $c = $this->seedEntry(1, $modelId, [$names['string'] => 'c']);

        self::assertSame(
            [$c, $b, $a],
            $this->idsInOrder(SortSpec::byId(SortDirection::Desc), $modelId),
        );
    }

    public function testSortsByIntrinsicCreatedAt(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        $a = $this->seedEntry(1, $modelId, [$names['string'] => 'a']);
        $b = $this->seedEntry(1, $modelId, [$names['string'] => 'b']);
        $c = $this->seedEntry(1, $modelId, [$names['string'] => 'c']);

        // created_at is DATETIME, so entries seeded inside one test very
        // likely share a timestamp. That is the point: the assertion
        // holds only because entry_data.id breaks the tie in the same
        // direction, which is what makes the ordering total.
        self::assertSame(
            [$a, $b, $c],
            $this->idsInOrder(SortSpec::byCreatedAt(), $modelId),
        );
        self::assertSame(
            [$c, $b, $a],
            $this->idsInOrder(SortSpec::byCreatedAt(SortDirection::Desc), $modelId),
        );
    }

    public function testTiedSortValuesAreBrokenByIdInTheSortDirection(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        $first  = $this->seedEntry(1, $modelId, [$names['string'] => 'same']);
        $second = $this->seedEntry(1, $modelId, [$names['string'] => 'same']);
        $third  = $this->seedEntry(1, $modelId, [$names['string'] => 'same']);

        self::assertSame(
            [$first, $second, $third],
            $this->idsInOrder(SortSpec::byField($names['string']), $modelId),
        );
        self::assertSame(
            [$third, $second, $first],
            $this->idsInOrder(SortSpec::byField($names['string'], SortDirection::Desc), $modelId),
        );
    }

    public function testRowsWithNoValueSortFirstAscendingAndLastDescending(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        $withValue = $this->seedEntry(1, $modelId, [$names['string'] => 'zulu']);
        // No value for the sort field at all — its slot column stays NULL.
        $noValue   = $this->seedEntry(1, $modelId, [$names['int'] => 1]);

        // MySQL sorts NULL first ascending, last descending. The engine
        // does not override that; it just has to keep the NULL block
        // reachable, which is what the keyset predicate's extra branch
        // is for.
        self::assertSame(
            [$noValue, $withValue],
            $this->idsInOrder(SortSpec::byField($names['string']), $modelId),
        );
        self::assertSame(
            [$withValue, $noValue],
            $this->idsInOrder(SortSpec::byField($names['string'], SortDirection::Desc), $modelId),
        );
    }

    public function testRowsWithNoPageRowAtAllAreNotDroppedFromASortedPage(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        $this->createField($modelId, 'string', false, 'notes');

        $slotted = $this->seedEntry(1, $modelId, [$names['string'] => 'zulu']);
        // Only a non-filterable field, which per ADR 0034 is JSON-only —
        // so this entry gets NO row on the extension page at all.
        //
        // That distinction is what makes this test able to fail. Seeding
        // an entry that merely omits the *sort* field is not enough: the
        // fixture puts all four slots on one page, so any slot-backed
        // field still creates the page row and INNER would behave
        // identically to LEFT. Verified by swapping the join to INNER and
        // confirming this test — and only this one — goes red.
        $unslotted = $this->seedEntry(1, $modelId, ['notes' => 'no slot anywhere']);

        self::assertSame(
            [$unslotted, $slotted],
            $this->idsInOrder(SortSpec::byField($names['string']), $modelId),
        );
    }

    public function testSortOnUnknownFieldIsRejected(): void
    {
        [$modelId] = $this->setupSortableModel();
        $this->expectException(UnknownFieldException::class);
        $this->idsInOrder(SortSpec::byField('not_a_field'), $modelId);
    }

    public function testSortOnNonFilterableFieldIsRejected(): void
    {
        [$modelId] = $this->setupSortableModel();
        $this->createField($modelId, 'string', false, 'notes');

        // ADR 0004's sort clause, enforced for the first time: a field
        // with no indexed slot is not an ordering target.
        $this->expectException(FieldNotSortableException::class);
        $this->idsInOrder(SortSpec::byField('notes'), $modelId);
    }

    public function testSortOnBackfillingSlotIsRejected(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        $this->pdo->exec(
            'UPDATE stardust_slot_assignments a'
            . ' JOIN stardust_fields f ON f.id = a.field_id'
            . " SET a.status = 'backfilling'"
            . " WHERE f.model_id = {$modelId} AND f.name = '{$names['string']}'"
        );

        // Filters and sorts must agree about `backfilling`: the slot is
        // not yet populated for every row, so ordering by it would be
        // ordering by a half-written column.
        $this->expectException(FieldNotSortableException::class);
        $this->idsInOrder(SortSpec::byField($names['string']), $modelId);
    }

    public function testSortedReadStillGoesDarkForADeletingModel(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        $this->seedEntry(1, $modelId, [$names['string'] => 'a']);
        $this->seedEntry(1, $modelId, [$names['string'] => 'b']);

        self::assertTrue((new StarDust(new Config(pdo: $this->pdo, logger: new NullLogger())))->deleteModel(1, $modelId));

        // ADR 0038: reads go dark — an empty page, indistinguishable from
        // a model that never existed. Widening the pre-flight gate to run
        // on sorted reads must not turn that into a leak or an exception.
        self::assertSame([], $this->idsInOrder(SortSpec::byId(SortDirection::Desc), $modelId));
        self::assertSame([], $this->idsInOrder(SortSpec::byCreatedAt(), $modelId));
        self::assertSame([], $this->idsInOrder(null, $modelId));
    }

    public function testSortOnAFieldOfADeletingModelIsIndistinguishableFromAnUnknownModel(): void
    {
        [$modelId, $names] = $this->setupSortableModel();
        $this->seedEntry(1, $modelId, [$names['string'] => 'a']);
        self::assertTrue((new StarDust(new Config(pdo: $this->pdo, logger: new NullLogger())))->deleteModel(1, $modelId));

        // A model deletion marks every field, so the snapshot carries
        // none — the sort target resolves to nothing. That is the same
        // answer a never-existent model gives, which is what keeps the
        // two indistinguishable. Filters already behave this way; sorts
        // must not diverge.
        $this->expectException(UnknownFieldException::class);
        $this->idsInOrder(SortSpec::byField($names['string']), $modelId);
    }

    public function testSortIsTenantIsolated(): void
    {
        [$modelId, $names] = $this->setupSortableModel(1);
        $mine = $this->seedEntry(1, $modelId, [$names['string'] => 'mine']);
        $this->seedEntry(2, $modelId, [$names['string'] => 'aaa_theirs']);

        // The other tenant's row sorts first by value, so if isolation
        // leaked it would surface at the head of the page rather than
        // somewhere easy to miss.
        self::assertSame([$mine], $this->idsInOrder(SortSpec::byField($names['string']), $modelId, 1));
    }
}
