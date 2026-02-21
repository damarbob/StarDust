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
        public ?array $ids = null,
        public ?array $sort = null,
        public ?array $selectedFields = null
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

    public function withSelectedFields(array $fields): self
    {
        return new self(
            searchQuery: $this->searchQuery,
            createdAfter: $this->createdAfter,
            createdBefore: $this->createdBefore,
            updatedAfter: $this->updatedAfter,
            updatedBefore: $this->updatedBefore,
            ids: $this->ids,
            sort: $this->sort,
            selectedFields: $fields
        );
    }
}
