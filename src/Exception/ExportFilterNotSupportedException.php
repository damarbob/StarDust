<?php

declare(strict_types=1);

namespace StarDust\Exception;

use RuntimeException;

/**
 * Thrown by the Phase 7 export submission API when a request carries a
 * non-empty `filter`.
 *
 * Export predicate filtering is not implemented: the Chronicler's
 * `EntryDataPager` selects on `tenant_id / model_id / deleted_at` only
 * and never reads the stored filter back. Before this guard existed the
 * filter was accepted, stored verbatim, and then ignored — so a request
 * for a subset silently produced a **full extract** of the model. That
 * was the one place in the engine where being wrong was quiet rather
 * than loud, and handing a consumer every row when they asked for a few
 * is the worst shape that failure can take.
 *
 * Refusing is deliberately not the same as implementing. Filtering is
 * undesigned here — ADR 0010 and the async-exports blueprint were both
 * relocated to StarGate and neither mentions filters, predicates or
 * QueryFilter — so the honest behaviour until it is designed is to
 * decline the request rather than answer a different one.
 *
 * Carries the offending tenant, model, and the filter's top-level keys
 * so an upstream HTTP layer can name what it rejected without echoing
 * back a payload of unknown size.
 */
final class ExportFilterNotSupportedException extends RuntimeException
{
    /**
     * @param list<string> $filterKeys Top-level keys of the rejected filter.
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $modelId,
        public readonly array $filterKeys,
    ) {
        $keys = $filterKeys === [] ? '(none)' : implode(', ', $filterKeys);
        parent::__construct(
            "export filtering is not supported: request for tenant_id={$tenantId} "
            . "model_id={$modelId} carried filter keys [{$keys}]. Omit the filter — "
            . 'an export always covers every non-deleted entry in the model.'
        );
    }
}
