<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Reconciler;

use PDO;
use StarDust\Tests\Smoke\Phase5TestCase;

/**
 * Phase 5 sync-queue drain exit criteria:
 *   - claimed rows are removed from `stardust_sync_queue`;
 *   - each drained entry gains a row on the matching `entry_slots_page_N`;
 *   - exactly one `chunk_complete` event per chunk.
 */
final class SyncQueueDrainTest extends Phase5TestCase
{
    public function testDrainsAllQueuedRows(): void
    {
        // Provision a page WITH the field's slot reserved BEFORE the
        // entry write. We then write the entry through the normal path
        // (which will populate the slot in the same tx), DELETE the
        // slot row, and enqueue — simulating the post-fact state the
        // Reconciler is meant to repair.
        [$modelId, $_fieldId, $pageId, $fieldName] = $this->setupModelWithReservedField(1, 'string');

        $entryIds = [];
        for ($i = 0; $i < 3; $i++) {
            $entryIds[] = $this->seedEntry(1, $modelId, [$fieldName => 'value-' . $i]);
        }

        // Clear the slot rows so the Reconciler has work to do.
        $tableName = $this->pageTableName($pageId);
        $this->pdo->exec("DELETE FROM {$tableName}");

        foreach ($entryIds as $id) {
            $this->enqueueSyncRow($id);
        }

        $source = $this->makeSyncQueueWorkSource();
        $outcome = $source->tickOne('test-corr-1');

        self::assertSame(\StarDust\Reconciler\TickOutcome::WORK_DONE, $outcome);
        self::assertSame(0, $this->countQueueRows(), 'Queue should be empty after drain.');
        self::assertSame(count($entryIds), $this->countSlotRows($tableName));
    }

    public function testIdleWhenQueueIsEmpty(): void
    {
        $source = $this->makeSyncQueueWorkSource();
        $outcome = $source->tickOne('test-corr-empty');
        self::assertSame(\StarDust\Reconciler\TickOutcome::IDLE, $outcome);
    }

    public function testMissingEntryDataGoesToDlq(): void
    {
        // No entry_data row at all — `BackfillExecutor` throws
        // EntryDataMissingException, which routes to DLQ.
        $this->enqueueSyncRow(99_999);

        $source = $this->makeSyncQueueWorkSource();
        $outcome = $source->tickOne('test-corr-missing');

        self::assertSame(\StarDust\Reconciler\TickOutcome::WORK_DONE, $outcome);
        self::assertSame(0, $this->countQueueRows());
        self::assertSame(1, $this->countDlqRows());

        $dlq = $this->fetchLatestDlqRow();
        self::assertSame('sync_queue', $dlq['source']);
        self::assertSame('missing_entry_data', $dlq['reason']);
        self::assertSame('test-corr-missing', $dlq['chunk_correlation_id']);
        self::assertSame(99_999, (int) $dlq['entry_id']);
    }

    private function countQueueRows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_sync_queue')->fetchColumn();
    }

    private function countDlqRows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM stardust_reconciler_dlq')->fetchColumn();
    }

    private function fetchLatestDlqRow(): array
    {
        $row = $this->pdo->query(
            'SELECT * FROM stardust_reconciler_dlq ORDER BY id DESC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }

    private function pageTableName(int $pageId): string
    {
        $stmt = $this->pdo->prepare('SELECT table_name FROM stardust_pages WHERE id = ?');
        $stmt->execute([$pageId]);
        return (string) $stmt->fetchColumn();
    }

    private function countSlotRows(string $tableName): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$tableName}")->fetchColumn();
    }
}
