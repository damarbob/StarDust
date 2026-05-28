<?php

declare(strict_types=1);

namespace StarDust\Search;

use StarDust\Exception\PageSizeOutOfRangeException;
use StarDust\Filter\Ast\FilterNode;
use StarDust\Read\Cursor;

/**
 * Phase 8 driver-facing read request.
 *
 * The {@see EntrySearchInterface::list()} entry takes one of these and
 * returns a {@see SearchResult}. Distinct from Phase 4's
 * {@see \StarDust\Read\EntryQuery} so the driver surface does not
 * inherit Phase 4's append-only DTO baggage; the two convert via
 * {@see SearchRequest::fromEntryQuery()} / {@see SearchResult::toEntryPage()}.
 *
 * `$filter === null` is the normative match-all signal. `$correlationId`
 * is allocated by the {@see SearchService} orchestrator so drivers can
 * thread the same id through their own log emissions; tests construct
 * an instance with a fixed id to make assertions reproducible.
 */
final class SearchRequest
{
    public const DEFAULT_PAGE_SIZE = 100;
    public const MAX_PAGE_SIZE     = 1000;
    public const MIN_PAGE_SIZE     = 1;

    /**
     * @param list<string>|null $selectFields field names to populate on result rows;
     *                                        `null` returns every registered field for
     *                                        the model
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $modelId,
        public readonly ?FilterNode $filter = null,
        public readonly ?array $selectFields = null,
        public readonly int $pageSize = self::DEFAULT_PAGE_SIZE,
        public readonly ?Cursor $cursor = null,
        public readonly string $correlationId = '',
    ) {
        if ($pageSize < self::MIN_PAGE_SIZE || $pageSize > self::MAX_PAGE_SIZE) {
            throw new PageSizeOutOfRangeException(
                "SearchRequest pageSize must be in [" . self::MIN_PAGE_SIZE
                . ', ' . self::MAX_PAGE_SIZE . "]; got {$pageSize}."
            );
        }
    }

    public function withCorrelationId(string $correlationId): self
    {
        return new self(
            tenantId:      $this->tenantId,
            modelId:       $this->modelId,
            filter:        $this->filter,
            selectFields:  $this->selectFields,
            pageSize:      $this->pageSize,
            cursor:        $this->cursor,
            correlationId: $correlationId,
        );
    }

    public function withFilter(?FilterNode $filter): self
    {
        return new self(
            tenantId:      $this->tenantId,
            modelId:       $this->modelId,
            filter:        $filter,
            selectFields:  $this->selectFields,
            pageSize:      $this->pageSize,
            cursor:        $this->cursor,
            correlationId: $this->correlationId,
        );
    }

    public static function fromEntryQuery(\StarDust\Read\EntryQuery $query): self
    {
        return new self(
            tenantId:     $query->tenantId,
            modelId:      $query->modelId,
            filter:       $query->filter,
            selectFields: $query->selectFields,
            pageSize:     $query->pageSize,
            cursor:       $query->cursor,
        );
    }
}
