<?php

declare(strict_types=1);

namespace StarDust\Write;

use DateTimeZone;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Soft delete for a single entry.
 *
 * Stamps `entry_data.deleted_at` and nothing else. That one column is
 * the whole mechanism: every read surface already excludes soft-deleted
 * rows — `BoundedFetch` and `MysqlNativeDriver` on the read/search path,
 * `EntryDataPager` on the export path — so the row disappears from
 * reads, filters, point-reads, and exports the moment this commits.
 *
 * ## Slot columns are deliberately left alone
 *
 * The `entry_slots_page_N` row keeps its values. Nothing reads it
 * without joining through a non-deleted `entry_data` row, so the stale
 * values are unreachable, and clearing them would mean an extra write
 * per occupied page for no observable difference. This also mirrors how
 * the Liberator already treats slot data — reclamation nullifies on the
 * *slot's* lifecycle, not the entry's.
 *
 * ## Idempotent, and not an error
 *
 * `delete()` returns `false` rather than throwing when the row does not
 * exist, belongs to another tenant, or was already deleted. A repeated
 * delete is a normal thing for a caller to do (a retried request, a
 * double-clicked button) and the outcome the caller wanted is already
 * true. `StarDust::updateEntry()` takes the opposite stance and throws,
 * because silently not applying an update loses data the caller
 * believed it had written.
 *
 * There is no hard delete and no restore. `deleted_at` is the only
 * lifecycle transition the schema models today; purging would have to
 * reclaim slot columns and is a separate design question.
 *
 * Structured-log events (closed vocabulary per ADR 0020):
 *   - `entry_deleted` (source: `api`) — only on an actual transition
 */
final class EntryDeleter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return bool `true` iff this call performed the transition
     */
    public function delete(int $tenantId, int $entryId): bool
    {
        TenantId::assertValid($tenantId);

        $now = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            // `deleted_at IS NULL` in the WHERE is what makes the call
            // idempotent without a prior SELECT: a second delete matches
            // zero rows instead of overwriting the original timestamp.
            //
            // ADR 0038 rides the same statement rather than adding a
            // pre-SELECT, which would cost this class the no-pre-SELECT
            // idempotence its whole design rests on. Soft-deleting a row
            // whose model is being hard-purged achieves nothing, so the
            // join makes it match zero rows and return `false` — matching
            // the idempotent posture already documented above.
            //
            // **The join MUST be LEFT.** An INNER join would require a
            // live `stardust_models` row, and several fixtures (and any
            // real deployment predating the registry) carry `entry_data`
            // rows whose `model_id` has no registry row at all. Those
            // would silently start returning `false`. With LEFT, a missing
            // model yields NULL, which satisfies `IS NULL` — absent is not
            // deleting.
            $stmt = $this->pdo->prepare(
                'UPDATE entry_data e'
                . ' LEFT JOIN stardust_models m ON m.id = e.model_id'
                . ' SET e.deleted_at = ?, e.updated_at = ?'
                . ' WHERE e.id = ? AND e.tenant_id = ? AND e.deleted_at IS NULL'
                . '   AND m.deleted_at IS NULL'
            );
            $stmt->execute([$now, $now, $entryId, $tenantId]);
            $deleted = $stmt->rowCount() === 1;

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        if ($deleted) {
            $this->logger->info('entry deleted', [
                'event'     => 'entry_deleted',
                'source'    => 'api',
                'tenant_id' => $tenantId,
                'entry_id'  => $entryId,
            ]);
        }

        return $deleted;
    }
}
