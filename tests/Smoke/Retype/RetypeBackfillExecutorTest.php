<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Retype;

use InvalidArgumentException;
use StarDust\Retype\ChunkResult;
use StarDust\Retype\RetypeBackfillExecutor;
use StarDust\Retype\RetypeCheckpoint;
use StarDust\Slot\SlotAssignment;
use StarDust\Tests\Smoke\Phase6bTestCase;
use StarDust\Write\SlotRowUpserter;

/**
 * The executor's statement count, called directly so the count is the
 * chunk's alone and not the work source's claim, cursor and sampler
 * statements. The value and event semantics are covered end to end by
 * {@see RetypeBackfillTest}.
 */
final class RetypeBackfillExecutorTest extends Phase6bTestCase
{
    /** Mixed on purpose: the middle one cannot become an int. */
    private const VALUES = ['10', '20', 'x', '40', '50', '60', '70'];
    private const EXPECTED = ['10', '20', null, '40', '50', '60', '70'];

    public function testAWholeChunkIsOneUpsertStatement(): void
    {
        [$checkpoint, $slot, $table, $ids] = $this->scenario();

        $before = $this->insertStatements();
        $result = $this->executor()->processChunk($checkpoint, $slot, 'value', 'string', 'int', $table, 500);
        $statements = $this->insertStatements() - $before;

        self::assertSame(1, $statements, 'Seven rows must cost one multi-row UPSERT, not seven.');
        $this->assertBackfilled($table, $slot, $ids);
        $this->assertChunkResult($result, $ids);
    }

    public function testTheChunkSplitsWhenThePlaceholderBudgetIsSmall(): void
    {
        [$checkpoint, $slot, $table, $ids] = $this->scenario();

        // Six placeholders at three per row is two rows a statement: 7 rows, 4 statements.
        $before = $this->insertStatements();
        $result = $this->executor(maxPlaceholders: 6)->processChunk($checkpoint, $slot, 'value', 'string', 'int', $table, 500);
        $statements = $this->insertStatements() - $before;

        self::assertSame(4, $statements);
        $this->assertBackfilled($table, $slot, $ids);
        $this->assertChunkResult($result, $ids);
    }

    public function testTheChunkSplitsWhenTheByteBudgetIsSmall(): void
    {
        [$checkpoint, $slot, $table, $ids] = $this->scenario();

        // A budget of one byte puts every row in a statement of its own, which
        // is what a chunk of values larger than max_allowed_packet must do.
        $before = $this->insertStatements();
        $result = $this->executor(maxStatementBytes: 1)->processChunk($checkpoint, $slot, 'value', 'string', 'int', $table, 500);
        $statements = $this->insertStatements() - $before;

        self::assertSame(7, $statements);
        $this->assertBackfilled($table, $slot, $ids);
        $this->assertChunkResult($result, $ids);
    }

    public function testReprocessingAChunkWritesTheSameRowsAgain(): void
    {
        [$checkpoint, $slot, $table, $ids] = $this->scenario();
        $executor = $this->executor(maxPlaceholders: 6);

        // What a resume after a crash between the UPSERTs and the commit does.
        $executor->processChunk($checkpoint, $slot, 'value', 'string', 'int', $table, 500);
        $second = $executor->processChunk($checkpoint, $slot, 'value', 'string', 'int', $table, 500);

        $this->assertBackfilled($table, $slot, $ids);
        $this->assertChunkResult($second, $ids);
        self::assertSame(7, $this->countPageRowsFor($table, $ids), 'A repeat must update the rows, not add any.');
    }

    public function testAnEmptyChunkIssuesNoUpsert(): void
    {
        [$checkpoint, $slot, $table, $ids] = $this->scenario(cursorAfterLastEntry: true);

        $before = $this->insertStatements();
        $result = $this->executor()->processChunk($checkpoint, $slot, 'value', 'string', 'int', $table, 500);

        self::assertSame(0, $this->insertStatements() - $before);
        self::assertSame(0, $result->rowsProcessed);
        self::assertTrue($result->isFinalChunk);
        self::assertSame($ids[count($ids) - 1], $result->newCursor, 'The cursor must not move on an empty chunk.');
    }

    public function testABudgetBelowOneIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->executor(maxPlaceholders: 0);
    }

    public function testAByteBudgetBelowOneIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->executor(maxStatementBytes: 0);
    }

    private function executor(?int $maxPlaceholders = null, ?int $maxStatementBytes = null): RetypeBackfillExecutor
    {
        $upserter = new SlotRowUpserter($this->pdo);

        return $maxPlaceholders === null
            ? new RetypeBackfillExecutor($this->pdo, $upserter, maxStatementBytes: $maxStatementBytes)
            : new RetypeBackfillExecutor($this->pdo, $upserter, $maxPlaceholders, $maxStatementBytes);
    }

    /**
     * A tenant with one string field holding {@see self::VALUES}, retyped to
     * int so the replacement slot is `backfilling` and unfilled.
     *
     * @return array{RetypeCheckpoint, SlotAssignment, string, list<int>}
     */
    private function scenario(bool $cursorAfterLastEntry = false): array
    {
        $this->provisionPage(['i_str_01', 'i_int_01']);
        $modelId = $this->createModel(1);
        $fieldId = $this->createField($modelId, 'string', true, 'value');
        $this->reserveSlotFor($fieldId);

        $ids = [];
        foreach (self::VALUES as $value) {
            $ids[] = $this->seedEntry(1, $modelId, ['value' => $value]);
        }

        $this->makeRetypeInitiator()->initiate(
            tenantId: 1,
            fieldId: $fieldId,
            newDeclaredType: 'int',
            newIsFilterable: null,
        );

        $row = $this->fetchLiveSlotForField($fieldId);
        self::assertNotNull($row);
        self::assertSame('backfilling', $row['status'], 'The fixture must leave the new slot unfilled.');

        $slot = new SlotAssignment(
            pageId: (int) $row['page_id'],
            slotColumn: (string) $row['slot_column'],
            slotAssignmentId: (int) $row['id'],
            slotType: (string) $row['slot_type'],
        );
        $checkpointRow = $this->fetchCheckpointForField($fieldId);
        self::assertNotNull($checkpointRow);

        $checkpoint = new RetypeCheckpoint(
            id: (int) $checkpointRow['id'],
            fieldId: $fieldId,
            tenantId: 1,
            modelId: $modelId,
            lastProcessedId: $cursorAfterLastEntry ? $ids[count($ids) - 1] : 0,
            sourceDeclaredType: 'string',
            targetDeclaredType: 'int',
            targetIsFilterable: true,
            fieldName: 'value',
        );

        return [$checkpoint, $slot, $this->pageTableNameFor($slot->pageId), $ids];
    }

    /** @param list<int> $ids */
    private function assertBackfilled(string $table, SlotAssignment $slot, array $ids): void
    {
        foreach ($ids as $i => $id) {
            $stored = $this->fetchSlotValue($table, $id, $slot->slotColumn);
            self::assertSame(
                self::EXPECTED[$i],
                $stored === null ? null : (string) $stored,
                "Entry {$id} (value '" . self::VALUES[$i] . "') holds the wrong slot value.",
            );
        }
    }

    /** @param list<int> $ids */
    private function assertChunkResult(ChunkResult $result, array $ids): void
    {
        self::assertSame(count($ids), $result->rowsProcessed);
        self::assertSame($ids[count($ids) - 1], $result->newCursor);
        self::assertTrue($result->isFinalChunk);
        self::assertCount(1, $result->nullEvents, "Only the value 'x' cannot be coerced.");
        self::assertSame($ids[2], $result->nullEvents[0]->entryId);
    }

    /** Server-side count of INSERT statements this connection has run. */
    private function insertStatements(): int
    {
        $row = $this->pdo->query("SHOW SESSION STATUS LIKE 'Com\\_insert'")->fetch(\PDO::FETCH_NUM);
        self::assertIsArray($row);

        return (int) $row[1];
    }
}
