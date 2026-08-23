<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Thrown when a model rename would collide with another model's name
 * inside the same tenant.
 *
 * `ux_models_tenant_name (tenant_id, name)` is the real backstop; the
 * explicit pre-check exists so the caller gets a typed, readable error
 * instead of a raw `PDOException` carrying errno 1062.
 *
 * **Deliberately simpler than {@see FieldNameConflictException}.** That
 * one also has to guard `previous_name`, because a field rename leaves
 * the old name live in a spare column while its payload backfill drains
 * — so a second field renamed into the first's vacated name would make
 * the first read the second's values. A model rename has no such window:
 * nothing resolves a model by name, so there is no old name to keep
 * reserved and only the current-name collision can arise.
 */
final class ModelNameConflictException extends RuntimeException
{
}
