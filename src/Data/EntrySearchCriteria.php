<?php

namespace StarDust\Data;

class EntrySearchCriteria
{
    public ?string $searchQuery = null;
    public ?array $ids = null;
    public ?int $modelId = null;
    public ?string $createdAfter = null;
    public ?string $createdBefore = null;
    public ?string $updatedAfter = null;
    public ?string $updatedBefore = null;
    public bool $includeDeleted = false;
    public array $customFilters = [];

    public function __construct(
        ?string $searchQuery = null,
        ?array $ids = null,
        ?int $modelId = null,
        ?string $createdAfter = null,
        ?string $createdBefore = null,
        ?string $updatedAfter = null,
        ?string $updatedBefore = null,
        bool $includeDeleted = false,
        array $customFilters = []
    ) {
        $this->searchQuery = $searchQuery;
        $this->ids = $ids;
        $this->modelId = $modelId;
        $this->createdAfter = $createdAfter;
        $this->createdBefore = $createdBefore;
        $this->updatedAfter = $updatedAfter;
        $this->updatedBefore = $updatedBefore;
        $this->includeDeleted = $includeDeleted;
        $this->customFilters = $customFilters;
    }

    public function hasSearchTerm(): bool
    {
        return !empty($this->searchQuery);
    }

    public function hasIds(): bool
    {
        return !empty($this->ids);
    }

    public function hasModelId(): bool
    {
        return !empty($this->modelId);
    }

    public function hasDateFilters(): bool
    {
        return $this->createdAfter || $this->createdBefore || $this->updatedAfter || $this->updatedBefore;
    }

    public function hasCustomFilters(): bool
    {
        return !empty($this->customFilters);
    }

    public function addCustomFilter(string $field, mixed $value): void
    {
        $this->customFilters[$field] = $value;
    }
}
