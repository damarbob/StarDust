<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Reconciler;

use StarDust\Exception\DlqReplayNotFoundException;
use StarDust\Tests\Smoke\Phase5TestCase;

/**
 * Operator DLQ replay exit criteria (ADR 0018):
 *   - by `--id`: single row re-enqueued, DLQ deleted, retry_count
 *     incremented before delete (the increment is the audit signal —
 *     we verify it by counting the bump in a paired smoke).
 *   - by `--reason`: every matching row re-enqueued in one transaction.
 *   - no rows match → typed exception.
 */
final class DlqReplayTest extends Phase5TestCase
{
    public function testReplayByIdReinsertsAndDeletes(): void
    {
        $dlqId = $this->seedDlqRow('sync_queue', 4242, 'missing_entry_data', 'corr-original');

        $manifest = $this->makeDlqReplayer()->replayById($dlqId);

        self::assertSame(1, $manifest->count());
        self::assertSame([4242], $manifest->entryIds);
        self::assertSame([$dlqId], $manifest->dlqIds);

        $remaining = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM stardust_reconciler_dlq WHERE id = ' . $dlqId
        )->fetchColumn();
        self::assertSame(0, $remaining);

        $enqueued = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM stardust_sync_queue WHERE entry_id = 4242'
        )->fetchColumn();
        self::assertSame(1, $enqueued);
    }

    public function testReplayByReasonReinsertsAllMatchingRows(): void
    {
        $this->seedDlqRow('sync_queue', 10, 'missing_entry_data', 'corr-a');
        $this->seedDlqRow('sync_queue', 11, 'missing_entry_data', 'corr-b');
        $this->seedDlqRow('sync_queue', 12, 'schema_incompatibility', 'corr-c');

        $manifest = $this->makeDlqReplayer()->replayByReason('missing_entry_data');

        self::assertSame(2, $manifest->count());
        self::assertSame([10, 11], $manifest->entryIds);

        $remaining = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM stardust_reconciler_dlq WHERE reason = 'missing_entry_data'"
        )->fetchColumn();
        self::assertSame(0, $remaining);

        $other = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM stardust_reconciler_dlq WHERE reason = 'schema_incompatibility'"
        )->fetchColumn();
        self::assertSame(1, $other, 'Unrelated reasons must not be replayed.');

        $enqueued = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM stardust_sync_queue WHERE entry_id IN (10, 11)'
        )->fetchColumn();
        self::assertSame(2, $enqueued);
    }

    public function testReplayByIdThrowsWhenIdMissing(): void
    {
        $this->expectException(DlqReplayNotFoundException::class);
        $this->makeDlqReplayer()->replayById(999_999);
    }

    public function testReplayByReasonThrowsWhenNoneMatch(): void
    {
        $this->expectException(DlqReplayNotFoundException::class);
        $this->makeDlqReplayer()->replayByReason('malformed_json');
    }

    public function testBulkImportDlqWithoutEntryIdIsRemovedButNotEnqueued(): void
    {
        $dlqId = $this->seedDlqRow('bulk_import', null, 'malformed_json', 'corr-bulk');

        $manifest = $this->makeDlqReplayer()->replayById($dlqId);
        self::assertSame(1, $manifest->count());
        self::assertSame([], $manifest->entryIds);

        $remaining = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM stardust_reconciler_dlq WHERE id = ' . $dlqId
        )->fetchColumn();
        self::assertSame(0, $remaining);

        $queueRows = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM stardust_sync_queue'
        )->fetchColumn();
        self::assertSame(0, $queueRows);
    }
}
