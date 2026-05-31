<?php

declare(strict_types=1);

namespace StarDust\Schema;

/**
 * Input DTO describing one field to register under a model via
 * {@see SchemaBuilder::createModel()}.
 *
 * `$declaredType` is one of `string | int | numeric | datetime`
 * (the `stardust_fields.declared_type` ENUM). `$isFilterable` only
 * records intent in the registry — a field becomes genuinely
 * queryable once its value lands in an indexed slot column, which
 * happens after a page is provisioned and a slot is reserved.
 */
final class FieldDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $declaredType,
        public readonly bool $isFilterable = false,
    ) {
    }
}
