<?php

declare(strict_types=1);

namespace StarDust\Schema;

/**
 * One model, as reported by {@see SchemaReader::listModels()}.
 *
 * Deliberately just the identity pair. Listing a tenant's models is a
 * navigation query — the fields come from
 * {@see SchemaReader::describeModel()} once the caller knows which
 * model it wants, so the list stays one row per model rather than
 * fanning out over every field of every model.
 */
final class ModelSummary
{
    public function __construct(
        public readonly int $modelId,
        public readonly string $name,
    ) {
    }
}
