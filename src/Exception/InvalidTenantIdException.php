<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Thrown when a write- or read-path entry point is called with a
 * `tenant_id` outside the supported range `[1, 2^63 − 1]`.
 *
 * Per Architecture Blueprint §1.2 the engine treats `tenant_id` as a
 * boundary contract: every public function-API entry point validates the
 * bound before issuing SQL. StarGate (or the consumer) owns mapping
 * external identifiers to this positive BIGINT.
 */
final class InvalidTenantIdException extends RuntimeException
{
}
