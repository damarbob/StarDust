<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Retype;

use StarDust\Config\Config;
use StarDust\StarDust;
use StarDust\Tests\Smoke\Phase6bTestCase;
use StarDust\Write\EntryPayload;

/**
 * A promotion that deferred its slot, then a write before the next tick.
 *
 * The write enqueues, since the field is filterable and unmapped, and
 * once capacity exists the sync-queue source reserves the field an
 * `assigned` slot before the retype source claims its checkpoint. The
 * retype source used to look only for a `backfilling` or `ready` slot,
 * so it reserved a second one and hit `ux_slot_assignments_field_live`
 * on every tick from then on.
 *
 * Driven through whole ticks rather than single work sources, because a
 * fix that only reorders who reserves can stall the round instead.
 */
final class DeferredPromotionUnmappedWriteTest extends Phase6bTestCase
{
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

    private function promoteThenWriteThenTick(int $modelId, int $fieldId): void
    {
        $engine = new StarDust(new Config(pdo: $this->pdo, watcherPollIntervalSeconds: 1));

        $engine->promoteFieldToFilterable(1, $fieldId);
        self::assertNull($this->fetchLiveSlotForField($fieldId), 'The promotion defers its slot.');

        $entryId = $engine->write(EntryPayload::fromArray([
            'tenantId' => 1,
            'modelId' => $modelId,
            'fields' => ['title' => 'Hello'],
        ]))->entryId;

        $engine->tick(5);

        self::assertSame('ready', $this->fetchLiveSlotForField($fieldId)['status'] ?? null);
        self::assertSame('completed', $this->fetchCheckpointForField($fieldId)['status'] ?? null);
        self::assertSame(0, $this->countSyncQueueRowsFor([$entryId]));
    }
}
