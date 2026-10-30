<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Write;

use PDO;
use Psr\Log\NullLogger;
use StarDust\Clock\SystemClock;
use StarDust\Exception\ModelDeletionInProgressException;
use StarDust\Exception\UncoercibleSlotValueException;
use StarDust\Reconciler\TickOutcome;
use StarDust\Tests\Smoke\Phase6bTestCase;
use StarDust\Write\BulkChunkResult;
use StarDust\Write\BulkIngestOptions;
use StarDust\Write\BulkIngestor;
use StarDust\Write\EntryChunkWriter;
use StarDust\Write\EntryPayload;
use StarDust\Write\EntryWriteResult;
use StarDust\Write\EntryWriter;
use StarDust\Write\SlotRowUpserter;
use Throwable;

/**
 * The chunk writer: every bulk write path, and, through
 * `EntryWriter::writeWithinTransaction()`, the single-entry one too.
 *
 * The headline property is that a chunk issues a number of statements
 * that does not depend on how many entries it holds. The rest of this
 * file pins that batching the statements changed nothing a caller can
 * observe: ids come back in payload order and match the stored rows,
 * NULL padding on a shared page clobbers nothing, the exhaustion enqueue
 * lands on exactly the right entries, and every per-payload failure
 * still leaves the chunk with no rows at all.
 */
final class EntryChunkWriterTest extends Phase6bTestCase
{
    private function chunkWriter(int $maxPlaceholders = EntryChunkWriter::DEFAULT_MAX_PLACEHOLDERS, ?int $maxStatementBytes = null): EntryChunkWriter
    {
        return new EntryChunkWriter(
            pdo: $this->pdo,
            clock: new SystemClock(),
            slotRowUpserter: new SlotRowUpserter($this->pdo),
            maxPlaceholders: $maxPlaceholders,
            maxStatementBytes: $maxStatementBytes,
        );
    }

    /**
     * @param list<EntryPayload> $payloads
     * @return list<EntryWriteResult>
     */
    private function writeCommitted(EntryChunkWriter $writer, array $payloads, ?string $correlationId = null): array
    {
        $this->pdo->beginTransaction();
        try {
            $results = $writer->writeChunk($payloads, $correlationId);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
        return $results;
    }

    /** @return array<string, int> */
    private function statementCounters(): array
    {
        $rows = $this->pdo->query(
            "SHOW SESSION STATUS WHERE Variable_name IN ('Com_stmt_execute', 'Com_insert', 'Com_select')"
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        $out = [];
        foreach ($rows as $name => $value) {
            $out[(string) $name] = (int) $value;
        }
        ksort($out);
        return $out;
    }

    /**
     * @param array<string, int> $before
     * @param array<string, int> $after
     * @return array<string, int>
     */
    private static function delta(array $before, array $after): array
    {
        $out = [];
        foreach ($after as $k => $v) {
            $out[$k] = $v - ($before[$k] ?? 0);
        }
        return $out;
    }

    private function countEntries(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM entry_data')->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function slotRow(string $table, int $entryId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$table} WHERE entry_id = ?");
        $stmt->execute([$entryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row, "no slot row for entry {$entryId}");
        return $row;
    }

    /**
     * Model with two indexed string slots on one page, plus one
     * filterable field that holds no slot, so every chunk exercises the
     * entry INSERT, the slot UPSERT and the queue INSERT.
     *
     * @return array{0: int, 1: string}
     */
    private function statementFixture(): array
    {
        $this->provisionPage(['i_str_01', 'i_str_02']);
        $modelId = $this->createModel(1);
        $this->reserveSlotFor($this->createField($modelId, 'string', true, 'a'));
        $this->reserveSlotFor($this->createField($modelId, 'string', true, 'b'));
        $this->createField($modelId, 'string', true, 'waiting');
        return [$modelId, 'waiting'];
    }

    /** @return array<string, int> */
    private function measureChunk(int $modelId, int $size): array
    {
        $payloads = [];
        for ($i = 0; $i < $size; $i++) {
            $payloads[] = new EntryPayload(1, $modelId, ['a' => "a{$i}", 'b' => "b{$i}", 'waiting' => "w{$i}"]);
        }

        $writer = $this->chunkWriter();
        $before = $this->statementCounters();
        $this->writeCommitted($writer, $payloads);
        $after = $this->statementCounters();

        return self::delta($before, $after);
    }

    // ---------------------------------------------------------------
    // The headline property
    // ---------------------------------------------------------------

    /**
     * Neuter-validated: with `writeChunk()` temporarily replaced by a
     * per-entry loop over a one-element chunk, this test fails with the
     * 300-entry delta thirty times the 10-entry one.
     */
    public function testStatementCountDoesNotGrowWithTheChunk(): void
    {
        [$modelId] = $this->statementFixture();

        $small = $this->measureChunk($modelId, 10);
        $large = $this->measureChunk($modelId, 300);

        self::assertSame($small, $large, 'a 300-entry chunk must issue exactly the statements a 10-entry one does');
        self::assertSame(310, (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_sync_queue')->fetchColumn());
        // entry_data + one page UPSERT + the queue INSERT.
        self::assertSame(3, $large['Com_insert']);
    }

    // ---------------------------------------------------------------
    // Ids and ordering
    // ---------------------------------------------------------------

    public function testReturnedIdsMatchStoredRowsInPayloadOrderAcrossModels(): void
    {
        $this->provisionPage(['i_str_01', 'i_int_01']);
        $modelA = $this->createModel(1);
        $modelB = $this->createModel(1);
        $this->reserveSlotFor($this->createField($modelA, 'string', true, 'title'));
        $this->reserveSlotFor($this->createField($modelB, 'int', true, 'rank'));

        $payloads = [];
        for ($i = 0; $i < 12; $i++) {
            $payloads[] = $i % 3 === 0
                ? new EntryPayload(1, $modelB, ['rank' => $i])
                : new EntryPayload(1, $modelA, ['title' => "t{$i}"]);
        }

        $results = $this->writeCommitted($this->chunkWriter(), $payloads);
        self::assertCount(12, $results);

        foreach ($results as $i => $result) {
            $stmt = $this->pdo->prepare('SELECT model_id, fields FROM entry_data WHERE id = ?');
            $stmt->execute([$result->entryId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray($row);
            self::assertSame($payloads[$i]->modelId, (int) $row['model_id']);
            self::assertSame($payloads[$i]->fields, json_decode((string) $row['fields'], true));
            self::assertCount(1, $result->slotsWritten);
            self::assertFalse($result->enqueuedForBackfill);
        }

        $ids = array_map(static fn (EntryWriteResult $r): int => $r->entryId, $results);
        $sorted = $ids;
        sort($sorted);
        self::assertSame($sorted, $ids, 'ids ascend in payload order');
        self::assertCount(12, array_unique($ids));
    }

    /**
     * Entries on one page that planned different columns are padded to
     * one column set with NULL. The padded NULL must land only where the
     * entry genuinely had no value, and must not reach a row that
     * already existed.
     */
    public function testNullPaddingOnASharedPageClobbersNothing(): void
    {
        $pageId = $this->provisionPage(['i_str_01', 'i_str_02', 'i_int_01']);
        $modelId = $this->createModel(1);
        $this->reserveSlotFor($this->createField($modelId, 'string', true, 'a'));
        $this->reserveSlotFor($this->createField($modelId, 'string', true, 'b'));
        $this->reserveSlotFor($this->createField($modelId, 'int', true, 'c'));
        $table = $this->pageTableNameFor($pageId);

        $bystander = $this->seedEntry(1, $modelId, ['a' => 'keep-a', 'b' => 'keep-b', 'c' => 7]);

        $results = $this->writeCommitted($this->chunkWriter(), [
            new EntryPayload(1, $modelId, ['a' => 'only-a']),
            new EntryPayload(1, $modelId, ['b' => 'only-b', 'c' => 2]),
            new EntryPayload(1, $modelId, ['a' => 'all-a', 'b' => 'all-b', 'c' => 3]),
        ]);

        $cols = static fn (array $row): array => [$row['i_str_01'], $row['i_str_02'], $row['i_int_01'] === null ? null : (int) $row['i_int_01']];

        self::assertSame(['only-a', null, null], $cols($this->slotRow($table, $results[0]->entryId)));
        self::assertSame([null, 'only-b', 2], $cols($this->slotRow($table, $results[1]->entryId)));
        self::assertSame(['all-a', 'all-b', 3], $cols($this->slotRow($table, $results[2]->entryId)));
        self::assertSame(['keep-a', 'keep-b', 7], $cols($this->slotRow($table, $bystander)));

        // slotsWritten reports the entry's own columns, never the padding.
        self::assertCount(1, $results[0]->slotsWritten);
        self::assertCount(2, $results[1]->slotsWritten);
        self::assertCount(3, $results[2]->slotsWritten);
    }

    // ---------------------------------------------------------------
    // ADR 0007 exhaustion enqueue
    // ---------------------------------------------------------------

    public function testExhaustionEnqueueLandsOnExactlyTheRightEntriesWithTheChunkId(): void
    {
        [$modelId, , , $slotted] = $this->setupFilterableStringField();
        $waiting = 'waiting';
        $this->createField($modelId, 'string', true, $waiting);

        $results = $this->writeCommitted($this->chunkWriter(), [
            new EntryPayload(1, $modelId, [$slotted => 'x']),
            new EntryPayload(1, $modelId, [$slotted => 'y', $waiting => 'w']),
            new EntryPayload(1, $modelId, [$slotted => 'z']),
            new EntryPayload(1, $modelId, [$waiting => 'v'], 'payload-own-id'),
        ], 'chunk-correlation-id');

        self::assertSame([false, true, false, true], array_map(
            static fn (EntryWriteResult $r): bool => $r->enqueuedForBackfill,
            $results,
        ));

        $queued = $this->pdo->query(
            'SELECT entry_id, origin_correlation_id FROM stardust_sync_queue ORDER BY entry_id'
        )->fetchAll(PDO::FETCH_KEY_PAIR);

        // The passed id wins over a payload's own, as it did per entry.
        self::assertSame([
            $results[1]->entryId => 'chunk-correlation-id',
            $results[3]->entryId => 'chunk-correlation-id',
        ], array_map('strval', $queued));
    }

    public function testQueueRowFallsBackToEachPayloadsOwnIdWhenNoChunkIdIsPassed(): void
    {
        [$modelId] = $this->setupFilterableStringField();
        $waiting = 'waiting';
        $this->createField($modelId, 'string', true, $waiting);

        $results = $this->writeCommitted($this->chunkWriter(), [
            new EntryPayload(1, $modelId, [$waiting => 'a'], 'first-own-id'),
            new EntryPayload(1, $modelId, [$waiting => 'b']),
            new EntryPayload(1, $modelId, [$waiting => 'c'], 'third-own-id'),
        ]);

        $stmt = $this->pdo->query(
            'SELECT entry_id, origin_correlation_id FROM stardust_sync_queue ORDER BY entry_id'
        );
        $queued = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        self::assertSame([
            $results[0]->entryId => 'first-own-id',
            $results[1]->entryId => null,
            $results[2]->entryId => 'third-own-id',
        ], $queued);
    }

    // ---------------------------------------------------------------
    // Failures write nothing
    // ---------------------------------------------------------------

    public function testDeletingModelAnywhereInTheChunkWritesNothing(): void
    {
        [$liveModel, , , $field] = $this->setupFilterableStringField();
        $deleting = $this->createModel(1);
        $this->markModelDeleted($deleting);
        $before = $this->countEntries();

        $this->pdo->beginTransaction();
        try {
            $this->chunkWriter()->writeChunk([
                new EntryPayload(1, $liveModel, [$field => 'a']),
                new EntryPayload(1, $liveModel, [$field => 'b']),
                new EntryPayload(1, $deleting, ['x' => 1]),
            ]);
            self::fail('Expected ModelDeletionInProgressException.');
        } catch (ModelDeletionInProgressException $e) {
            self::assertStringContainsString("Model {$deleting} is being deleted; no entry can be written to it.", $e->getMessage());
            // Planning precedes every write, so nothing reached the
            // table even before the caller rolls back.
            self::assertSame($before, $this->countEntries());
        } finally {
            $this->pdo->rollBack();
        }

        self::assertSame($before, $this->countEntries());
    }

    public function testDeletingModelOnTheImportPathFailsTheJobExactlyAsBefore(): void
    {
        [$liveModel, , , $field] = $this->setupFilterableStringField();
        $deleting = $this->createModel(1);
        $this->markModelDeleted($deleting);

        $artifactDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stardust-test-' . bin2hex(random_bytes(4));
        mkdir($artifactDir, 0777, true);

        try {
            [$jobId] = $this->writePendingImportJob(1, [
                ['tenant_id' => 1, 'model_id' => $liveModel, 'fields' => [$field => 'a']],
                ['tenant_id' => 1, 'model_id' => $deleting, 'fields' => ['x' => 1]],
            ], $artifactDir);

            $this->makeImportJobWorkSource(artifactDir: $artifactDir)->tickOne('test-corr-deleting');

            $stmt = $this->pdo->prepare('SELECT status, failed_reason, manifest FROM stardust_import_jobs WHERE id = ?');
            $stmt->execute([$jobId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray($job);
            self::assertSame('failed', $job['status']);
            self::assertSame('entry_write_failed', $job['failed_reason']);

            $manifest = json_decode((string) $job['manifest'], true);
            self::assertIsArray($manifest);
            self::assertArrayNotHasKey('entries_written', $manifest, 'a first-chunk failure writes no counters');
            self::assertSame('failed', $manifest['chunk_manifest'][0]['outcome']);

            $dlq = $this->pdo->query("SELECT COUNT(*) FROM stardust_reconciler_dlq WHERE source = 'bulk_import'")->fetchColumn();
            self::assertSame(1, (int) $dlq);
            self::assertSame(0, $this->countEntries(), 'the live model\'s entry rolled back with the chunk');
        } finally {
            foreach (glob($artifactDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($artifactDir);
        }
    }

    public function testUncoercibleValueAtEntryKWritesNothing(): void
    {
        [$modelId, , , $field] = $this->setupFilterableStringField();
        $before = $this->countEntries();

        $payloads = [];
        for ($i = 0; $i < 20; $i++) {
            $payloads[] = new EntryPayload(1, $modelId, [$field => $i === 13 ? str_repeat('x', 5000) : "ok{$i}"]);
        }

        $this->pdo->beginTransaction();
        try {
            $this->chunkWriter()->writeChunk($payloads);
            self::fail('Expected UncoercibleSlotValueException.');
        } catch (UncoercibleSlotValueException) {
            self::assertSame($before, $this->countEntries());
        } finally {
            $this->pdo->rollBack();
        }
    }

    // ---------------------------------------------------------------
    // Statement budgets
    // ---------------------------------------------------------------

    /**
     * A byte budget small enough to force one row per statement: the
     * statement count grows, the outcome does not.
     */
    public function testTinyBudgetsSplitIntoMoreStatementsAndStillCommitEverything(): void
    {
        [$modelId, $waiting] = $this->statementFixture();

        $payloads = [];
        for ($i = 0; $i < 7; $i++) {
            $payloads[] = new EntryPayload(1, $modelId, ['a' => "a{$i}", 'b' => "b{$i}", $waiting => "w{$i}"]);
        }

        $before = $this->statementCounters();
        $results = $this->writeCommitted($this->chunkWriter(maxPlaceholders: 10, maxStatementBytes: 1), $payloads);
        $delta = self::delta($before, $this->statementCounters());

        // entry_data: 5 placeholders per row, 10 max → but 1 byte forces
        // one row per statement, as do the page and queue writes.
        self::assertSame(3 * 7, $delta['Com_insert']);
        self::assertCount(7, $results);

        foreach ($results as $i => $result) {
            $row = $this->slotRow('entry_slots_page_1', $result->entryId);
            self::assertSame("a{$i}", $row['i_str_01']);
            self::assertSame("b{$i}", $row['i_str_02']);
        }
        self::assertSame(7, (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_sync_queue')->fetchColumn());
    }

    public function testPlaceholderBudgetAloneSplitsStatements(): void
    {
        [$modelId, $waiting] = $this->statementFixture();

        $payloads = [];
        for ($i = 0; $i < 9; $i++) {
            $payloads[] = new EntryPayload(1, $modelId, ['a' => "a{$i}", 'b' => "b{$i}", $waiting => "w{$i}"]);
        }

        // 12 placeholders: entry_data (5/row) → 2 rows, page (4/row) → 3
        // rows, queue (3/row) → 4 rows. 9 entries → 5 + 3 + 3 statements.
        $before = $this->statementCounters();
        $results = $this->writeCommitted($this->chunkWriter(maxPlaceholders: 12), $payloads);
        $delta = self::delta($before, $this->statementCounters());

        self::assertSame(5 + 3 + 3, $delta['Com_insert']);
        $ids = array_map(static fn (EntryWriteResult $r): int => $r->entryId, $results);
        $stored = array_map('intval', $this->pdo->query('SELECT id FROM entry_data ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame($stored, $ids);
    }

    /**
     * Sub-batches are statements inside the caller's transaction, not
     * transactions of their own: a failure in the last statement of a
     * split chunk takes the earlier ones with it. The failure is an
     * over-long correlation id, which only the queue INSERT, the final
     * statement, can trip.
     */
    public function testASplitChunkThatFailsLateRollsBackEveryEarlierStatement(): void
    {
        [$modelId, $waiting] = $this->statementFixture();

        $payloads = [];
        for ($i = 0; $i < 6; $i++) {
            $payloads[] = new EntryPayload(1, $modelId, ['a' => "a{$i}", $waiting => "w{$i}"]);
        }

        $ingestor = new BulkIngestor(
            pdo: $this->pdo,
            entryWriter: new EntryWriter(
                pdo: $this->pdo,
                clock: new SystemClock(),
                logger: new NullLogger(),
                slotRowUpserter: new SlotRowUpserter($this->pdo),
                chunkWriter: $this->chunkWriter(maxStatementBytes: 1),
            ),
            logger: new NullLogger(),
        );

        $result = $ingestor->ingest($payloads, new BulkIngestOptions(correlationId: str_repeat('c', 64)));

        self::assertSame(BulkChunkResult::OUTCOME_ROLLED_BACK, $result->chunks[0]->outcome);
        self::assertSame(0, $this->countEntries());
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM entry_slots_page_1')->fetchColumn());
    }

    // ---------------------------------------------------------------
    // ADR 0036 / 0037 canonicalisation across a chunk
    // ---------------------------------------------------------------

    public function testRenameInFlightCanonicalisesEveryPayloadInTheChunk(): void
    {
        [$modelId, $fieldId, $pageId] = $this->setupFilterableStringField();
        $this->makeRenameInitiator()->initiate(1, $fieldId, 'label');

        $results = $this->writeCommitted($this->chunkWriter(), [
            new EntryPayload(1, $modelId, ['name' => 'old-key']),
            new EntryPayload(1, $modelId, ['label' => 'new-key']),
            new EntryPayload(1, $modelId, ['name' => 'old-again', 'extra' => 1]),
        ]);

        self::assertSame(['label' => 'old-key'], $this->rawPayload($results[0]->entryId));
        self::assertSame(['label' => 'new-key'], $this->rawPayload($results[1]->entryId));
        $third = $this->rawPayload($results[2]->entryId);
        self::assertIsArray($third);
        ksort($third);
        self::assertSame(['extra' => 1, 'label' => 'old-again'], $third);

        $table = $this->pageTableNameFor($pageId);
        self::assertSame('old-key', $this->slotRow($table, $results[0]->entryId)['i_str_01']);
        self::assertSame('old-again', $this->slotRow($table, $results[2]->entryId)['i_str_01']);
    }

    public function testDeletedFieldKeyIsStrippedFromEveryPayloadInTheChunk(): void
    {
        [$modelId, , , $kept] = $this->setupFilterableStringField();
        $goneId = $this->createField($modelId, 'string', false, 'gone');
        self::assertTrue($this->makeDeleteFieldInitiator()->initiate(1, $goneId));

        $results = $this->writeCommitted($this->chunkWriter(), [
            new EntryPayload(1, $modelId, [$kept => 'a', 'gone' => 'x']),
            new EntryPayload(1, $modelId, ['gone' => 'y']),
            new EntryPayload(1, $modelId, [$kept => 'c']),
        ]);

        self::assertSame([$kept => 'a'], $this->rawPayload($results[0]->entryId));
        self::assertSame([], $this->rawPayload($results[1]->entryId));
        self::assertSame([$kept => 'c'], $this->rawPayload($results[2]->entryId));
    }

    public function testEmptyChunkIssuesNoStatement(): void
    {
        // The SHOW STATUS probe itself is a statement under native
        // prepares, so the baseline is two probes back to back.
        $a = $this->statementCounters();
        $probeOnly = self::delta($a, $this->statementCounters());

        $before = $this->statementCounters();
        self::assertSame([], $this->chunkWriter()->writeChunk([]));
        self::assertSame($probeOnly, self::delta($before, $this->statementCounters()));
    }

    public function testImportWindowRecordsTheChunkIdRangeFromTheBatchedWrite(): void
    {
        [$modelId, , , $field] = $this->setupFilterableStringField();

        $artifactDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'stardust-test-' . bin2hex(random_bytes(4));
        mkdir($artifactDir, 0777, true);

        try {
            $entries = [];
            for ($i = 0; $i < 7; $i++) {
                $entries[] = ['tenant_id' => 1, 'model_id' => $modelId, 'fields' => [$field => "v{$i}"]];
            }
            [$jobId] = $this->writePendingImportJob(1, $entries, $artifactDir);

            self::assertSame(
                TickOutcome::WORK_DONE,
                $this->makeImportJobWorkSource(artifactDir: $artifactDir, chunkSize: 3)->tickOne('test-corr-range'),
            );

            $stmt = $this->pdo->prepare('SELECT manifest FROM stardust_import_jobs WHERE id = ?');
            $stmt->execute([$jobId]);
            $manifest = json_decode((string) $stmt->fetchColumn(), true);
            self::assertIsArray($manifest);
            self::assertSame(7, $manifest['entries_written']);

            $ids = array_map('intval', $this->pdo->query('SELECT id FROM entry_data ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
            self::assertSame(
                [[$ids[0], $ids[2]], [$ids[3], $ids[5]], [$ids[6], $ids[6]]],
                array_map(static fn (array $r): array => [$r['entry_id_first'], $r['entry_id_last']], $manifest['chunk_manifest']),
            );
        } finally {
            foreach (glob($artifactDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($artifactDir);
        }
    }
}
