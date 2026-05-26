<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Retype;

use StarDust\Reconciler\TickOutcome;
use StarDust\Tests\Smoke\Phase6bTestCase;

/**
 * Phase 6b exit criterion #7 — deferred-assignment path.
 *
 * When the initiator cannot reserve a free slot of the target shape,
 * it tombstones the old slot and inserts a `running` checkpoint
 * without assigning a new slot. Each Reconciler tick attempts the
 * reservation again; until capacity is restored the work source
 * returns CAPACITY_WAIT.
 */
final class RetypeDeferredAssignmentTest extends Phase6bTestCase
{
    public function testWorkSourceReturnsCapacityWaitUntilSlotAvailable(): void
    {
        // Provision page 1 with no indexed slots so the only int
        // slots are unindexed. We'll exhaust them, then retype.
        $pageId = $this->provisionPage([]);
        $modelId = $this->createModel(1);

        // Reserve EVERY int slot so the retype's new int reservation
        // must defer.
        $intSlotsFree = (int) $this->pdo
            ->query("SELECT COUNT(*) FROM stardust_slot_assignments WHERE status='free' AND slot_type='int'")
            ->fetchColumn();

        $fillerFieldIds = [];
        for ($i = 0; $i < $intSlotsFree; $i++) {
            $fid = $this->createField($modelId, 'int', false, 'filler_' . $i);
            $this->reserveSlotFor($fid);
            $fillerFieldIds[] = $fid;
        }

        // Subject field: a string field with data, retype to int when
        // all int slots are already taken.
        $fieldId = $this->createField($modelId, 'string', false, 'amount');
        $this->reserveSlotFor($fieldId);
        $this->seedEntry(1, $modelId, ['amount' => '42']);

        // Sanity: no free int slot remaining.
        $remainingInts = (int) $this->pdo
            ->query("SELECT COUNT(*) FROM stardust_slot_assignments WHERE status='free' AND slot_type='int'")
            ->fetchColumn();
        self::assertSame(0, $remainingInts);

        // Initiate retype. The reservation must defer.
        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: 'int',
            newIsFilterable: null,
        );
        self::assertNull($this->fetchLiveSlotForField($fieldId));

        // Tick: no capacity → CAPACITY_WAIT.
        $workSource = $this->makeRetypeBackfillWorkSource();
        self::assertSame(TickOutcome::CAPACITY_WAIT, $workSource->tickOne('cor-defer-1'));

        // Free up capacity by tombstoning one filler slot AND letting
        // it return to `free`. (In production the Liberator does this;
        // here we just patch the row to mimic the end state.)
        $filler = $this->fetchLiveSlotForField($fillerFieldIds[0]);
        self::assertNotNull($filler);
        $this->pdo->prepare(
            "UPDATE stardust_slot_assignments SET status='free', field_id=NULL WHERE id=?"
        )->execute([(int) $filler['id']]);

        // Tick again: the deferred reservation succeeds and the
        // backfill runs to completion in one shot (1 row < chunkSize).
        self::assertSame(TickOutcome::WORK_DONE, $workSource->tickOne('cor-defer-2'));

        // Field now has a live `ready` slot.
        $live = $this->fetchLiveSlotForField($fieldId);
        self::assertNotNull($live);
        self::assertSame('ready', $live['status']);
        self::assertSame('int', $live['slot_type']);

        // Checkpoint completed.
        $cp = $this->fetchCheckpointForField($fieldId);
        self::assertSame('completed', $cp['status']);
    }
}
