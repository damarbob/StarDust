<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Support;

use StarDust\Read\Entry;
use StarDust\Search\EntrySearchInterface;
use StarDust\Search\SearchRequest;
use StarDust\Search\SearchResult;

/**
 * A custom driver as a consumer would write one before the batch read
 * existed: it implements {@see EntrySearchInterface} and nothing else.
 *
 * It hands every call to a real driver and records the point reads that
 * reach it, so a test can say what `StarDust::getMany()` asked a driver
 * without the batch capability.
 */
final class DelegatingSearchDriver implements EntrySearchInterface
{
    /** @var list<array{int, int}> tenant id and entry id of each `get()` */
    public array $getCalls = [];

    public function __construct(private readonly EntrySearchInterface $inner)
    {
    }

    public function list(SearchRequest $request): SearchResult
    {
        return $this->inner->list($request);
    }

    public function get(int $tenantId, int $entryId): ?Entry
    {
        $this->getCalls[] = [$tenantId, $entryId];

        return $this->inner->get($tenantId, $entryId);
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
