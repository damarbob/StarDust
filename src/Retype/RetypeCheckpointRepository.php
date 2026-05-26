<?php

declare(strict_types=1);

namespace StarDust\Retype;

use PDO;

/**
 * Encapsulates all reads/writes against `backfill_checkpoints` whose
 * `job_name LIKE 'retype_field_%'`. The Backfill Pump CLI uses the same
 * table with operator-supplied `job_name`s; segregating the retype
 * namespace by prefix keeps the two consumers from racing.
 *
 * The `RetypeCheckpoint` rows are augmented at load time with the
 * field's `(tenant_id, model_id)` via JOIN to `stardust_fields` ⨝
 * `stardust_models`; the work source needs both to scope its
 * `entry_data` cursor scan.
 */
final class RetypeCheckpointRepository
{
    public const JOB_NAME_PREFIX = 'retype_field_';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function jobNameFor(int $fieldId): string
    {
        return self::JOB_NAME_PREFIX . $fieldId;
    }

    /**
     * Claims one `status='running'` retype checkpoint via `FOR UPDATE
     * SKIP LOCKED`. The lock is held for the surrounding transaction
     * (the work source's chunk transaction), so a second Reconciler
     * worker cannot claim the same row mid-chunk.
     *
     * Joins `stardust_fields` and `stardust_models` for the partition
     * tuple so the work source receives a fully-hydrated DTO.
     */
    public function loadOneClaimable(): ?RetypeCheckpoint
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.last_processed_id, c.source_declared_type,'
            . ' f.id AS field_id, f.name AS field_name,'
            . ' f.declared_type AS target_declared_type,'
            . ' f.is_filterable AS target_is_filterable,'
            . ' f.model_id, m.tenant_id'
            . ' FROM backfill_checkpoints c'
            . ' JOIN stardust_fields f'
            . '   ON f.id = CAST(SUBSTRING(c.job_name, ' . (strlen(self::JOB_NAME_PREFIX) + 1) . ') AS UNSIGNED)'
            . ' JOIN stardust_models m ON m.id = f.model_id'
            . " WHERE c.status = 'running' AND c.job_name LIKE ?"
            . ' ORDER BY c.id'
            . ' LIMIT 1 FOR UPDATE SKIP LOCKED'
        );
        $stmt->execute([self::JOB_NAME_PREFIX . '%']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return new RetypeCheckpoint(
            id: (int) $row['id'],
            fieldId: (int) $row['field_id'],
            tenantId: (int) $row['tenant_id'],
            modelId: (int) $row['model_id'],
            lastProcessedId: (int) $row['last_processed_id'],
            sourceDeclaredType: (string) ($row['source_declared_type'] ?? $row['target_declared_type']),
            targetDeclaredType: (string) $row['target_declared_type'],
            targetIsFilterable: (bool) $row['target_is_filterable'],
            fieldName: (string) $row['field_name'],
        );
    }

    public function existsRunningForField(int $fieldId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM backfill_checkpoints"
            . " WHERE job_name = ? AND status = 'running'"
            . ' LIMIT 1'
        );
        $stmt->execute([self::jobNameFor($fieldId)]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Inserts a new `running` checkpoint. The UNIQUE
     * `ux_backfill_job_name` index enforces one row per
     * `retype_field_{N}`; concurrent initiators will hit a
     * `PDOException` which the caller surfaces as
     * {@see \StarDust\Exception\RetypeInProgressException} after
     * the {@see self::existsRunningForField()} pre-check.
     */
    public function insert(int $fieldId, string $sourceDeclaredType, string $now): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO backfill_checkpoints'
            . ' (job_name, last_processed_id, status, started_at, updated_at, source_declared_type)'
            . " VALUES (?, 0, 'running', ?, ?, ?)"
        );
        $stmt->execute([self::jobNameFor($fieldId), $now, $now, $sourceDeclaredType]);
        return (int) $this->pdo->lastInsertId();
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
