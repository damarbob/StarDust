<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Thrown by the Phase 7 export submission API when a tenant already has
 * `chroniclerPerTenantActiveCap` (default 3) jobs in `pending` or
 * `processing` status. The cap is enforced inside an atomic
 * `SELECT … FOR UPDATE` + `INSERT` transaction so concurrent submitters
 * at the boundary cannot both insert.
 *
 * Carries the offending tenant id and observed active-job count so
 * upstream HTTP layers can surface a meaningful 429-style response.
 */
final class ExportJobActiveCapExceededException extends RuntimeException
{
    public function __construct(
        public readonly int $tenantId,
        public readonly int $activeCount,
        public readonly int $cap,
    ) {
        parent::__construct(
            "tenant_id={$tenantId} already has {$activeCount} active export jobs "
            . "(cap={$cap}); reject submission and retry once one completes."
        );
    }
}
