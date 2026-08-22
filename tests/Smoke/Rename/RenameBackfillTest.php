<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Rename;

use StarDust\Reconciler\TickOutcome;
use StarDust\Tests\Smoke\Phase6bTestCase;

/**
 * The asynchronous half: the Reconciler's chunked payload rewrite.
 */
final class RenameBackfillTest extends Phase6bTestCase
{
    public function testDrainsAcrossMultipleChunksAndCompletesAtomically(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'title');
        for ($i = 1; $i <= 7; $i++) {
            $this->seedEntry(1, $modelId, ['title' => "v{$i}"]);
        }

        $this->makeRenameInitiator()->initiate(1, $fieldId, 'headline');
        $versionAfterInitiate = $this->fetchSchemaVersion();

        // chunkSize 3 over 7 rows: three full chunks then a short one.
        $ticks = 0;
        while ($this->runRenameTick(null, 3) === TickOutcome::WORK_DONE) {
            self::assertLessThan(10, ++$ticks, 'Backfill failed to terminate.');
        }

        self::assertSame(
            0,
            $this->countRowsWithKey($modelId, 'title'),
            'No row may remain on the pre-rename key.',
        );
        self::assertSame(7, $this->countRowsWithKey($modelId, 'headline'));

        $checkpoint = $this->fetchRenameCheckpointForField($fieldId);
        self::assertSame('completed', $checkpoint['status']);
        self::assertNull(
            $this->fetchFieldRow($fieldId)['previous_name'],
            'previous_name must be cleared once no row can still hold the old key.',
        );
        self::assertGreaterThan(
            $versionAfterInitiate,
            $this->fetchSchemaVersion(),
            'Completion must bump the version so cached snapshots drop the fallback.',
        );
    }

    public function testIsIdempotentAcrossAnAlreadyMigratedRange(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'title');
        $entryId = $this->seedEntry(1, $modelId, ['title' => 'v1']);

        $this->makeRenameInitiator()->initiate(1, $fieldId, 'headline');
        $this->runRenameTick();

        $after = $this->rawPayload($entryId);

        // Re-open a checkpoint over the same, already-migrated range.
        $this->makeRenameInitiator()->initiate(1, $fieldId, 'caption');
        $this->pdo->exec(
            "UPDATE stardust_fields SET previous_name = 'headline' WHERE id = {$fieldId}"
        );
        $this->runRenameTick();

        self::assertSame(
            ['caption' => 'v1'],
            $this->rawPayload($entryId),
            'Rewriting a range twice must not duplicate or drop keys.',
        );
        self::assertSame(['headline' => 'v1'], $after);
    }

    public function testRowsThatNeverHeldTheFieldAreUntouched(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'title');
        $withField = $this->seedEntry(1, $modelId, ['title' => 'v1']);
        $without   = $this->seedEntry(1, $modelId, ['other' => 'x']);

        $this->makeRenameInitiator()->initiate(1, $fieldId, 'headline');
        while ($this->runRenameTick() === TickOutcome::WORK_DONE) {
        }

        self::assertSame(['headline' => 'v1'], $this->rawPayload($withField));
        self::assertSame(
            ['other' => 'x'],
            $this->rawPayload($without),
            'A row without the renamed key must not gain a null one.',
        );
    }

    /**
     * The highest-value test in this file.
     *
     * A decode-mutate-encode implementation using `json_decode(..., true)`
     * silently converts `{"0":"x","1":"y"}` into the JSON array
     * `["x","y"]`, because those keys decode to a PHP list. Field names
     * are `VARCHAR(128)` with no numeric restriction, so that payload is
     * reachable. The SQL rewrite must preserve it — along with nested
     * objects, arrays, nulls, floats and non-ASCII text.
     */
    public function testPreservesPayloadFidelityIncludingNumericKeys(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'title');

        $entryId = $this->seedEntry(1, $modelId, [
            'title'  => 'renamed-value',
            '0'      => 'zero',
            '1'      => 'one',
            'nested' => ['a' => [1, 2, 3], 'b' => ['c' => null], 'd' => 1.5],
            'uni'    => 'café — ünïcode',
        ]);

        $this->makeRenameInitiator()->initiate(1, $fieldId, 'headline');
        while ($this->runRenameTick() === TickOutcome::WORK_DONE) {
        }

        $expected = [
            '0'        => 'zero',
            '1'        => 'one',
            'nested'   => ['a' => [1, 2, 3], 'b' => ['c' => null], 'd' => 1.5],
            'uni'      => 'café — ünïcode',
            'headline' => 'renamed-value',
        ];
        ksort($expected);

        self::assertSame(
            $expected,
            $this->sortedPayload($entryId),
            'Only the renamed key may change; every other value must survive byte-for-byte.',
        );

        // The specific hazard: `{"0":…,"1":…}` must still be a JSON
        // OBJECT, not have collapsed into an array.
        $raw = (string) $this->pdo
            ->query('SELECT fields FROM entry_data WHERE id = ' . $entryId)
            ->fetchColumn();
        self::assertStringContainsString('"0":', str_replace(' ', '', $raw));
        self::assertStringStartsWith('{', $raw);
    }

    public function testEmitsChunkCompleteAndExactlyOneRenameComplete(): void
    {
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', false, 'title');
        for ($i = 1; $i <= 5; $i++) {
            $this->seedEntry(1, $modelId, ['title' => "v{$i}"]);
        }

        $this->makeRenameInitiator()->initiate(1, $fieldId, 'headline');

        $logger = $this->makeRecordingLogger();
        $source = $this->makeRenameBackfillWorkSource($logger, 2);
        while ($source->tickOne('corr-1') === TickOutcome::WORK_DONE) {
        }

        $records = $logger->records();
        self::assertGreaterThanOrEqual(3, count($this->recordsWithEvent($records, 'chunk_complete')));
        self::assertCount(
            1,
            $this->recordsWithEvent($records, 'rename_complete'),
            'rename_complete fires once, on the final chunk only.',
        );

        $claimed = $this->recordsWithEvent($records, 'chunk_claimed');
        self::assertSame('rename_backfill', $claimed[0]['context']['queue']);
    }

    public function testIdleWhenNothingIsRunning(): void
    {
        self::assertSame(TickOutcome::IDLE, $this->runRenameTick());
    }

    private function countRowsWithKey(int $modelId, string $key): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM entry_data'
            . " WHERE model_id = ? AND JSON_CONTAINS_PATH(fields, 'one', ?)"
        );
        $stmt->execute([$modelId, '$."' . $key . '"']);
        return (int) $stmt->fetchColumn();
    }

    /**
     * MySQL normalises JSON object key order on storage, so compare
     * against a key-sorted array rather than asserting on order.
     *
     * @return array<string, mixed>
     */
    private function sortedPayload(int $entryId): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->rawPayload($entryId);
        ksort($payload);
        return $payload;
    }
}
