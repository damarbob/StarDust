<?php

namespace StarDust\Data;

/**
 * Data Transfer Object for Model Search Criteria.
 * Encapsulates all possible filter parameters for querying models.
 */
readonly class ModelSearchCriteria
{
    public function __construct(
        public ?string $searchQuery = null,
        public ?string $createdAfter = null,
        public ?string $createdBefore = null,
        public ?string $updatedAfter = null,
        public ?string $updatedBefore = null,
        public ?array $ids = null
    ) {}

    public function hasSearchTerm(): bool
    {
        return !empty($this->searchQuery);
    }

    public function hasDateFilters(): bool
    {
        return !empty($this->createdAfter) ||
            !empty($this->createdBefore) ||
            !empty($this->updatedAfter) ||
            !empty($this->updatedBefore);
    }

    public function hasIds(): bool
    {
        return !empty($this->ids);
    }
}
