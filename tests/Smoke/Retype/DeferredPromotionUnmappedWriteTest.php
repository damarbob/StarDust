<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Retype;

use Psr\Log\NullLogger;
use StarDust\Clock\SystemClock;
use StarDust\Config\Config;
use StarDust\Slot\SlotReserver;
use StarDust\StarDust;
use StarDust\Tests\Smoke\Phase6bTestCase;
use StarDust\Write\EntryPayload;

/**
 * A promotion that deferred its slot, then a write before the next tick.
 *
 * The write enqueues, since the field is filterable and unmapped, and
 * once capacity exists the sync-queue source can reserve the field a
 * slot before the retype source claims its checkpoint. The retype source
 * used to look only for a `backfilling` or `ready` slot, so it reserved
 * a second one and hit `ux_slot_assignments_field_live` on every tick
 * from then on.
 *
 * The sync-queue source now reserves such a slot as `backfilling`, so it
 * never answers filters while it holds only the drained entries. An
 * `assigned` slot can still appear (a retype committing between the
 * check and the reservation, or an install that hit the old bug), and
 * the retype source adopts it.
 *
 * The first two cases are driven through whole ticks rather than single
 * work sources, because a fix that only reorders who reserves can stall
 * the round instead.
 */
final class DeferredPromotionUnmappedWriteTest extends Phase6bTestCase
{
    private int $lastEntryId = 0;

    public function testWithNoPageYet(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'title');

        $this->promoteThenWriteThenTick($modelId, $fieldId);
    }

    public function testWithEveryIndexedSlotOfTheFamilyTaken(): void
    {
        $this->provisionPage(['i_str_01']);
        $modelId = $this->createModel(1);
        $this->reserveSlotFor($this->createField($modelId, 'string', true, 'sku'));
        $fieldId = $this->createField($modelId, 'string', false, 'title');

        $this->promoteThenWriteThenTick($modelId, $fieldId);
    }

    public function testTheSyncQueueReservesTheSlotAsBackfilling(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'title');
        $engine = $this->promoteThenWrite($modelId, $fieldId);
        $this->provisionPageWithIndexedFamily('str');

        $this->makeSyncQueueWorkSource()->tickOne('test-reserve');

        $slot = $this->fetchLiveSlotForField($fieldId);
        self::assertSame(
            'backfilling',
            $slot['status'] ?? null,
            'A slot the retype has not filled yet must stay off the read path.',
        );

        $this->tickUntilPromoted($engine, $fieldId, (int) $slot['id']);
    }

    public function testTheRetypeSourceAdoptsAnAssignedSlot(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'title');
        $engine = $this->promoteThenWrite($modelId, $fieldId);
        $this->provisionPageWithIndexedFamily('str');

        // The state the sync-queue source used to leave behind.
        (new SlotReserver($this->pdo, new SystemClock(), new NullLogger()))
            ->reserveForExhaustionBackfill($fieldId);
        $slot = $this->fetchLiveSlotForField($fieldId);
        self::assertSame('assigned', $slot['status'] ?? null);

        $this->tickUntilPromoted($engine, $fieldId, (int) $slot['id']);
    }

    private function promoteThenWriteThenTick(int $modelId, int $fieldId): void
    {
        $engine = $this->promoteThenWrite($modelId, $fieldId);

        $engine->tick(5);

        self::assertSame('ready', $this->fetchLiveSlotForField($fieldId)['status'] ?? null);
        self::assertSame('completed', $this->fetchCheckpointForField($fieldId)['status'] ?? null);
        self::assertSame(0, $this->countSyncQueueRowsFor([$this->lastEntryId]));
    }

    private function promoteThenWrite(int $modelId, int $fieldId): StarDust
    {
        $engine = new StarDust(new Config(pdo: $this->pdo, watcherPollIntervalSeconds: 1));

        $engine->promoteFieldToFilterable(1, $fieldId);
        self::assertNull($this->fetchLiveSlotForField($fieldId), 'The promotion defers its slot.');

        $this->lastEntryId = $engine->write(EntryPayload::fromArray([
            'tenantId' => 1,
            'modelId' => $modelId,
            'fields' => ['title' => 'Hello'],
        ]))->entryId;

        return $engine;
    }

    /**
     * The retype source must take over the existing slot rather than
     * reserve another, and the backfill must still fill it.
     */
    private function tickUntilPromoted(StarDust $engine, int $fieldId, int $slotId): void
    {
        $engine->tick(5);

        $slot = $this->fetchLiveSlotForField($fieldId);
        self::assertSame($slotId, (int) ($slot['id'] ?? 0), 'The existing slot is kept.');
        self::assertSame('ready', $slot['status'] ?? null);
        self::assertSame('completed', $this->fetchCheckpointForField($fieldId)['status'] ?? null);
        self::assertSame('Hello', $this->fetchSlotValue(
            $this->pageTableNameFor((int) $slot['page_id']),
            $this->lastEntryId,
            (string) $slot['slot_column'],
        ));
    }
}
