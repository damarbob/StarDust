<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use DateTimeZone;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use StarDust\Clock\SystemClock;
use StarDust\Liberator\Liberator;
use StarDust\Liberator\SlotSweeper;
use StarDust\Liberator\TombstonedSlotRepository;

/**
 * Shared scaffolding for Phase 6a Liberator smoke tests. Builds on
 * Phase 5 helpers and adds:
 *   - {@see self::tombstoneSlotAssignment()} flips a live slot to
 *     `tombstoned` with `tombstoned_at = NOW()` and `field_id = NULL`,
 *     mimicking the API path Phase 6b will eventually own.
 *   - {@see self::makeLiberator()}, {@see self::makeSlotSweeper()},
 *     and {@see self::makeTombstonedSlotRepository()} compose the
 *     daemon's collaborators bound to the test PDO.
 *   - {@see self::seedSlotValues()} writes raw values into a slot
 *     column via direct INSERT, bypassing the write path — useful when
 *     a test wants to observe nullification on data that did not pass
 *     through a real EntryWriter.
 */
abstract class Phase6aTestCase extends Phase5TestCase
{
    protected function tombstoneSlotAssignment(int $slotAssignmentId): void
    {
        // Two-step UPDATE so the partial unique constraint on field_id
        // (live statuses only) does not race with the field_id clear.
        $stmt = $this->pdo->prepare(
            "UPDATE stardust_slot_assignments"
            . " SET status = 'tombstoned', field_id = NULL, tombstoned_at = UTC_TIMESTAMP()"
            . " WHERE id = ?"
        );
        $stmt->execute([$slotAssignmentId]);
    }

    protected function setTombstonedAt(int $slotAssignmentId, string $utcDateTime): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE stardust_slot_assignments SET tombstoned_at = ? WHERE id = ?'
        );
        $stmt->execute([$utcDateTime, $slotAssignmentId]);
    }

    /**
     * Seeds N bare `entry_data` + `entry_slots_page_X` rows with a
     * non-null value in `$slotColumn`. Bypasses `EntryWriter` so tests
     * can build large fixtures cheaply without going through the JSON
     * payload + slot UPSERT path. The slot table's FK to `entry_data`
     * forces us to create the data rows first.
     *
     * @return list<int> the created entry_data ids
     */
    protected function seedSlotValues(
        int $modelId,
        string $tableName,
        string $slotColumn,
        int $count,
        string $value = 'tombstoned-value',
        int $tenantId = 1,
    ): array {
        $entryDataStmt = $this->pdo->prepare(
            'INSERT INTO entry_data (tenant_id, model_id, created_at, updated_at, fields)'
            . " VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), JSON_OBJECT('seed', ?))"
        );
        $slotStmt = $this->pdo->prepare(
            "INSERT INTO {$tableName} (entry_id, tenant_id, {$slotColumn}) VALUES (?, ?, ?)"
        );

        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $entryDataStmt->execute([$tenantId, $modelId, $i]);
            $entryId = (int) $this->pdo->lastInsertId();
            $slotStmt->execute([$entryId, $tenantId, $value]);
            $ids[] = $entryId;
        }
        return $ids;
    }

    protected function pageTableNameFor(int $pageId): string
    {
        $stmt = $this->pdo->prepare('SELECT table_name FROM stardust_pages WHERE id = ?');
        $stmt->execute([$pageId]);
        return (string) $stmt->fetchColumn();
    }

    protected function fetchSlotAssignment(int $slotAssignmentId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM stardust_slot_assignments WHERE id = ?');
        $stmt->execute([$slotAssignmentId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, "Slot assignment {$slotAssignmentId} not found.");
        return $row;
    }

    protected function fetchSchemaVersion(): int
    {
        return (int) $this->pdo
            ->query('SELECT version FROM stardust_schema_version WHERE id = 1')
            ->fetchColumn();
    }

    protected function countNonNullValues(string $tableName, string $slotColumn): int
    {
        return (int) $this->pdo
            ->query("SELECT COUNT(*) FROM {$tableName} WHERE {$slotColumn} IS NOT NULL")
            ->fetchColumn();
    }

    protected function makeTombstonedSlotRepository(int $batchSize = 50): TombstonedSlotRepository
    {
        return new TombstonedSlotRepository(pdo: $this->pdo, batchSize: $batchSize);
    }

    protected function makeSlotSweeper(
        ?LoggerInterface $logger = null,
        int $chunkSize = 500,
        int $interChunkDelayMicros = 0,
        int $deadlockRetryBudget = 3,
        ?callable $sleepFn = null,
    ): SlotSweeper {
        return new SlotSweeper(
            pdo: $this->pdo,
            logger: $logger ?? new NullLogger(),
            chunkSize: $chunkSize,
            interChunkDelayMicros: $interChunkDelayMicros,
            deadlockRetryBudget: $deadlockRetryBudget,
            sleepFn: $sleepFn ?? static fn (int $_micros) => null,
        );
    }

    protected function makeLiberator(
        ?LoggerInterface $logger = null,
        int $batchSize = 50,
        int $chunkSize = 500,
        int $deadlockRetryBudget = 3,
        ?callable $sleepFn = null,
    ): Liberator {
        $log = $logger ?? new NullLogger();
        return new Liberator(
            logger: $log,
            repository: $this->makeTombstonedSlotRepository($batchSize),
            sweeper: $this->makeSlotSweeper(
                logger: $log,
                chunkSize: $chunkSize,
                interChunkDelayMicros: 0,
                deadlockRetryBudget: $deadlockRetryBudget,
                sleepFn: $sleepFn,
            ),
        );
    }

    /** Read NDJSON lines back from a recording stream. @return list<array<string,mixed>> */
    protected function readNdjsonStream($stream): array
    {
        rewind($stream);
        $lines = array_values(array_filter(explode("\n", (string) stream_get_contents($stream))));
        return array_map(
            static fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            $lines,
        );
    }

    protected function utcNowString(): string
    {
        return (new SystemClock())->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }
}
