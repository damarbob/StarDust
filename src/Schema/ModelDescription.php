<?php

declare(strict_types=1);

namespace StarDust\Schema;

/**
 * A model and its fields, as reported by
 * {@see SchemaReader::describeModel()}.
 *
 * The read-side counterpart to {@see ModelDefinition}, which is what
 * {@see SchemaBuilder::createModel()} returns. They are deliberately
 * separate types: `ModelDefinition` reports what a *write* just
 * created (name → id, enough to reserve slots against), while this
 * carries the full current shape of the registry including each
 * field's declared type and whether it is queryable today.
 */
final class ModelDescription
{
    /**
     * @param list<FieldDescription> $fields ordered by field id, i.e.
     *                                       registration order
     */
    public function __construct(
        public readonly int $modelId,
        public readonly string $name,
        public readonly array $fields,
    ) {
    }

    /** Look one field up by name; `null` when the model has no such field. */
    public function field(string $fieldName): ?FieldDescription
    {
        foreach ($this->fields as $field) {
            if ($field->name === $fieldName) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The fields a filter can target right now.
     *
     * Shorthand for filtering on {@see FieldDescription::$isIndexed} —
     * see that class for why this is not the same as the fields whose
     * `is_filterable` flag is set.
     *
     * @return list<FieldDescription>
     */
    public function indexedFields(): array
    {
        return array_values(array_filter(
            $this->fields,
            static fn (FieldDescription $f): bool => $f->isIndexed,
        ));
    }
}
