<?php

declare(strict_types=1);

namespace StarDust\Filter\Ast;

use StarDust\Read\FieldDescriptor;

/**
 * A reference to a model field within a {@see LeafNode}.
 *
 * The wire form is the unresolved `{model, name}` pair carried by every
 * incoming JSON payload. The pre-flight `FieldRefResolver` walks the AST
 * and produces a *new* `FieldRef` instance with the registry-resolved
 * `$modelId`, `$fieldId`, and `$descriptor` populated, so the driver
 * downstream never sees an unresolved reference (ADR 0021).
 *
 * Carrying both forms on one class keeps the AST referentially
 * transparent — resolution allocates new immutable instances rather
 * than mutating the original tree.
 */
final class FieldRef
{
    public function __construct(
        public readonly string $modelName,
        public readonly string $fieldName,
        public readonly ?int $modelId = null,
        public readonly ?int $fieldId = null,
        public readonly ?FieldDescriptor $descriptor = null,
    ) {
    }

    /**
     * Returns a new `FieldRef` carrying the registry-resolved metadata.
     * The original `$modelName` / `$fieldName` are preserved verbatim so
     * downstream emitters (logs, JSON pointers) can echo the consumer's
     * own spelling rather than the resolved id.
     */
    public function withResolved(int $modelId, FieldDescriptor $descriptor): self
    {
        return new self(
            modelName:  $this->modelName,
            fieldName:  $this->fieldName,
            modelId:    $modelId,
            fieldId:    $descriptor->fieldId,
            descriptor: $descriptor,
        );
    }

    public function isResolved(): bool
    {
        return $this->descriptor !== null;
    }

    /**
     * Convenience constructor for internal callers (and tests) that
     * already have the model context from the surrounding request and
     * only know the field name. The resolver treats an empty
     * `$modelName` as "use the request's modelId".
     */
    public static function local(string $fieldName): self
    {
        return new self(modelName: '', fieldName: $fieldName);
    }
}
