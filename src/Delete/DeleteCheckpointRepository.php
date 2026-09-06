<?php

declare(strict_types=1);

namespace StarDust\Delete;

use PDO;
use StarDust\Support\LikePattern;
use StarDust\Rename\RenameCheckpointRepository;
use StarDust\Retype\RetypeCheckpointRepository;

/**
 * All SQL against `backfill_checkpoints` rows whose `job_name` matches
 * `delete_field_%`.
 *
 * The third disjoint prefix in that table, alongside `retype_field_`
 * and `rename_field_`. No repository's `LIKE` scan can claim another's
 * rows, so three lifecycles coexist without a discriminator column.
 *
 * **This is the one repository that deletes from the table.** Every
 * other lifecycle ends in `markCompleted()`, leaving an audit row keyed
 * to a field that still exists. A delete ends with the field row gone,
 * so a surviving `delete_field_{id}` row would be exactly the orphan
 * this feature exists to remove: permanently unclaimable, pointing at
 * an auto-increment id that will never be issued again, and counted by
 * any dashboard that groups `backfill_checkpoints` by status.
 */
final class DeleteCheckpointRepository
{
    public const JOB_NAME_PREFIX = 'delete_field_';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function jobNameFor(int $fieldId): string
    {
        return self::JOB_NAME_PREFIX . $fieldId;
    }

    /**
     * Claims one `status='running'` delete checkpoint via `FOR UPDATE
     * SKIP LOCKED`, held for the surrounding chunk transaction so a
     * second Reconciler worker cannot claim the same row mid-chunk.
     *
     * `f.deleted_at IS NOT NULL` is the integrity guard, mirroring the
     * rename repository's `previous_name IS NOT NULL`: it is what makes
     * the INNER JOIN meaningful rather than incidental. A `running`
     * delete checkpoint whose field is no longer marked deleted has no
     * business erasing that field's values from every payload in the
     * model, and the guard is what stops it.
     *
     * The JOIN is INNER, so once the final chunk hard-deletes the field
     * row the checkpoint becomes invisible here — which is harmless,
     * because the same transaction deletes the checkpoint too.
     */
    public function loadOneClaimable(): ?DeleteCheckpoint
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.last_processed_id, c.correlation_id,'
            . ' f.id AS field_id, f.name AS field_name, f.model_id, m.tenant_id'
            . ' FROM backfill_checkpoints c'
            . ' JOIN stardust_fields f'
            . '   ON f.id = CAST(SUBSTRING(c.job_name, '
                . (strlen(self::JOB_NAME_PREFIX) + 1) . ') AS UNSIGNED)'
            . ' JOIN stardust_models m ON m.id = f.model_id'
            . " WHERE c.status = 'running' AND c.job_name LIKE ? ESCAPE '\\\\'"
            . '   AND f.deleted_at IS NOT NULL'
            . ' ORDER BY c.id'
            . ' LIMIT 1 FOR UPDATE SKIP LOCKED'
        );
        $stmt->execute([LikePattern::escapedPrefix(self::JOB_NAME_PREFIX)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return new DeleteCheckpoint(
            id: (int) $row['id'],
            fieldId: (int) $row['field_id'],
            tenantId: (int) $row['tenant_id'],
            modelId: (int) $row['model_id'],
            lastProcessedId: (int) $row['last_processed_id'],
            fieldName: (string) $row['field_name'],
            correlationId: $row['correlation_id'] === null ? null : (string) $row['correlation_id'],
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
     * a previous attempt left behind.
     *
     * An upsert rather than a plain INSERT, for the reason
     * {@see RenameCheckpointRepository::insertOrReset()} documents:
     * `ux_backfill_job_name` is UNIQUE and `existsRunningForField()`
     * returns false for a terminal row, so a plain INSERT throws a raw
     * `PDOException` on the second lifecycle for a field.
     *
     * That is rarer here than elsewhere — the successful path deletes
     * this row and the field it belongs to, so there is normally
     * nothing to collide with. It still matters for the failure path: a
     * purge whose checkpoint was manually marked `failed` leaves both
     * the row and the field's `deleted_at` behind, and re-issuing the
     * delete must resume rather than crash.
     */
    public function insertOrReset(int $fieldId, string $now, ?string $correlationId = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO backfill_checkpoints'
            . ' (job_name, last_processed_id, status, started_at, updated_at, correlation_id)'
            . " VALUES (?, 0, 'running', ?, ?, ?)"
            . ' ON DUPLICATE KEY UPDATE'
            . "     last_processed_id = 0, status = 'running',"
            . '     started_at = VALUES(started_at), updated_at = VALUES(updated_at),'
            . '     completed_at = NULL, last_error = NULL,'
            . '     correlation_id = VALUES(correlation_id)'
        );
        $stmt->execute([self::jobNameFor($fieldId), $now, $now, $correlationId]);

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

    /** Null when no delete has ever been initiated for the field. */
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

    /**
     * Removes the purge checkpoint on the final chunk, in the same
     * transaction that hard-deletes the field row.
     *
     * Deliberately not `markCompleted()` — see the class docblock.
     */
    public function delete(int $checkpointId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM backfill_checkpoints WHERE id = ?');
        $stmt->execute([$checkpointId]);
    }

    /**
     * Clears any *terminal* rename and retype checkpoints for a field
     * whose deletion is being initiated.
     *
     * The initiator refuses outright while either is `running`, so this
     * only ever removes `completed` / `failed` / `paused` rows. Those
     * are harmless while their field exists and become orphans the
     * moment it does not, since both sibling repositories recover the
     * field id by INNER JOIN on a substring of `job_name`.
     *
     * Scoped by exact `job_name` rather than a `LIKE`, so it can never
     * reach another field's rows.
     */
    public function deleteTerminalLifecycleRowsFor(int $fieldId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM backfill_checkpoints WHERE job_name IN (?, ?)'
        );
        $stmt->execute([
            RenameCheckpointRepository::jobNameFor($fieldId),
            RetypeCheckpointRepository::jobNameFor($fieldId),
        ]);
    }
}
