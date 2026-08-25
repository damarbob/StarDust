<?php

declare(strict_types=1);

namespace StarDust\Schema;

use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PDOException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Exception\FieldDeletionInProgressException;
use StarDust\Exception\ModelDeletionInProgressException;
use StarDust\Support\ModelDeletionProbe;
use Throwable;

/**
 * Convenience helper for registering models and fields without
 * hand-writing `stardust_models` / `stardust_fields` SQL.
 *
 * This is a deliberate stopgap, not the first-class model/field
 * definition API. It wraps the registry INSERTs the engine otherwise
 * leaves to the caller, so the first-run experience does not require
 * raw SQL. It does NOT provision pages or reserve slots — making a
 * filterable field genuinely queryable still goes through
 * {@see \StarDust\Page\PageProvisioner} and
 * {@see \StarDust\Slot\SlotReserver} (or, in a running deployment, the
 * Watcher daemon).
 *
 * Every method is get-or-create and therefore idempotent: a model or
 * field whose name already exists is returned unchanged rather than
 * re-inserted, so a setup/seed script is safe to re-run. (Existing
 * rows' `declared_type` / `is_filterable` are NOT reconciled against
 * the arguments — the real definition API will own migrations.)
 *
 * Follows the project's transaction+log discipline: input is validated
 * first, all writes for one `createModel()` call commit in a single
 * registry transaction that bumps `stardust_schema_version` exactly
 * once when any row was actually inserted, and the summary log line is
 * emitted only after the commit succeeds.
 */
final class SchemaBuilder
{
    /** The `stardust_fields.declared_type` ENUM universe. */
    private const DECLARED_TYPES = ['string', 'int', 'numeric', 'datetime'];

    /** `VARCHAR(128)` ceiling on both `name` columns (schema reference §4). */
    private const NAME_MAX_LENGTH = 128;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Register a model and (optionally) its fields in one transaction.
     *
     * Returns a {@see ModelDefinition} carrying the model id and a
     * `field name → id` map. Re-running with the same names returns the
     * existing ids unchanged.
     *
     * @param list<FieldDefinition> $fields
     */
    public function createModel(int $tenantId, string $name, array $fields = []): ModelDefinition
    {
        $this->assertValidTenant($tenantId);
        $this->assertValidName($name, 'model');
        foreach ($fields as $field) {
            $this->assertValidName($field->name, 'field');
            $this->assertValidDeclaredType($field->declaredType);
        }

        $now = $this->utcNow();

        $this->pdo->beginTransaction();
        try {
            $inserted = false;

            $modelId = $this->findModelId($tenantId, $name);
            if ($modelId === null) {
                $modelId  = $this->insertModel($tenantId, $name, $now);
                $inserted = true;
            }

            $fieldIds = [];
            foreach ($fields as $field) {
                $existing = $this->findFieldId($modelId, $field->name);
                if ($existing === null) {
                    $fieldIds[$field->name] = $this->insertField($modelId, $field, $now);
                    $inserted = true;
                } else {
                    $fieldIds[$field->name] = $existing;
                }
            }

            if ($inserted) {
                $this->bumpSchemaVersion($now);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->logger->info('schema model defined', [
            'tenant_id'   => $tenantId,
            'model_id'    => $modelId,
            'model_name'  => $name,
            'field_count' => count($fieldIds),
        ]);

        return new ModelDefinition($modelId, $fieldIds);
    }

    /**
     * Register a single model (no fields). Returns its id; existing
     * models with the same `(tenant_id, name)` are returned unchanged.
     */
    public function defineModel(int $tenantId, string $name): int
    {
        return $this->createModel($tenantId, $name)->modelId;
    }

    /**
     * Register a single field under an existing model. Returns its id;
     * an existing field with the same `(model_id, name)` is returned
     * unchanged. Bumps `stardust_schema_version` when it inserts.
     */
    public function defineField(
        int $modelId,
        string $name,
        string $declaredType,
        bool $isFilterable = false,
    ): int {
        $this->assertValidName($name, 'field');
        $this->assertValidDeclaredType($declaredType);

        $now = $this->utcNow();

        $this->pdo->beginTransaction();
        try {
            $fieldId = $this->findFieldId($modelId, $name);
            if ($fieldId === null) {
                $fieldId = $this->insertField(
                    $modelId,
                    new FieldDefinition($name, $declaredType, $isFilterable),
                    $now,
                );
                $this->bumpSchemaVersion($now);
            }
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $fieldId;
    }

    /**
     * `deleted_at IS NULL` is the same data-loss guard `findFieldId()`
     * carries, one level up.
     *
     * This is the get-or-create half of `defineModel()`. Without the
     * predicate it would hand back the id of a model whose ADR 0038
     * deletion is in flight — so a seed script re-running during a purge
     * would silently adopt a model whose entries are being destroyed and
     * whose registry row is about to be dropped, taking every field with
     * it. Missing here routes the caller to `insertModel()`, where the
     * unique index becomes a typed error.
     */
    private function findModelId(int $tenantId, string $name): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM stardust_models'
            . ' WHERE tenant_id = ? AND name = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$tenantId, $name]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * @throws ModelDeletionInProgressException when the name is still held by a model
     *                                          whose ADR 0038 purge has not finished
     */
    private function insertModel(int $tenantId, string $name, string $now): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO stardust_models (tenant_id, name, created_at) VALUES (?, ?, ?)'
        );

        try {
            $stmt->execute([$tenantId, $name, $now]);
        } catch (PDOException $e) {
            // Exactly the `insertField()` shape below. `ux_models_tenant_name`
            // is unconditional, so a deleting model still holds its name;
            // `findModelId()` deliberately did not see it, so the only way
            // to reach errno 1062 on this tenant+name is a deletion in
            // flight (or a concurrent insert, which wants to fail too).
            if ($this->isDuplicateEntry($e) && $this->nameHeldByDeletedModel($tenantId, $name)) {
                throw new ModelDeletionInProgressException(sprintf(
                    "Model '%s' in tenant %d is being deleted; its name cannot be reused"
                    . ' until the entry purge completes. Run a reconciler to finish it.',
                    $name,
                    $tenantId,
                ));
            }
            throw $e;
        }

        return (int) $this->pdo->lastInsertId();
    }

    private function nameHeldByDeletedModel(int $tenantId, string $name): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM stardust_models'
            . ' WHERE tenant_id = ? AND name = ? AND deleted_at IS NOT NULL'
            . ' LIMIT 1'
        );
        $stmt->execute([$tenantId, $name]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * `deleted_at IS NULL` is load-bearing, not hygiene.
     *
     * This method is the get-or-create half of `defineField()`. Without
     * the predicate it would hand back the id of a field whose ADR 0037
     * deletion is in flight — so a caller re-registering the name would
     * silently adopt a field whose values are actively being erased from
     * every payload, and whose registry row is about to be dropped
     * outright by the purge's final chunk. Missing here is what routes
     * the caller to `insertField()`, where the unique index turns it
     * into a typed error.
     */
    private function findFieldId(int $modelId, string $name): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM stardust_fields'
            . ' WHERE model_id = ? AND name = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$modelId, $name]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * @throws FieldDeletionInProgressException when the name is still held by a field
     *                                          whose ADR 0037 purge has not finished
     * @throws ModelDeletionInProgressException when the model itself is being deleted
     */
    private function insertField(int $modelId, FieldDefinition $field, string $now): int
    {
        // ADR 0038, and this one cannot ride the unique index the way the
        // name guards do — a *new* field name on a deleting model collides
        // with nothing, so errno 1062 never fires and the INSERT simply
        // succeeds.
        //
        // Letting it succeed is the failure this guard exists to prevent,
        // and it is not merely untidy. A field created after severance is
        // not marked, so `PendingDemandReader` — which gates on
        // `is_filterable = 1 AND f.deleted_at IS NULL` with no model
        // predicate — reads it as demand and has the Watcher provision a
        // page for a model being destroyed; then `UnmappedFieldReserver`
        // reserves it a slot on the ADR 0007 exhaustion path, re-taking
        // `fk_slot_assignments_field`. The purge's final chunk then fails
        // errno 1451, permanently, *after* earlier chunks have already
        // committed their deletes. Measured on MySQL 8.0.13.
        //
        // The guard sits here rather than in `defineField()` /
        // `createModel()` so it covers every path that can create a field,
        // present and future — the `SlotReserver::reserveCore()` precedent.
        if ($this->modelIsDeleting($modelId)) {
            throw new ModelDeletionInProgressException(sprintf(
                "Model %d is being deleted; field '%s' cannot be added to it."
                . ' Run a reconciler to finish the purge.',
                $modelId,
                $field->name,
            ));
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO stardust_fields'
            . ' (model_id, name, declared_type, is_filterable, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?)'
        );

        try {
            $stmt->execute([
                $modelId,
                $field->name,
                $field->declaredType,
                $field->isFilterable ? 1 : 0,
                $now,
                $now,
            ]);
        } catch (PDOException $e) {
            // `ux_fields_model_name` is unconditional, so a field being
            // deleted still holds its name until the purge lands.
            // `findFieldId()` above deliberately did not see it, so the
            // only way to reach errno 1062 on this model+name is a
            // deletion in flight (or a concurrent insert of the same
            // name, which wants to fail here too).
            if ($this->isDuplicateEntry($e) && $this->nameHeldByDeletedField($modelId, $field->name)) {
                throw new FieldDeletionInProgressException(sprintf(
                    "Field '%s' on model %d is being deleted; its name cannot be reused"
                    . ' until the payload purge completes. Run a reconciler to finish it.',
                    $field->name,
                    $modelId,
                ));
            }
            throw $e;
        }

        return (int) $this->pdo->lastInsertId();
    }

    private function modelIsDeleting(int $modelId): bool
    {
        return ModelDeletionProbe::isDeleting($this->pdo, $modelId);
    }

    private function nameHeldByDeletedField(int $modelId, string $name): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM stardust_fields'
            . ' WHERE model_id = ? AND name = ? AND deleted_at IS NOT NULL'
            . ' LIMIT 1'
        );
        $stmt->execute([$modelId, $name]);

        return $stmt->fetchColumn() !== false;
    }

    private function isDuplicateEntry(PDOException $e): bool
    {
        $info = $e->errorInfo;
        if (is_array($info) && isset($info[1]) && (int) $info[1] === 1062) {
            return true;
        }
        return str_contains($e->getMessage(), '1062');
    }

    private function bumpSchemaVersion(string $now): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE stardust_schema_version SET version = version + 1, updated_at = ? WHERE id = 1'
        );
        $stmt->execute([$now]);
    }

    private function utcNow(): string
    {
        return $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');
    }

    private function assertValidTenant(int $tenantId): void
    {
        if ($tenantId <= 0) {
            throw new InvalidArgumentException(
                "SchemaBuilder: tenant_id must be a positive BIGINT (>= 1); got {$tenantId}."
            );
        }
    }

    private function assertValidName(string $name, string $kind): void
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            throw new InvalidArgumentException("SchemaBuilder: {$kind} name must not be empty.");
        }
        if (strlen($name) > self::NAME_MAX_LENGTH) {
            throw new InvalidArgumentException(
                "SchemaBuilder: {$kind} name '{$name}' exceeds " . self::NAME_MAX_LENGTH . ' characters.'
            );
        }
    }

    private function assertValidDeclaredType(string $declaredType): void
    {
        if (!in_array($declaredType, self::DECLARED_TYPES, true)) {
            throw new InvalidArgumentException(
                "SchemaBuilder: declared_type '{$declaredType}' is not one of "
                . implode(', ', self::DECLARED_TYPES) . '.'
            );
        }
    }
}
