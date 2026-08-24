<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Delete;

use StarDust\Reconciler\TickOutcome;
use StarDust\Tests\Smoke\Phase6bTestCase;

/**
 * ADR 0037 asynchronous half: the chunked `entry_data` purge that ends
 * with the registry row itself.
 */
final class DeletePurgeTest extends Phase6bTestCase
{
    public function testDrainsAcrossMultipleChunksThenDeletesTheFieldRow(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'colour');

        $entryIds = [];
        for ($i = 0; $i < 5; $i++) {
            $entryIds[] = $this->seedEntry(1, $modelId, ['colour' => "c{$i}", 'shape' => 'round']);
        }

        $this->makeDeleteFieldInitiator()->initiate(1, $fieldId);

        // chunkSize 2 over 5 rows: 2 + 2 + 1, the last being partial and
        // therefore final.
        self::assertSame(TickOutcome::WORK_DONE, $this->runDeleteTick(chunkSize: 2));
        self::assertIsArray(
            $this->fetchFieldRowOrNull($fieldId),
            'Mid-drain the registry row must survive — the purge reads its name from it.',
        );

        self::assertSame(TickOutcome::WORK_DONE, $this->runDeleteTick(chunkSize: 2));
        self::assertSame(TickOutcome::WORK_DONE, $this->runDeleteTick(chunkSize: 2));

        foreach ($entryIds as $entryId) {
            $payload = $this->rawPayload($entryId);
            self::assertIsArray($payload);
            self::assertArrayNotHasKey('colour', $payload, 'Purged key must be gone from storage.');
            self::assertSame('round', $payload['shape'], 'Sibling keys must survive untouched.');
        }

        self::assertNull($this->fetchFieldRowOrNull($fieldId), 'Final chunk hard-deletes the field.');
        self::assertNull(
            $this->fetchDeleteCheckpointForField($fieldId),
            'The checkpoint is deleted, not completed — its field no longer exists.',
        );
        self::assertSame(TickOutcome::IDLE, $this->runDeleteTick(), 'Nothing left to claim.');
    }

    /**
     * Uniform state machine: a model with no entries completes on the
     * first tick rather than needing a special case in the initiator.
     */
    public function testEmptyModelCompletesOnTheFirstTick(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'colour');

        $this->makeDeleteFieldInitiator()->initiate(1, $fieldId);
        self::assertSame(TickOutcome::WORK_DONE, $this->runDeleteTick());

        self::assertNull($this->fetchFieldRowOrNull($fieldId));
        self::assertNull($this->fetchDeleteCheckpointForField($fieldId));
    }

    public function testRowsThatNeverCarriedTheFieldAreUntouched(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'colour');

        $withField = $this->seedEntry(1, $modelId, ['colour' => 'red', 'shape' => 'round']);
        $withoutField = $this->seedEntry(1, $modelId, ['shape' => 'square']);

        $this->makeDeleteFieldInitiator()->initiate(1, $fieldId);
        $logger = $this->makeRecordingLogger();
        $this->runDeleteTick($logger);

        self::assertSame(['shape' => 'square'], $this->rawPayload($withoutField));
        self::assertSame(['shape' => 'round'], $this->rawPayload($withField));

        $complete = $this->recordsWithEvent($logger->records(), 'chunk_complete');
        self::assertCount(1, $complete);
        self::assertSame(2, $complete[0]['context']['rows_scanned']);
        self::assertSame(
            1,
            $complete[0]['context']['rows_purged'],
            'The JSON_CONTAINS_PATH guard keeps rows_purged honest.',
        );
    }

    /**
     * Another model's entries share the table; the purge is scoped by
     * `(tenant_id, model_id)` and must not reach across.
     */
    public function testOtherModelsAndTenantsAreUntouched(): void
    {
        $modelA = $this->createModel(1, 'a');
        $modelB = $this->createModel(1, 'b');
        $modelC = $this->createModel(2, 'c');

        $fieldId = $this->createField($modelA, 'string', false, 'colour');
        $this->createField($modelB, 'string', false, 'colour');
        $this->createField($modelC, 'string', false, 'colour');

        $inA = $this->seedEntry(1, $modelA, ['colour' => 'red']);
        $inB = $this->seedEntry(1, $modelB, ['colour' => 'blue']);
        $inC = $this->seedEntry(2, $modelC, ['colour' => 'green']);

        $this->makeDeleteFieldInitiator()->initiate(1, $fieldId);
        $this->runDeleteTick();

        self::assertSame([], $this->rawPayload($inA));
        self::assertSame(['colour' => 'blue'], $this->rawPayload($inB));
        self::assertSame(['colour' => 'green'], $this->rawPayload($inC));
    }

    public function testEmitsChunkEventsAndExactlyOneDeleteComplete(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'colour');
        for ($i = 0; $i < 3; $i++) {
            $this->seedEntry(1, $modelId, ['colour' => "c{$i}"]);
        }

        $this->makeDeleteFieldInitiator()->initiate(1, $fieldId);

        $logger = $this->makeRecordingLogger();
        $this->runDeleteTick($logger, chunkSize: 2);
        $this->runDeleteTick($logger, chunkSize: 2);

        $claimed = $this->recordsWithEvent($logger->records(), 'chunk_claimed');
        self::assertCount(2, $claimed);
        self::assertSame('delete_purge', $claimed[0]['context']['queue']);

        $complete = $this->recordsWithEvent($logger->records(), 'chunk_complete');
        self::assertCount(2, $complete);
        self::assertFalse($complete[0]['context']['final_chunk']);
        self::assertTrue($complete[1]['context']['final_chunk']);

        $done = $this->recordsWithEvent($logger->records(), 'delete_complete');
        self::assertCount(1, $done, 'Exactly one terminal event for the whole lifecycle.');
        self::assertSame('registry', $done[0]['context']['source']);
        self::assertSame('colour', $done[0]['context']['field_name']);
    }

    /**
     * The `{"0":"x","1":"y"}` trap, ported from
     * `RenameBackfillTest::testPreservesPayloadFidelityIncludingNumericKeys`.
     *
     * A decode-mutate-encode purge using `json_decode($json, true)`
     * silently converts that payload into the JSON *array* `["x","y"]`,
     * because those keys decode to a PHP list. Field names are
     * `VARCHAR(128)` with no numeric restriction, so the payload is
     * reachable. The SQL rewrite must preserve it.
     */
    public function testPreservesPayloadFidelityIncludingNumericKeys(): void
    {
        $modelId = $this->createModel(1);
        $victim = $this->createField($modelId, 'string', false, 'colour');
        $this->createField($modelId, 'string', false, '0');
        $this->createField($modelId, 'string', false, '1');
        $this->createField($modelId, 'string', false, 'nested');
        $this->createField($modelId, 'string', false, 'uni');

        // The non-numeric keys are load-bearing in the FIXTURE, not just
        // the assertion: `json_encode(['0'=>'x','1'=>'y'])` emits the
        // JSON *array* `["x","y"]`, so a payload of purely sequential
        // numeric keys cannot be constructed through the write path at
        // all. Mixing in a string key keeps it an object — which is the
        // shape that would break under a decode-and-re-encode purge.
        $entryId = $this->seedEntry(1, $modelId, [
            'colour' => 'gone',
            '0'      => 'zero',
            '1'      => 'one',
            'nested' => ['a' => [1, 2, 3], 'b' => ['c' => null], 'd' => 1.5],
            'uni'    => 'café — ünïcode',
        ]);

        $this->makeDeleteFieldInitiator()->initiate(1, $victim);
        $this->runDeleteTick();

        $expected = [
            '0'      => 'zero',
            '1'      => 'one',
            'nested' => ['a' => [1, 2, 3], 'b' => ['c' => null], 'd' => 1.5],
            'uni'    => 'café — ünïcode',
        ];
        ksort($expected);

        self::assertSame(
            $expected,
            $this->sortedPayload($entryId),
            'Only the deleted key may go; every other value must survive byte-for-byte.',
        );
    }

    /**
     * Nested objects, floats and explicit nulls must survive a purge of
     * a sibling key byte-for-byte in meaning.
     */
    public function testPreservesNestedAndScalarFidelity(): void
    {
        $modelId = $this->createModel(1);
        $victim = $this->createField($modelId, 'string', false, 'colour');
        $this->createField($modelId, 'string', false, 'meta');

        $entryId = $this->seedEntry(1, $modelId, [
            'colour' => 'red',
            'meta'   => ['nested' => ['deep' => true], 'ratio' => 1.5, 'nothing' => null],
        ]);

        $this->makeDeleteFieldInitiator()->initiate(1, $victim);
        $this->runDeleteTick();

        // MySQL normalises JSON object key order on store, at every
        // depth — so the comparison has to sort recursively, not just at
        // the top level.
        self::assertSame(
            self::deepKsort(['meta' => ['nested' => ['deep' => true], 'ratio' => 1.5, 'nothing' => null]]),
            self::deepKsort((array) $this->rawPayload($entryId)),
        );
    }

    /** @return array<string, mixed> */
    private function sortedPayload(int $entryId): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->rawPayload($entryId);
        ksort($payload);
        return $payload;
    }

    /**
     * @param  array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function deepKsort(array $value): array
    {
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::deepKsort($v);
            }
        }
        ksort($value);
        return $value;
    }

    /**
     * A field name containing characters that would break an unquoted
     * `$.name` JSON path, or escape a quoted one.
     */
    public function testHandlesAwkwardFieldNames(): void
    {
        foreach (['a field', '9lives', 'wei"rd', 'back\\slash', 'ünïcode'] as $name) {
            $modelId = $this->createModel(1, 'm_' . bin2hex(random_bytes(4)));
            $fieldId = $this->createField($modelId, 'string', false, $name);
            $this->createField($modelId, 'string', false, 'keep');
            $entryId = $this->seedEntry(1, $modelId, [$name => 'gone', 'keep' => 'kept']);

            $this->makeDeleteFieldInitiator()->initiate(1, $fieldId);
            $this->runDeleteTick();

            self::assertSame(
                ['keep' => 'kept'],
                $this->rawPayload($entryId),
                "Field name '{$name}' must purge cleanly.",
            );
        }
    }

    /**
     * Soft-deleted entries are purged too. They can be read by nothing,
     * but leaving them carrying a key whose field no longer exists would
     * permanently desynchronise them from the registry.
     */
    public function testSoftDeletedEntriesArePurgedToo(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'colour');
        $entryId = $this->seedEntry(1, $modelId, ['colour' => 'red']);
        $this->pdo->exec('UPDATE entry_data SET deleted_at = UTC_TIMESTAMP() WHERE id = ' . $entryId);

        $this->makeDeleteFieldInitiator()->initiate(1, $fieldId);
        $this->runDeleteTick();

        self::assertSame([], $this->rawPayload($entryId));
    }

    /**
     * The tombstone the initiator left behind must still reclaim, with
     * the field row already gone — the Liberator never joins
     * `stardust_fields`.
     */
    public function testTombstonedSlotStillReclaimsAfterTheFieldRowIsDeleted(): void
    {
        $pageId = $this->provisionPage(['i_str_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'colour');
        $this->reserveSlotFor($fieldId);
        $slotId = (int) $this->fetchLiveSlotForField($fieldId)['id'];
        $this->seedEntry(1, $modelId, ['colour' => 'red']);

        $this->makeDeleteFieldInitiator()->initiate(1, $fieldId);
        $this->runDeleteTick();
        self::assertNull($this->fetchFieldRowOrNull($fieldId));

        $this->setTombstonedAt($slotId, '2000-01-01 00:00:00');
        $this->makeLiberator()->tick();

        $slot = $this->fetchSlotAssignment($slotId);
        self::assertSame('free', $slot['status'], 'ADR 0029: the sweep never joins stardust_fields.');
        self::assertSame(0, $this->countNonNullValues($this->pageTableNameFor($pageId), 'i_str_01'));
    }
}
