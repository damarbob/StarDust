<?php

declare(strict_types=1);

namespace StarDust\Delete;

use PDO;
use StarDust\Rename\RenameCheckpointRepository;
use StarDust\Retype\RetypeCheckpointRepository;
use StarDust\Support\LikePattern;

/**
 * Cursor state for ADR 0038 model deletions — the fourth
 * `backfill_checkpoints` job-name namespace.
 *
 * All four prefixes are thirteen characters, so every claim query in the
 * engine recovers its id with the same `SUBSTRING(job_name, 14)` offset.
 * `delete_model_` and `delete_field_` diverge at the eighth character, so
 * the two are disjoint under `LIKE` even before escaping.
 *
 * ## Two guards on the claim query, not one
 *
 * `job_name` is **operator-supplied** for Backfill Pump CLI jobs, and `_`
 * is a single-character SQL wildcard. For ADR 0037 a stray match meant a
 * spurious `JSON_REMOVE`; here it would mean an unrecoverable
 * `DELETE FROM entry_data`. So the pattern is escaped via
 * {@see LikePattern} **and** the query carries
 * `m.deleted_at IS NOT NULL`, which no operator-named job can satisfy.
 * Verified against MySQL 8.0.13: `deleteXmodelY_rebuild` matches the
 * unescaped pattern and not the escaped one.
 *
 * ## One checkpoint per model, never one per field
 *
 * Severance opens exactly one of these. Opening `delete_field_{id}` rows
 * per field instead would have N purges each scanning the same
 * `(tenant_id, model_id)` partition to rewrite the same rows — and no
 * field purge deletes a model, so the entries would be rewritten N times
 * and still be there afterwards.
 */
final class ModelDeleteCheckpointRepository
{
    public const JOB_NAME_PREFIX = 'delete_model_';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function jobNameFor(int $modelId): string
    {
        return self::JOB_NAME_PREFIX . $modelId;
    }

    /**
     * Claims one running model purge, or null when there is none.
     *
     * `FOR UPDATE SKIP LOCKED` locks the `stardust_models` row as well as
     * the checkpoint, which is deliberate: it serialises the final
     * `DELETE FROM stardust_models` against a second worker. Keep the
     * join inside this locking query rather than hoisting it out for
     * tidiness.
     */
    public function loadOneClaimable(): ?ModelDeleteCheckpoint
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.id, c.last_processed_id, m.id AS model_id, m.tenant_id'
            . ' FROM backfill_checkpoints c'
            . ' JOIN stardust_models m'
            . '   ON m.id = CAST(SUBSTRING(c.job_name, '
                . (strlen(self::JOB_NAME_PREFIX) + 1) . ') AS UNSIGNED)'
            . " WHERE c.status = 'running'"
            . "   AND c.job_name LIKE ? ESCAPE '\\\\'"
            . '   AND m.deleted_at IS NOT NULL'
            . ' ORDER BY c.id'
            . ' LIMIT 1 FOR UPDATE SKIP LOCKED'
        );
        $stmt->execute([LikePattern::escapedPrefix(self::JOB_NAME_PREFIX)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return new ModelDeleteCheckpoint(
            id: (int) $row['id'],
            modelId: (int) $row['model_id'],
            tenantId: (int) $row['tenant_id'],
            lastProcessedId: (int) $row['last_processed_id'],
        );
    }

    public function existsRunningForModel(int $modelId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM backfill_checkpoints WHERE job_name = ? AND status = 'running' LIMIT 1"
        );
        $stmt->execute([self::jobNameFor($modelId)]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Opens a fresh `running` checkpoint, resetting any terminal row a
     * previous attempt left behind.
     *
     * An upsert for the reason {@see DeleteCheckpointRepository::insertOrReset()}
     * gives: the successful path deletes this row along with the model,
     * so there is normally nothing to collide with, but a purge manually
     * marked `failed` leaves both the row and `stardust_models.deleted_at`
     * behind — and re-issuing the delete must resume rather than crash.
     */
    public function insertOrReset(int $modelId, string $now): int
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
        $stmt->execute([self::jobNameFor($modelId), $now, $now]);

        // lastInsertId() is 0 on the UPDATE branch of an upsert.
        return $this->idForModel($modelId);
    }

    public function idForModel(int $modelId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM backfill_checkpoints WHERE job_name = ? LIMIT 1'
        );
        $stmt->execute([self::jobNameFor($modelId)]);

        return (int) $stmt->fetchColumn();
    }

    /** Null when no deletion has ever been initiated for the model. */
    public function statusForModel(int $modelId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT status FROM backfill_checkpoints WHERE job_name = ? LIMIT 1'
        );
        $stmt->execute([self::jobNameFor($modelId)]);
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
     * transaction that deletes the model row.
     *
     * Deliberately not `markCompleted()`: every other lifecycle leaves an
     * audit row keyed to something that still exists, and here the model
     * is gone, so a surviving row would be exactly the orphan this
     * feature eliminates.
     */
    public function delete(int $checkpointId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM backfill_checkpoints WHERE id = ?');
        $stmt->execute([$checkpointId]);
    }

    /**
     * Clears every field-scoped lifecycle checkpoint for the given
     * fields — all three namespaces, N fields wide.
     *
     * Three deep rather than ADR 0037's two: `delete_field_{id}` is
     * included because a field purge manually marked `failed` leaves its
     * row behind, and the model cascade would orphan it permanently.
     *
     * The initiator refuses outright while any of them is `running`, so
     * in practice this only ever removes terminal rows. It is written to
     * cover all of them regardless, because the cascade at the end of the
     * purge does not care which status a row was in: both sibling
     * repositories recover the field id by INNER JOIN on a substring of
     * `job_name`, so any row whose field was cascaded away becomes
     * permanently unclaimable and permanently counted.
     *
     * Scoped by exact `job_name`, never a `LIKE`, so it cannot reach
     * another model's fields.
     *
     * @param list<int> $fieldIds
     */
    public function deleteLifecycleRowsForFields(array $fieldIds): void
    {
        if ($fieldIds === []) {
            return;
        }

        $names = [];
        foreach ($fieldIds as $fieldId) {
            $names[] = RenameCheckpointRepository::jobNameFor($fieldId);
            $names[] = RetypeCheckpointRepository::jobNameFor($fieldId);
            $names[] = DeleteCheckpointRepository::jobNameFor($fieldId);
        }

        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $stmt = $this->pdo->prepare(
            "DELETE FROM backfill_checkpoints WHERE job_name IN ({$placeholders})"
        );
        $stmt->execute($names);
    }
}
