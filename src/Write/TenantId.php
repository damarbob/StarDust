<?php

declare(strict_types=1);

namespace StarDust\Write;

use StarDust\Exception\InvalidTenantIdException;

/**
 * Boundary validator for `tenant_id` arguments to every write- and
 * read-path entry point per Architecture Blueprint §1.2.
 *
 * Contract: `tenant_id` is a positive BIGINT, i.e. an integer in
 * `[1, 2^63 − 1]`. The upper bound is the natural `PHP_INT_MAX` on a
 * 64-bit host; this class documents that as an intentional invariant
 * rather than a runtime check it could meaningfully fail against.
 *
 * Engine code MUST call `assertValid()` before issuing any SQL. The
 * Phase 3 surface ({@see \StarDust\Write\EntryWriter},
 * {@see \StarDust\Write\BulkIngestor},
 * {@see \StarDust\Write\BulkIngestSubmitter}) and the `StarDust`
 * engine class call it at every public entry point as defense in
 * depth.
 */
final class TenantId
{
    public static function assertValid(int $tenantId): void
    {
        if ($tenantId <= 0) {
            throw new InvalidTenantIdException(
                "tenant_id must be a positive BIGINT (>= 1); got {$tenantId}."
            );
        }
    }
}
