<?php

declare(strict_types=1);

namespace StarDust\Schema;

use DateTimeZone;
use InvalidArgumentException;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
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

    private function findModelId(int $tenantId, string $name): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM stardust_models WHERE tenant_id = ? AND name = ?'
        );
        $stmt->execute([$tenantId, $name]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function insertModel(int $tenantId, string $name, string $now): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO stardust_models (tenant_id, name, created_at) VALUES (?, ?, ?)'
        );
        $stmt->execute([$tenantId, $name, $now]);

        return (int) $this->pdo->lastInsertId();
    }

    private function findFieldId(int $modelId, string $name): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM stardust_fields WHERE model_id = ? AND name = ?'
        );
        $stmt->execute([$modelId, $name]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    private function insertField(int $modelId, FieldDefinition $field, string $now): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO stardust_fields'
            . ' (model_id, name, declared_type, is_filterable, created_at, updated_at)'
            . ' VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $modelId,
            $field->name,
            $field->declaredType,
            $field->isFilterable ? 1 : 0,
            $now,
            $now,
        ]);

        return (int) $this->pdo->lastInsertId();
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
