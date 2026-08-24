<?php

declare(strict_types=1);

namespace StarDust\Rename;

use DateTimeZone;
use InvalidArgumentException;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Exception\FieldNameConflictException;
use StarDust\Exception\FieldNotFoundException;
use StarDust\Exception\RenameInProgressException;
use StarDust\Exception\RetypeInProgressException;
use StarDust\Retype\RetypeCheckpointRepository;
use Throwable;

/**
 * The ADR 0036 field-rename registry transaction.
 *
 * Flips `stardust_fields.name` immediately, stashes the old value in
 * `previous_name`, bumps the schema version, and opens a
 * `rename_field_{id}` checkpoint for the Reconciler to drain. The
 * payload rewrite is asynchronous; `previous_name` is what keeps reads
 * and writes correct until it lands.
 *
 * Unlike {@see \StarDust\Retype\RetypeInitiator} there is no
 * "no backfill required" branch — every rename needs the payload pass,
 * and a model with no entries simply completes on the work source's
 * first tick. Keeping the state machine uniform is worth more than
 * short-circuiting the empty case.
 */
final class RenameInitiator
{
    private const MAX_NAME_LENGTH = 128;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly RenameCheckpointRepository $renameCheckpoints,
        private readonly RetypeCheckpointRepository $retypeCheckpoints,
    ) {
    }

    public function initiate(int $tenantId, int $fieldId, string $newName): void
    {
        $newName = trim($newName);
        if ($newName === '') {
            throw new InvalidArgumentException('Field name must be a non-empty string.');
        }
        if (mb_strlen($newName) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(
                'Field name exceeds ' . self::MAX_NAME_LENGTH . ' characters.'
            );
        }

        $field = $this->loadField($tenantId, $fieldId);

        // Idempotent no-op, matching SchemaBuilder's get-or-create
        // posture. Deliberately before the in-flight guards: re-issuing
        // the same rename mid-drain should not raise.
        if ($newName === $field['name']) {
            return;
        }

        // Both guards, in this order in both initiators, so two
        // concurrent initiators cannot each see the other's row as
        // absent. `ux_backfill_job_name` is the real backstop; the
        // ordering just makes the common case deterministic.
        if ($this->renameCheckpoints->existsRunningForField($fieldId)) {
            throw new RenameInProgressException(
                "Field {$fieldId} already has a rename in progress."
            );
        }
        if ($this->retypeCheckpoints->existsRunningForField($fieldId)) {
            throw new RetypeInProgressException(
                "Field {$fieldId} has a retype in progress; rename cannot start until it completes."
            );
        }

        $now = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            // Inside the transaction, not before it: the check
            // takes a FOR UPDATE lock, and in autocommit that lock
            // would be released the instant the SELECT finished,
            // leaving nothing to stop a concurrent initiator
            // claiming the same name between check and UPDATE.
            $this->assertNameAvailable($field['model_id'], $fieldId, $newName);

            $update = $this->pdo->prepare(
                'UPDATE stardust_fields'
                . ' SET name = ?, previous_name = ?, updated_at = ?'
                . ' WHERE id = ?'
            );
            $update->execute([$newName, $field['name'], $now, $fieldId]);

            $bump = $this->pdo->prepare(
                'UPDATE stardust_schema_version'
                . ' SET version = version + 1, updated_at = ?'
                . ' WHERE id = 1'
            );
            $bump->execute([$now]);

            $this->renameCheckpoints->insertOrReset($fieldId, $now);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->logger->info('field rename started', [
            'event'     => 'rename_started',
            'source'    => 'registry',
            'tenant_id' => $tenantId,
            'model_id'  => $field['model_id'],
            'field_id'  => $fieldId,
            'old_name'  => $field['name'],
            'new_name'  => $newName,
        ]);
    }

    /**
     * The name must not collide with any *other* field's current name
     * or its in-flight `previous_name`.
     *
     * `ux_fields_model_name` covers only the first half. Renaming
     * `a → b` frees `a` as far as that index is concerned, so a
     * subsequent `y → a` would be accepted — and then field `b`'s
     * read-path fallback (new key, else old key) would resolve to field
     * `y`'s value on every row the first backfill has not yet reached.
     *
     * `FOR UPDATE` because the check and the write must not interleave
     * with a concurrent initiator — which only holds because the caller
     * runs this INSIDE its transaction; in autocommit the lock would be
     * dropped at the end of the SELECT; the unique index remains the
     * backstop for the current-name half.
     */
    private function assertNameAvailable(int $modelId, int $fieldId, string $newName): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, previous_name FROM stardust_fields'
            . ' WHERE model_id = ? AND id <> ? AND (name = ? OR previous_name = ?)'
            . ' LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$modelId, $fieldId, $newName, $newName]);
        $clash = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($clash === false) {
            return;
        }

        $reason = ((string) $clash['name'] === $newName)
            ? 'is already the name of field ' . (int) $clash['id']
            : 'is the pre-rename name of field ' . (int) $clash['id']
                . ', whose rename backfill has not finished';

        throw new FieldNameConflictException("Field name '{$newName}' {$reason}.");
    }

    /**
     * @return array{name: string, model_id: int}
     */
    private function loadField(int $tenantId, int $fieldId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT f.name, f.model_id, m.tenant_id'
            . ' FROM stardust_fields f'
            . ' JOIN stardust_models m ON m.id = f.model_id'
            . ' WHERE f.id = ?'
        );
        $stmt->execute([$fieldId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new FieldNotFoundException("Field {$fieldId} does not exist.");
        }
        if ((int) $row['tenant_id'] !== $tenantId) {
            throw new FieldNotFoundException(
                "Field {$fieldId} does not belong to tenant {$tenantId}."
            );
        }

        return [
            'name'     => (string) $row['name'],
            'model_id' => (int) $row['model_id'],
        ];
    }
}
