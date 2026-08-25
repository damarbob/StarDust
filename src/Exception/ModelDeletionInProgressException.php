<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Thrown when something targets a model whose ADR 0038 deletion has been
 * initiated but whose entry purge has not finished —
 * `stardust_models.deleted_at` is non-null.
 *
 * The model-level counterpart of {@see FieldDeletionInProgressException},
 * and keyed on a registry column for the same reason: it still fires for
 * a model whose purge checkpoint was manually failed or deleted, where a
 * checkpoint-keyed guard would silently pass.
 *
 * ## Why writes throw here when a deleted *field's* key is merely stripped
 *
 * ADR 0037 strips a deleted field's key from an inbound write rather than
 * rejecting it, because a rejected write loses data while a strip
 * converges. For a model there is no residual valid entry to preserve:
 * the write is a request to create a row in a partition being erased.
 * Accepting it either lands behind the purge cursor — making the
 * acceptance a lie — or ahead of it, creating a permanent orphan with a
 * dangling `model_id`, which is the very thing ADR 0038 exists to
 * eliminate. It is also the one case where loud rejection costs the
 * caller nothing real, since the row would be destroyed inside the drain
 * window regardless.
 *
 * So `write()`, `updateEntry()`, `bulkWrite()` and `submitBulkWrite()`
 * raise it, as do `compactModel()` and `submitExport()`. Note the
 * surfaces that deliberately do **not**: `read()`, `search()` and `get()`
 * go dark instead — indistinguishable from a model that never existed,
 * matching the tenant-isolation posture `describeModel()` already takes —
 * and `deleteEntry()` returns `false`, because soft-deleting a row about
 * to be hard-deleted achieves nothing.
 *
 * `SchemaBuilder` raises it for the third reason
 * {@see FieldDeletionInProgressException} documents, one level up:
 * `ux_models_tenant_name` is unconditional, so a deleting model still
 * holds its name until the purge lands. It is additionally raised by
 * `defineField()` against a deleting model — reporting a *field*-deletion
 * error there would tell the caller to wait for a name that is never
 * coming back, and letting the insert through would hand a filterable
 * field to a severed model, re-taking the foreign key the purge's final
 * DELETE needs released.
 *
 * Note `deleteModel()` itself throws nothing for the symmetric case — a
 * delete against an already-deleting model returns `false`, matching
 * `deleteField()` and `deleteEntry()`.
 */
final class ModelDeletionInProgressException extends RuntimeException
{
}
