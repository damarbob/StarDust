<?php

declare(strict_types=1);

namespace StarDust\Watcher;

/**
 * The {@see ProvisioningPlanner}'s verdict for one poll cycle: whether
 * to provision, why, which columns the new page should index, and the
 * capacity figures the poll logs report.
 */
final class ProvisioningPlan
{
    public const TRIGGER_NONE = 'none';

    /** A demanded family has no claimable free slot. Ignores the threshold. */
    public const TRIGGER_UNSATISFIABLE_DEMAND = 'unsatisfiable_demand';

    /** Global free ratio fell below the configured threshold. */
    public const TRIGGER_LOW_CAPACITY = 'low_capacity';

    /**
     * @param list<string> $indexedColumns slot columns the new page should index
     * @param list<string> $starvedFamilies families with no claimable free slot
     */
    public function __construct(
        public readonly bool $shouldProvision,
        public readonly string $trigger,
        public readonly array $indexedColumns,
        public readonly array $starvedFamilies,
        public readonly int $usableFree,
        public readonly int $usableTotal,
        public readonly float $usableFreeRatio,
    ) {
    }
}
