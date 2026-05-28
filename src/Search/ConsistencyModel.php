<?php

declare(strict_types=1);

namespace StarDust\Search;

/**
 * Closed two-value vocabulary returned by
 * {@see EntrySearchInterface::consistencyModel()}.
 *
 *   - `STRONG`   — results reflect every commit up to the moment of
 *                  query dispatch (MySQL native driver).
 *   - `EVENTUAL` — results may lag behind committed writes by an
 *                  unbounded amount (external search-engine drivers
 *                  consuming the sync queue).
 *
 * Callers surface this value through their own transport contract
 * (e.g., an HTTP header) per the Search Driver Adapter blueprint §4.
 */
final class ConsistencyModel
{
    public const STRONG   = 'strong';
    public const EVENTUAL = 'eventual';
}
