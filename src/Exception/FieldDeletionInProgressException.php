<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Thrown when something targets a field whose ADR 0037 deletion has
 * been initiated but whose payload purge has not finished —
 * `stardust_fields.deleted_at` is non-null.
 *
 * Two distinct callers raise it, for two different reasons.
 *
 * **The lifecycle guards** (`RenameInitiator`, and
 * `RetypeInitiator::runTuple()` so `compactModel()` inherits it) raise
 * it because a field may have at most one lifecycle in flight. Starting
 * a rename or retype against a field that is being deleted would race
 * two payload rewrites over the same key, and the retype direction is
 * the same silent-NULL correctness hazard that
 * {@see RenameInProgressException} documents.
 *
 * **`SchemaBuilder::defineField()`** raises it because
 * `ux_fields_model_name` is unconditional, so a field being deleted
 * still holds its name until the purge lands. Re-registering that name
 * has to fail loudly: the get-or-create lookup excludes soft-deleted
 * rows precisely so it cannot silently hand back the id of a field
 * whose values are actively being erased.
 *
 * Unlike {@see RenameInProgressException} and
 * {@see RetypeInProgressException}, this one is keyed on a registry
 * column rather than a checkpoint row — which means it still fires for
 * a field whose purge checkpoint was manually failed or deleted, where
 * a checkpoint-keyed guard would silently pass.
 */
final class FieldDeletionInProgressException extends RuntimeException
{
}
