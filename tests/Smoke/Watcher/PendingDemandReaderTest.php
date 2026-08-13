<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Watcher;

use StarDust\Tests\Smoke\Phase6bTestCase;
use StarDust\Watcher\PendingDemandReader;

/**
 * Registry demand: filterable fields waiting on a slot.
 *
 * Extends the Phase 6b case so the deferred-retype source can be
 * exercised through the real `RetypeInitiator` rather than by hand —
 * that path is the whole reason the reader exists.
 */
final class PendingDemandReaderTest extends Phase6bTestCase
{
    private function demandReader(): PendingDemandReader
    {
        return new PendingDemandReader($this->pdo);
    }

    public function testUnmappedFilterableFieldIsDemand(): void
    {
        $this->provisionPage();
        $modelId = $this->createModel(1);
        $this->createField($modelId, 'string', true, 'wants_slot');

        self::assertSame(['str' => 1], $this->demandReader()->read()->toArray());
    }

    /** ADR 0034: a JSON-only field never wants a slot, so it is never demand. */
    public function testNonFilterableFieldIsNotDemand(): void
    {
        $this->provisionPage();
        $modelId = $this->createModel(1);
        $this->createField($modelId, 'string', false, 'json_only');

        self::assertTrue($this->demandReader()->read()->isEmpty());
    }

    public function testFieldWithLiveSlotIsNotDemand(): void
    {
        $this->provisionPage();
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'satisfied');
        $this->reserveSlotFor($fieldId);

        self::assertTrue($this->demandReader()->read()->isEmpty());
    }

    /** Tombstoned is not live, so the field is waiting again. */
    public function testTombstonedSlotDoesNotSuppressDemand(): void
    {
        $this->provisionPage();
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'evicted');
        $this->reserveSlotFor($fieldId);

        $slot = $this->fetchLiveSlotForField($fieldId);
        self::assertNotNull($slot);
        $this->tombstoneSlotAssignment((int) $slot['id']);

        self::assertSame(['str' => 1], $this->demandReader()->read()->toArray());
    }

    /**
     * The blueprint's second demand source is a subset of the first,
     * and lands under the *target* family. Both properties in one test
     * because they share a fixture and either failing breaks the
     * one-query justification.
     */
    public function testDeferredRetypeWaiterIsCountedOnceUnderTargetFamily(): void
    {
        // No indexed int column anywhere, so the retype's replacement
        // reservation must defer.
        $this->provisionPage(['i_str_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'amount');
        $this->reserveSlotFor($fieldId);

        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: 'int',
            newIsFilterable: null,
        );
        self::assertNull($this->fetchLiveSlotForField($fieldId), 'Reservation must have deferred.');

        $demand = $this->demandReader()->read();
        self::assertSame(['int' => 1], $demand->toArray(), 'Counted under the target family.');
        self::assertSame(1, $demand->totalWaiters(), 'Counted once, not once per source.');
    }

    public function testDemandGroupsByFamilyAcrossAllFourDeclaredTypes(): void
    {
        $this->provisionPage();
        $modelId = $this->createModel(1);
        $this->createField($modelId, 'string', true, 'a');
        $this->createField($modelId, 'int', true, 'b');
        $this->createField($modelId, 'numeric', true, 'c');
        $this->createField($modelId, 'datetime', true, 'd');

        self::assertSame(
            ['dt' => 1, 'int' => 1, 'num' => 1, 'str' => 1],
            $this->demandReader()->read()->toArray(),
            'Keys are ksorted so the log line is diffable between polls.',
        );
    }

    public function testNoFieldsAtAllIsEmptyDemand(): void
    {
        $this->provisionPage();

        self::assertTrue($this->demandReader()->read()->isEmpty());
        self::assertSame(0, $this->demandReader()->read()->totalWaiters());
    }
}
