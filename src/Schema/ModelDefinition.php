<?php

declare(strict_types=1);

namespace StarDust\Schema;

use InvalidArgumentException;

/**
 * Result of {@see SchemaBuilder::createModel()} — the resolved
 * `stardust_models.id` plus a `field name → stardust_fields.id` map.
 *
 * Callers use {@see self::fieldId()} when they need a specific field's
 * id (e.g. to reserve a slot for it), and {@see self::$modelId} as the
 * `model_id` for writes and queries.
 */
final class ModelDefinition
{
    /**
     * @param array<string,int> $fieldIds Field name → `stardust_fields.id`.
     */
    public function __construct(
        public readonly int $modelId,
        public readonly array $fieldIds,
    ) {
    }

    public function fieldId(string $fieldName): int
    {
        return $this->fieldIds[$fieldName]
            ?? throw new InvalidArgumentException(
                "ModelDefinition: no field named '{$fieldName}' on model {$this->modelId}."
            );
    }
}
