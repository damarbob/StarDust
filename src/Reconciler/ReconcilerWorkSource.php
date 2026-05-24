<?php

declare(strict_types=1);

namespace StarDust\Reconciler;

/**
 * One pluggable source of work for the Reconciler. Phase 5 ships two:
 *   - {@see SyncQueueWorkSource}: drains `stardust_sync_queue`.
 *   - {@see ImportJobWorkSource}: claims and processes
 *     `stardust_import_jobs` rows.
 *
 * The interface is intentionally single-method (ISP). A future Phase
 * 6b retype-backfill source can plug in here without {@see Reconciler}
 * changing.
 *
 * The chunk correlation id is generated once per {@see Reconciler::tick()}
 * and threaded down so every event for this chunk shares an id
 * (Phase 5 exit criterion: DLQ rows must carry the same
 * `chunk_correlation_id` as the chunk's structured-log events).
 */
interface ReconcilerWorkSource
{
    public function tickOne(string $chunkCorrelationId): TickOutcome;
}
