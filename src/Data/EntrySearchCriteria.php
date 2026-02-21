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
    /** @var VirtualColumnFilter[] */
    public array $customFilters = [];
    public ?array $selectedFields = null;

    public function __construct(
        ?string $searchQuery = null,
        ?array $ids = null,
        ?int $modelId = null,
        ?string $createdAfter = null,
        ?string $createdBefore = null,
        ?string $updatedAfter = null,
        ?string $updatedBefore = null,
        bool $includeDeleted = false,
        array $customFilters = [],
        public ?array $sort = null
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
        $this->sort = $sort;
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

    public function addCustomFilter(string $field, mixed $value, string $operator = '='): void
    {
        $this->customFilters[] = new VirtualColumnFilter($field, $value, $operator);
    }

    public function selectFields(array $fields): self
    {
        $this->selectedFields = $fields;
        return $this;
    }
}
