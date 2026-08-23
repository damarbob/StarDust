<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Thrown when a public model-level entry point receives a `model_id`
 * that does not resolve for the caller's tenant — it never existed, or
 * it belongs to someone else.
 *
 * The two cases are **deliberately indistinguishable**, for the same
 * reason `EntryNotFoundException` and `FieldNotFoundException` conflate
 * theirs: separating them would let a caller probe for the existence of
 * another tenant's models, which the Architecture Blueprint §1.2
 * isolation invariant forbids.
 *
 * `SchemaReader::describeModel()` deliberately does NOT throw this — it
 * returns `null`, because introspection asking "does this exist?" is a
 * question, whereas a mutation naming a model that isn't there is a
 * mistake.
 */
final class ModelNotFoundException extends RuntimeException
{
}
