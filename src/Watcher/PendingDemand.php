<?php

declare(strict_types=1);

namespace StarDust\Watcher;

/**
 * The slot shapes filterable fields are currently waiting on.
 *
 * A "waiter" is a filterable field with no live slot: either it was
 * never assigned one, or its slot was tombstoned by a retype whose
 * replacement reservation deferred for want of capacity. The Watcher
 * uses this both to decide whether provisioning is warranted and to
 * choose which columns of the new page to index.
 *
 * **Demand is keyed by slot family alone**, and that simplification is
 * contingent, not eternal: it holds because every reservation path with
 * production callers demands an *indexed* slot (ADR 0016 commitment 1,
 * and ADR 0034 made the retype call site pass `requireIndexed: true`
 * unconditionally). If a non-indexed reservation path ever gains a
 * production caller, demand grows a second dimension and this DTO
 * becomes `family → (indexedWaiters, anyWaiters)`.
 *
 * ADR 0032 (model-affine reservation) will likewise want a per-model
 * projection so a new page can be packed for one model. Keep
 * `waitersByFamily` the public surface and add projections alongside
 * it rather than leaking the underlying rows.
 */
final class PendingDemand
{
    /** @var array<string, int> slot_type => waiter count, ksorted, zero counts absent */
    private readonly array $waitersByFamily;

    /**
     * @param array<string, int> $waitersByFamily slot_type => waiter count
     */
    public function __construct(array $waitersByFamily)
    {
        $filtered = array_filter($waitersByFamily, static fn (int $n): bool => $n > 0);
        ksort($filtered);

        $this->waitersByFamily = $filtered;
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->waitersByFamily === [];
    }

    /** @return list<string> demanded slot families, ascending */
    public function families(): array
    {
        return array_keys($this->waitersByFamily);
    }

    public function waitersFor(string $family): int
    {
        return $this->waitersByFamily[$family] ?? 0;
    }

    public function totalWaiters(): int
    {
        return array_sum($this->waitersByFamily);
    }

    /**
     * Ksorted so the NDJSON log line is diffable between polls.
     *
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return $this->waitersByFamily;
    }

    /**
     * The log-payload form: always a JSON **object**, never an array.
     *
     * `json_encode([])` is `[]` but `json_encode(['str' => 1])` is
     * `{"str":1}`, so emitting the raw map would change the field's
     * JSON type the moment demand became non-zero. A strict-mapping
     * sink types the field from the first document it sees and then
     * rejects the other shape — which would drop the demand signal
     * exactly when it starts mattering. Casting to an object pins it.
     */
    public function forLog(): object
    {
        return (object) $this->waitersByFamily;
    }
}
