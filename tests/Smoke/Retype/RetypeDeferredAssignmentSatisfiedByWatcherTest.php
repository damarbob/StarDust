<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Retype;

use Psr\Log\NullLogger;
use StarDust\Reconciler\TickOutcome;
use StarDust\Tests\Smoke\Phase6bTestCase;

/**
 * The loop this whole change exists to close.
 *
 * A retype whose replacement reservation defers for want of an indexed
 * slot used to wait forever: the Reconciler emitted `capacity_wait` on
 * every tick, and the Watcher — which provisioned pages carrying no
 * indexes at all — could never produce a slot that satisfied it. Now
 * the Watcher sees the waiting field, provisions a page indexed for its
 * family, and the next Reconciler tick picks the slot up.
 */
final class RetypeDeferredAssignmentSatisfiedByWatcherTest extends Phase6bTestCase
{
    public function testDeferredRetypeIsSatisfiedAfterTheWatcherProvisions(): void
    {
        // One page with an indexed str column (so the subject field can
        // hold a slot) and no indexed int column anywhere.
        $this->provisionPage(['i_str_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'amount');
        $this->reserveSlotFor($fieldId);

        $this->seedEntry(1, $modelId, ['amount' => '42']);

        // 1. Retype string → int. No indexed int slot exists, so the
        //    replacement reservation defers.
        $logger = $this->makeRecordingLogger();
        $this->makeRetypeInitiator($logger)->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: 'int',
            newIsFilterable: null,
        );

        self::assertNull($this->fetchLiveSlotForField($fieldId), 'No slot could be reserved.');
        $started = $this->recordsWithEvent($logger->records(), 'retype_started');
        self::assertTrue($started[0]['context']['deferred_assignment']);

        // 2. The Reconciler cannot make progress: nothing to reserve.
        self::assertSame(
            TickOutcome::CAPACITY_WAIT,
            $this->makeRetypeBackfillWorkSource()->tickOne('cor-before'),
        );

        // 3. The Watcher sees the waiting field and provisions a page
        //    indexed for its family. Threshold 0.0 proves the demand
        //    trigger did it, not raw capacity.
        $this->makeWatcher(new NullLogger(), threshold: 0.0)->tick();

        $newPageId = (int) $this->pdo
            ->query('SELECT MAX(id) FROM stardust_pages')
            ->fetchColumn();
        self::assertSame(2, $newPageId, 'A second page must have been provisioned.');

        $indexed = $this->pdo
            ->query("SHOW INDEX FROM entry_slots_page_{$newPageId}")
            ->fetchAll(\PDO::FETCH_ASSOC);
        $columns = array_column($indexed, 'Column_name');
        self::assertContains('i_int_01', $columns, 'The new page is indexed for the demanded family.');

        // 4. The Reconciler now reserves and drains.
        self::assertSame(
            TickOutcome::WORK_DONE,
            $this->makeRetypeBackfillWorkSource()->tickOne('cor-after'),
        );

        $slot = $this->fetchLiveSlotForField($fieldId);
        self::assertNotNull($slot, 'The deferred reservation finally succeeded.');
        self::assertSame($newPageId, (int) $slot['page_id']);
        self::assertSame('int', $slot['slot_type']);
    }

    /** Once satisfied, the Watcher must not keep provisioning for the same field. */
    public function testWatcherStopsProvisioningOnceTheRetypeHasItsSlot(): void
    {
        $this->provisionPage(['i_str_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'amount');
        $this->reserveSlotFor($fieldId);
        $this->seedEntry(1, $modelId, ['amount' => '42']);

        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: 'int',
            newIsFilterable: null,
        );

        $watcher = $this->makeWatcher(new NullLogger(), threshold: 0.0);
        $watcher->tick();
        $this->makeRetypeBackfillWorkSource()->tickOne('cor-drain');

        $pagesAfterDrain = (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_pages')->fetchColumn();

        $watcher->tick();

        self::assertSame(
            $pagesAfterDrain,
            (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_pages')->fetchColumn(),
            'Demand is satisfied, so no further page is provisioned.',
        );
    }
}
