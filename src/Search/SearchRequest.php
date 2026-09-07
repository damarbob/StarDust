<?php

declare(strict_types=1);

namespace StarDust\Search;

use StarDust\Exception\PageSizeOutOfRangeException;
use StarDust\Filter\Ast\FilterNode;
use StarDust\Read\Cursor;
use StarDust\Read\SortSpec;

/**
 * Phase 8 driver-facing read request.
 *
 * The {@see EntrySearchInterface::list()} entry takes one of these and
 * returns a {@see SearchResult}. Distinct from Phase 4's
 * {@see \StarDust\Read\EntryQuery} so the driver surface does not
 * inherit Phase 4's append-only DTO baggage; the two convert via
 * {@see SearchRequest::fromEntryQuery()} / {@see SearchResult::toEntryPage()}.
 *
 * `$filter === null` is the normative match-all signal.
 *
 * `$correlationId` is the operation's id, threaded so drivers and
 * pre-flight stages emit under the same one. Empty string means "not
 * supplied", and {@see SearchService} mints a UUID on that — it is the
 * sentinel rather than null because this parameter predates the
 * caller-supplied contract, when the orchestrator was its only source
 * and tests set a fixed value for reproducible assertions. A consumer
 * may now pass their own request id, here or via `EntryQuery`.
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
        public readonly ?SortSpec $sort = null,
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
            sort:          $this->sort,
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
            sort:          $this->sort,
        );
    }

    public static function fromEntryQuery(\StarDust\Read\EntryQuery $query): self
    {
        return new self(
            tenantId:     $query->tenantId,
            modelId:      $query->modelId,
            filter:       $query->filter,
            // EntryQuery accepts any string array (it is a public DTO and
            // PHP does not enforce list-ness); normalise on the way in.
            selectFields: $query->selectFields === null
                ? null
                : array_values($query->selectFields),
            pageSize:     $query->pageSize,
            cursor:       $query->cursor,
            sort:         $query->sort,
            // `''` rather than null is this class's "no id" sentinel —
            // see the constructor. SearchService mints one on that value,
            // so a query with no id behaves exactly as read() always has.
            correlationId: $query->correlationId ?? '',
        );
    }
}
