<?php

declare(strict_types=1);

namespace StarDust\Reconciler;

/**
 * Audit manifest returned by {@see DlqReplayer::replayByReason()} and
 * (single-row) {@see DlqReplayer::replayById()}.
 *
 * `dlqIds` is the list of `stardust_reconciler_dlq.id` values the
 * replay consumed (deleted from DLQ + re-enqueued into
 * `stardust_sync_queue`). `entryIds` is the matching list of
 * `entry_id` values now back in the queue. Operators write this to a
 * ticket; the CLI prints the counts.
 */
final class ReplayManifest
{
    /**
     * @param list<int> $dlqIds
     * @param list<int> $entryIds
     */
    public function __construct(
        public readonly array $dlqIds,
        public readonly array $entryIds,
    ) {
    }

    public function count(): int
    {
        return count($this->dlqIds);
    }
}
