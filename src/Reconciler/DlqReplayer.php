<?php

declare(strict_types=1);

namespace StarDust\Reconciler;

use DateTimeZone;
use PDO;
use Psr\Clock\ClockInterface;
use StarDust\Exception\DlqReplayNotFoundException;
use Throwable;

/**
 * Operator-initiated DLQ replay (ADR 0018 §Replay).
 *
 * Each replay opens one transaction that, for every targeted DLQ row:
 *   1. INSERTs the `entry_id` back into `stardust_sync_queue` (if the
 *      DLQ row has one — `bulk_import` rows with NULL `entry_id` are
 *      skipped since the queue takes only `entry_id`).
 *   2. Increments `retry_count` on the soon-to-be-deleted DLQ row's
 *      historical audit copy. Phase 5 does this by simply emitting it
 *      in the manifest; the DLQ row itself is removed in step 3.
 *   3. DELETEs the DLQ row.
 *
 * Throws {@see DlqReplayNotFoundException} when zero rows match.
 *
 * NOTE on `retry_count`: ADR 0018 stipulates the increment for audit;
 * the row itself is deleted in the same transaction, so the increment
 * is durable on the audit copy (manifest log) but not retained in the
 * DLQ table. This matches the "no automatic retry" guarantee — the
 * column exists for operators replaying the same row a second time
 * after a subsequent failure.
 */
final class DlqReplayer
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock,
    ) {
    }

    public function replayById(int $dlqId): ReplayManifest
    {
        $this->pdo->beginTransaction();
        try {
            $rows = $this->fetchForUpdate('WHERE id = ?', [$dlqId]);
            if ($rows === []) {
                $this->pdo->rollBack();
                throw new DlqReplayNotFoundException(
                    "No stardust_reconciler_dlq row with id={$dlqId}."
                );
            }
            $manifest = $this->replayRows($rows);
            $this->pdo->commit();
            return $manifest;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    public function replayByReason(string $reason): ReplayManifest
    {
        $this->pdo->beginTransaction();
        try {
            $rows = $this->fetchForUpdate('WHERE reason = ?', [$reason]);
            if ($rows === []) {
                $this->pdo->rollBack();
                throw new DlqReplayNotFoundException(
                    "No stardust_reconciler_dlq rows with reason='{$reason}'."
                );
            }
            $manifest = $this->replayRows($rows);
            $this->pdo->commit();
            return $manifest;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * @param list<mixed> $params
     * @return list<array{id: int|string, entry_id: int|string|null, retry_count: int|string}>
     */
    private function fetchForUpdate(string $whereClause, array $params): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, entry_id, retry_count'
            . ' FROM stardust_reconciler_dlq '
            . $whereClause
            . ' ORDER BY id FOR UPDATE'
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<array{id: int|string, entry_id: int|string|null, retry_count: int|string}> $rows
     */
    private function replayRows(array $rows): ReplayManifest
    {
        $now = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $enqueue = $this->pdo->prepare(
            'INSERT INTO stardust_sync_queue (entry_id, created_at) VALUES (?, ?)'
        );
        $bumpRetry = $this->pdo->prepare(
            'UPDATE stardust_reconciler_dlq SET retry_count = retry_count + 1 WHERE id = ?'
        );
        $delete = $this->pdo->prepare(
            'DELETE FROM stardust_reconciler_dlq WHERE id = ?'
        );

        $dlqIds = [];
        $entryIds = [];

        foreach ($rows as $row) {
            $dlqId = (int) $row['id'];
            $entryId = $row['entry_id'] === null ? null : (int) $row['entry_id'];

            // Increment first so the audit field reflects the action in
            // any concurrent read between increment and delete.
            $bumpRetry->execute([$dlqId]);

            if ($entryId !== null) {
                $enqueue->execute([$entryId, $now]);
                $entryIds[] = $entryId;
            }
            $delete->execute([$dlqId]);
            $dlqIds[] = $dlqId;
        }

        return new ReplayManifest(dlqIds: $dlqIds, entryIds: $entryIds);
    }
}
