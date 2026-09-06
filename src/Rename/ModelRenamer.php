<?php

declare(strict_types=1);

namespace StarDust\Rename;

use InvalidArgumentException;
use PDO;
use Psr\Log\LoggerInterface;
use StarDust\Exception\ModelNameConflictException;
use StarDust\Exception\ModelNotFoundException;
use StarDust\Support\UuidV4;
use Throwable;

/**
 * Renames a model. One UPDATE, synchronous, complete on return.
 *
 * ## Why this needs none of {@see RenameInitiator}'s machinery
 *
 * A field rename is a data migration because `entry_data.fields` is
 * keyed by field name, so it needs a checkpoint, a Reconciler work
 * source, a `previous_name` bridge and a read/write fallback for the
 * whole drain.
 *
 * **A model's name is load-bearing nowhere.** Identity is
 * `stardust_models.id`: `entry_data` carries `model_id`,
 * `SchemaVersionCache` keys snapshots by `modelId` and `SlotResolver`
 * builds them from `stardust_fields` alone, and every other join to
 * `stardust_models` in the engine selects only `id` / `tenant_id`. The
 * QueryFilter wire format accepts a `{"model": …}` field reference, but
 * `FieldRefResolver` resolves leaves by field name against the snapshot
 * using the *request's* `modelId` and never reads it. So there is no
 * payload to rewrite, no window to bridge, and nothing to keep
 * consistent while it happens.
 *
 * ## No schema-version bump, on purpose
 *
 * `SchemaBuilder::createModel()` bumps `stardust_schema_version` when it
 * inserts a model row, and this deliberately does not. Nothing a cached
 * snapshot holds changes when a model is renamed, and the version row is
 * a singleton — bumping it would invalidate every model's snapshot in
 * every process for no correctness benefit. It also matches
 * schema_reference §5.1, which scopes the version to *field metadata*
 * changes; a model's label is not field metadata.
 *
 * `SchemaReader::listModels()` / `describeModel()` are uncached registry
 * reads, so they surface the new name immediately with no invalidation
 * needed.
 *
 * ## The one caller that misbehaves afterwards
 *
 * `SchemaBuilder::findModelId()` looks a model up by `(tenant_id, name)`
 * — the only look-up-by-name in the engine. After a rename,
 * `createModel()` / `defineModel()` called with the **old** name finds
 * nothing and silently creates a *second* model. That is inherent to
 * get-or-create rather than a defect here, and it is why a seed script
 * must be updated in step with a rename.
 */
final class ModelRenamer
{
    private const MAX_NAME_LENGTH = 128;

    /**
     * No {@see \Psr\Clock\ClockInterface} here, unlike every other
     * registry collaborator: `stardust_models` has no `updated_at`
     * column, so there is no timestamp to stamp, and the structured
     * logger already emits its own `ts`. Taking a clock this class never
     * reads would be dead weight.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function rename(int $tenantId, int $modelId, string $newName): void
    {
        $newName = trim($newName);
        if ($newName === '') {
            throw new InvalidArgumentException('Model name must be a non-empty string.');
        }
        // mb_strlen against the trimmed value, matching RenameInitiator.
        // SchemaBuilder::assertValidName() measures strlen() on the
        // untrimmed input, which both over-counts multibyte names and
        // under-counts trailing whitespace; this is the correct check
        // for a VARCHAR(128) column.
        if (mb_strlen($newName) > self::MAX_NAME_LENGTH) {
            throw new InvalidArgumentException(
                'Model name exceeds ' . self::MAX_NAME_LENGTH . ' characters.'
            );
        }

        $currentName = $this->loadName($tenantId, $modelId);

        // Idempotent no-op, before any mutation or event. Matches
        // RenameInitiator and SchemaBuilder's get-or-create posture.
        if ($newName === $currentName) {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            // Inside the transaction, not before it: the check
            // takes a FOR UPDATE lock, and in autocommit that lock
            // would be released the instant the SELECT finished,
            // leaving nothing to stop a concurrent renamer landing
            // the same name between the check and the UPDATE.
            $this->assertNameAvailable($tenantId, $modelId, $newName);

            $stmt = $this->pdo->prepare(
                'UPDATE stardust_models SET name = ? WHERE id = ? AND tenant_id = ?'
            );
            $stmt->execute([$newName, $modelId, $tenantId]);

            // "Did my write actually land?" — the same guard
            // reserveCore() carries, and for the same reason: the
            // engine takes an INJECTED PDO (ADR 0026), so on
            // ERRMODE_SILENT execute() merely returns false instead
            // of raising. Without this we would commit and emit
            // model_renamed for a rename that never happened.
            //
            // rowCount() is exact here: the same-name case already
            // returned above, so a matched row is always a changed
            // row. Zero means the row vanished between loadName()
            // and now, or the write silently failed.
            if ($stmt->rowCount() === 0) {
                throw new ModelNotFoundException(
                    "Model {$modelId} could not be renamed for tenant {$tenantId}: "
                    . 'the update matched no row.'
                );
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // Minted here rather than threaded in: a model rename is one
        // committed UPDATE with no asynchronous half and no sub-events,
        // so the operation and the event are the same thing. It is
        // explicit anyway, because a synthesised id from the logger is
        // indistinguishable from a threaded one and that is exactly how
        // nine sites drifted unnoticed.
        $this->logger->info('model renamed', [
            'event'          => 'model_renamed',
            'source'         => 'registry',
            'correlation_id' => UuidV4::generate(),
            'tenant_id'      => $tenantId,
            'model_id'       => $modelId,
            'old_name'       => $currentName,
            'new_name'       => $newName,
        ]);
    }

    /**
     * Tenant-scoped in the WHERE clause rather than fetched and compared,
     * so a model belonging to another tenant is indistinguishable from
     * one that does not exist.
     */
    private function loadName(int $tenantId, int $modelId): string
    {
        $stmt = $this->pdo->prepare(
            // ADR 0038: a deleting model must not be renamable. Its
            // name is held until the purge lands (`ux_models_tenant_name`
            // is unconditional), and moving it would defeat that.
            'SELECT name FROM stardust_models'
            . ' WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$modelId, $tenantId]);
        $name = $stmt->fetchColumn();

        if ($name === false) {
            throw new ModelNotFoundException(
                "Model {$modelId} does not exist for tenant {$tenantId}."
            );
        }

        return (string) $name;
    }

    /**
     * `FOR UPDATE` so the check and the UPDATE cannot interleave with a
     * concurrent renamer — which only holds because the caller runs this
     * INSIDE its transaction; in autocommit the lock would be dropped at
     * the end of the SELECT. `ux_models_tenant_name` remains the backstop;
     * this exists to turn errno 1062 into a typed exception.
     *
     * **Deliberately carries no `deleted_at IS NULL` predicate, unlike
     * every other model lookup in the engine.** `ux_models_tenant_name`
     * is unconditional, so a model whose ADR 0038 deletion is in flight
     * still holds its name until the purge lands. Excluding it here would
     * report that name as free, let this rename proceed, and then blow up
     * on errno 1062 outside the typed path — turning a clean
     * `ModelNameConflictException` into a raw `PDOException`. The clash
     * is real; it is just temporary.
     */
    private function assertNameAvailable(int $tenantId, int $modelId, string $newName): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM stardust_models'
            . ' WHERE tenant_id = ? AND id <> ? AND name = ?'
            . ' LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$tenantId, $modelId, $newName]);
        $clash = $stmt->fetchColumn();

        if ($clash !== false) {
            throw new ModelNameConflictException(
                "Model name '{$newName}' is already used by model " . (int) $clash
                . " in tenant {$tenantId}."
            );
        }
    }
}
