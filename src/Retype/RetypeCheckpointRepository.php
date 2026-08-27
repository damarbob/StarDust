<?php

declare(strict_types=1);

namespace StarDust\Retype;

use PDO;
use StarDust\Support\LikePattern;

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
            . " WHERE c.status = 'running' AND c.job_name LIKE ? ESCAPE '\\\\'"
            . ' ORDER BY c.id'
            . ' LIMIT 1 FOR UPDATE SKIP LOCKED'
        );
        $stmt->execute([LikePattern::escapedPrefix(self::JOB_NAME_PREFIX)]);
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
     * Does **any** field of this model have a running retype checkpoint?
     *
     * ADR 0039. {@see \StarDust\Compaction\CompactionService::plan()} asks
     * this before planning, because a field mid-relocation is invisible to
     * {@see \StarDust\Compaction\CompactionRepository::loadModelSlots()} —
     * its old slot is `tombstoned` and its new one `backfilling`, and that
     * population deliberately counts neither. Planning anyway produces a
     * plan computed against a partial picture, whose `pages_before` and
     * `pages_after` then contradict the ADR 0031 spread sample that is
     * supposed to confirm the operation succeeded.
     *
     * **Deliberately not named `existsRunningForModel()`.**
     * {@see \StarDust\Delete\ModelDeleteCheckpointRepository::existsRunningForModel()}
     * already carries that name for a checkpoint keyed *directly* by model
     * id. This is the different question — "does any field of this model
     * have one" — and the names must not suggest the two are siblings.
     *
     * The `LIKE` is escaped for the reason {@see LikePattern} documents:
     * `job_name` is operator-supplied for Backfill Pump jobs and `_` is a
     * single-character wildcard, so the unescaped pattern also matches
     * `retypeXfieldY_rebuild`. Here a stray match costs a spurious refusal
     * rather than a spurious write, but the join to `stardust_fields` is
     * what makes the answer meaningful either way.
     */
    public function existsRunningForAnyFieldOfModel(int $modelId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1'
            . ' FROM backfill_checkpoints c'
            . ' JOIN stardust_fields f'
            . '   ON f.id = CAST(SUBSTRING(c.job_name, ' . (strlen(self::JOB_NAME_PREFIX) + 1) . ') AS UNSIGNED)'
            . " WHERE c.status = 'running' AND c.job_name LIKE ? ESCAPE '\\\\'"
            . '   AND f.model_id = ?'
            . ' LIMIT 1'
        );
        $stmt->execute([LikePattern::escapedPrefix(self::JOB_NAME_PREFIX), $modelId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * The field's checkpoint status, or `null` when it has none.
     *
     * ADR 0033 compaction polls this between relocations: it initiates
     * field *k*, waits for the Reconciler to drain it, then moves on. A
     * bool is not enough there — {@see self::existsRunningForField()}
     * reports `false` for a `completed` row *and* for a field that has
     * no checkpoint at all, and `CompactionService` treats those two
     * identically only because both mean "not still running".
     *
     * It used to also have to distinguish `failed`. It no longer can:
     * `markFailed()` was removed along with the compaction branch that
     * consumed it, because nothing in `src/` ever called it and a
     * relocation's realistic stall — lock contention — is now retried
     * and then deferred as `TickOutcome::LOCK_WAIT`, which leaves the
     * checkpoint `running` and drainable.
     */
    public function statusForField(int $fieldId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT status FROM backfill_checkpoints WHERE job_name = ? LIMIT 1'
        );
        $stmt->execute([self::jobNameFor($fieldId)]);
        $status = $stmt->fetchColumn();

        return $status === false ? null : (string) $status;
    }

    /**
     * Opens a fresh `running` checkpoint, resetting any terminal row a
     * previous lifecycle for the same field left behind.
     *
     * **An upsert rather than a plain INSERT.** `ux_backfill_job_name`
     * is UNIQUE on `job_name` and nothing removes a *retype* checkpoint
     * — ADR 0037's `src/Delete/` clears terminal sibling rows, but only
     * when the field itself is being deleted. A plain INSERT therefore
     * made the *second* filterable-target lifecycle for a field throw a
     * raw `PDOException` (errno 1062) once the first had completed, and
     * {@see self::existsRunningForField()} offers no protection because
     * it reports `false` for a terminal row. Since every shape in
     * {@see RetypeInitiator::runTuple()} with a filterable target lands
     * here, that made a field a one-way door: one retype, promotion or
     * relocation each, ever. This is the same fix
     * {@see \StarDust\Rename\RenameCheckpointRepository::insertOrReset()}
     * and both delete repositories already carry.
     *
     * **`source_declared_type` must be reset with the rest.** No sibling
     * repository has that column, so porting their statement verbatim
     * would leave the second lifecycle draining against the *first*
     * one's source type — the wrong ADR 0024 matrix cell, with no event
     * and no exception to show for it. Pinned by
     * `RetypeInitiatorTest::testResetCheckpointCarriesTheNewSourceDeclaredType`.
     *
     * Only *terminal* rows are relaxed. A genuinely concurrent
     * lifecycle is still refused by the caller's
     * {@see self::existsRunningForField()} pre-check, which now runs
     * under {@see RetypeInitiator}'s `FOR UPDATE OF f` lock on the field
     * row and so cannot interleave with a second initiator. That lock is
     * what keeps this upsert from silently resetting a live checkpoint.
     *
     * Returns nothing: the checkpoint id has never had a consumer here,
     * and the siblings' `int` return exists only to work around
     * `lastInsertId()` reporting 0 on the UPDATE branch of an upsert —
     * a workaround for a value none of their callers read either.
     */
    public function insertOrReset(int $fieldId, string $sourceDeclaredType, string $now): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO backfill_checkpoints'
            . ' (job_name, last_processed_id, status, started_at, updated_at, source_declared_type)'
            . " VALUES (?, 0, 'running', ?, ?, ?)"
            . ' ON DUPLICATE KEY UPDATE'
            . "     last_processed_id = 0, status = 'running',"
            . '     started_at = VALUES(started_at), updated_at = VALUES(updated_at),'
            . '     completed_at = NULL, last_error = NULL,'
            . '     source_declared_type = VALUES(source_declared_type)'
        );
        $stmt->execute([self::jobNameFor($fieldId), $now, $now, $sourceDeclaredType]);
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
}
