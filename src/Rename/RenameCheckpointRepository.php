<?php

declare(strict_types=1);

namespace StarDust\Rename;

use PDO;

/**
 * All SQL against `backfill_checkpoints` rows whose `job_name` matches
 * `rename_field_%`.
 *
 * The prefix is disjoint from {@see \StarDust\Retype\RetypeCheckpointRepository}'s
 * `retype_field_`, so neither repository's `LIKE` scan can ever claim the
 * other's rows, and the two lifecycles coexist in one table without a
 * discriminator column.
 */
final class RenameCheckpointRepository
{
    public const JOB_NAME_PREFIX = 'rename_field_';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function jobNameFor(int $fieldId): string
    {
        return self::JOB_NAME_PREFIX . $fieldId;
    }

    /**
     * Claims one `status='running'` rename checkpoint via `FOR UPDATE
     * SKIP LOCKED`, held for the surrounding chunk transaction so a
     * second Reconciler worker cannot claim the same row mid-chunk.
     *
     * The join to `stardust_fields` is INNER, so a checkpoint whose
     * field row has been deleted is silently invisible here — the same
     * shape as the retype repository. That matters for `deleteField()`,
     * which must refuse or clean up rather than orphan a `running` row.
     *
     * `previous_name IS NOT NULL` is an integrity guard, not an
     * optimisation: a running rename checkpoint whose field has already
     * had `previous_name` cleared would have no old key to migrate from,
     * and rewriting against a null path would corrupt payloads.
     */
    public function loadOneClaimable(): ?RenameCheckpoint
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.last_processed_id,'
            . ' f.id AS field_id, f.name AS field_name, f.previous_name,'
            . ' f.model_id, m.tenant_id'
            . ' FROM backfill_checkpoints c'
            . ' JOIN stardust_fields f'
            . '   ON f.id = CAST(SUBSTRING(c.job_name, ' . (strlen(self::JOB_NAME_PREFIX) + 1) . ') AS UNSIGNED)'
            . ' JOIN stardust_models m ON m.id = f.model_id'
            . " WHERE c.status = 'running' AND c.job_name LIKE ?"
            . '   AND f.previous_name IS NOT NULL'
            . ' ORDER BY c.id'
            . ' LIMIT 1 FOR UPDATE SKIP LOCKED'
        );
        $stmt->execute([self::JOB_NAME_PREFIX . '%']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return new RenameCheckpoint(
            id: (int) $row['id'],
            fieldId: (int) $row['field_id'],
            tenantId: (int) $row['tenant_id'],
            modelId: (int) $row['model_id'],
            lastProcessedId: (int) $row['last_processed_id'],
            currentName: (string) $row['field_name'],
            previousName: (string) $row['previous_name'],
        );
    }

    public function existsRunningForField(int $fieldId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM backfill_checkpoints'
            . " WHERE job_name = ? AND status = 'running'"
            . ' LIMIT 1'
        );
        $stmt->execute([self::jobNameFor($fieldId)]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Inserts a fresh `running` checkpoint, resetting any terminal row
     * left behind by a previous rename of the same field.
     *
     * **Deliberately an upsert rather than a plain INSERT.** Nothing in
     * the engine ever deletes from `backfill_checkpoints`, and
     * `ux_backfill_job_name` is UNIQUE on `job_name`, so a plain INSERT
     * makes any *second* lifecycle for a given field throw a raw
     * `PDOException` once the first has completed — the caller's
     * `existsRunningForField()` pre-check returns false for a
     * `completed` row and offers no protection. The retype repository
     * has that defect today; this one does not inherit it.
     */
    public function insertOrReset(int $fieldId, string $now): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO backfill_checkpoints'
            . ' (job_name, last_processed_id, status, started_at, updated_at)'
            . " VALUES (?, 0, 'running', ?, ?)"
            . ' ON DUPLICATE KEY UPDATE'
            . "     last_processed_id = 0, status = 'running',"
            . '     started_at = VALUES(started_at), updated_at = VALUES(updated_at),'
            . '     completed_at = NULL, last_error = NULL'
        );
        $stmt->execute([self::jobNameFor($fieldId), $now, $now]);

        // lastInsertId() is 0 on the UPDATE branch of an upsert, so
        // re-read rather than trusting it.
        return $this->idForField($fieldId);
    }

    public function idForField(int $fieldId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM backfill_checkpoints WHERE job_name = ? LIMIT 1'
        );
        $stmt->execute([self::jobNameFor($fieldId)]);
        return (int) $stmt->fetchColumn();
    }

    /** Null when the field has never been renamed. */
    public function statusForField(int $fieldId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT status FROM backfill_checkpoints WHERE job_name = ? LIMIT 1'
        );
        $stmt->execute([self::jobNameFor($fieldId)]);
        $status = $stmt->fetchColumn();

        return $status === false ? null : (string) $status;
    }

    public function advance(int $checkpointId, int $newLastProcessedId, string $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE backfill_checkpoints'
            . ' SET last_processed_id = ?, updated_at = ?'
            . ' WHERE id = ?'
        );
        $stmt->execute([$newLastProcessedId, $now, $checkpointId]);
    }

    public function markCompleted(int $checkpointId, int $finalCursor, string $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE backfill_checkpoints'
            . " SET status = 'completed',"
            . '     last_processed_id = ?,'
            . '     updated_at = ?,'
            . '     completed_at = ?'
            . ' WHERE id = ?'
        );
        $stmt->execute([$finalCursor, $now, $now, $checkpointId]);
    }

    public function markFailed(int $checkpointId, string $reason, string $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE backfill_checkpoints'
            . " SET status = 'failed',"
            . '     updated_at = ?,'
            . '     completed_at = ?,'
            . '     last_error = ?'
            . ' WHERE id = ?'
        );
        $stmt->execute([$now, $now, substr($reason, 0, 512), $checkpointId]);
    }
}
