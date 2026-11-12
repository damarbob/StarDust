<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Support;

use StarDust\Read\Entry;
use StarDust\Search\BatchPointReadInterface;
use StarDust\Search\EntrySearchInterface;
use StarDust\Search\SearchRequest;
use StarDust\Search\SearchResult;

/**
 * A custom driver that does implement the batch capability.
 *
 * It hands every call to a real driver and records what reaches
 * `getMany()`, so a test can say exactly which list `StarDust::getMany()`
 * gave a driver that offers the capability.
 */
final class BatchDelegatingSearchDriver implements EntrySearchInterface, BatchPointReadInterface
{
    /** @var list<array{int, list<int>}> tenant id and id list of each `getMany()` */
    public array $getManyCalls = [];

    /** @var int how many times `get()` was called */
    public int $getCalls = 0;

    public function __construct(private readonly EntrySearchInterface&BatchPointReadInterface $inner)
    {
    }

    public function list(SearchRequest $request): SearchResult
    {
        return $this->inner->list($request);
    }

    public function get(int $tenantId, int $entryId): ?Entry
    {
        $this->getCalls++;

        return $this->inner->get($tenantId, $entryId);
    }

    public function getMany(int $tenantId, array $entryIds): array
    {
        $this->getManyCalls[] = [$tenantId, $entryIds];

        return $this->inner->getMany($tenantId, $entryIds);
    }

    public function supportedOperators(): array
    {
        return $this->inner->supportedOperators();
    }

    public function supportsFilterOn(int $fieldId): bool
    {
        return $this->inner->supportsFilterOn($fieldId);
    }

    public function supportsSortOn(int $fieldId): bool
    {
        return $this->inner->supportsSortOn($fieldId);
    }

    public function supportsFuzzySearch(): bool
    {
        return $this->inner->supportsFuzzySearch();
    }

    public function consistencyModel(): string
    {
        return $this->inner->consistencyModel();
    }
}
