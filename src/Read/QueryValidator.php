<?php

declare(strict_types=1);

namespace StarDust\Read;

use Psr\Log\LoggerInterface;
use StarDust\Exception\FieldNotFilterableException;
use StarDust\Exception\FieldNotIndexedException;
use StarDust\Exception\PageSizeOutOfRangeException;
use StarDust\Exception\UnknownFieldException;

/**
 * Pre-flight rejection per ADR 0004 (fail-fast on unindexed filters).
 *
 * Every filter and order clause is checked against the
 * {@see SnapshotEntry} captured by {@see SchemaVersionCache} *before*
 * any data query executes:
 *
 *   - Unknown field name        → {@see UnknownFieldException}
 *   - `is_filterable = false`   → {@see FieldNotFilterableException}
 *   - Slot status NOT IN
 *     (`assigned`, `ready`)     → {@see FieldNotIndexedException}
 *
 * Page-size out of range surfaces as a {@see PageSizeOutOfRangeException}.
 *
 * Every rejection emits an `api: pre_flight_rejected` structured-log
 * event (ADR 0020 closed vocabulary) before throwing, so dashboards
 * can count rejection reasons without scraping exception text.
 *
 * `selectFields` are *not* validated here: per ADR 0013 / 0007 the
 * read path is allowed to return any registered field of the model —
 * the {@see ResultAssembler} sources from the slot column when
 * available and from `JSON_EXTRACT` otherwise. The only acceptance
 * test on `selectFields` is "the name exists in `stardust_fields` for
 * this model"; that check happens here too for symmetry with filter
 * validation, but produces only an UnknownFieldException — never a
 * filterable/indexed rejection.
 */
final class QueryValidator
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function validate(EntryQuery $query, SnapshotEntry $snapshot, string $correlationId): void
    {
        $this->validatePageSize($query, $correlationId);

        foreach ($query->filters as $filter) {
            $this->validateFilterTarget($filter->fieldName, $snapshot, $query->tenantId, $correlationId);
        }

        if ($query->selectFields !== null) {
            foreach ($query->selectFields as $name) {
                if ($snapshot->field($name) === null) {
                    $this->emitRejection($correlationId, $query->tenantId, 'field_unknown', $name);
                    throw new UnknownFieldException(
                        "EntryQuery selectFields references unknown field '{$name}' "
                        . "for model {$snapshot->modelId}."
                    );
                }
            }
        }
    }

    private function validatePageSize(EntryQuery $query, string $correlationId): void
    {
        if ($query->pageSize < EntryQuery::MIN_PAGE_SIZE || $query->pageSize > EntryQuery::MAX_PAGE_SIZE) {
            $this->emitRejection($correlationId, $query->tenantId, 'page_size_out_of_range', null);
            throw new PageSizeOutOfRangeException(
                "EntryQuery pageSize must be in [" . EntryQuery::MIN_PAGE_SIZE
                . ', ' . EntryQuery::MAX_PAGE_SIZE . "]; got {$query->pageSize}."
            );
        }
    }

    private function validateFilterTarget(
        string $fieldName,
        SnapshotEntry $snapshot,
        int $tenantId,
        string $correlationId,
    ): void {
        $descriptor = $snapshot->field($fieldName);
        if ($descriptor === null) {
            $this->emitRejection($correlationId, $tenantId, 'field_unknown', $fieldName);
            throw new UnknownFieldException(
                "EntryQuery references unknown field '{$fieldName}' "
                . "for model {$snapshot->modelId}."
            );
        }

        if (! $descriptor->isFilterable) {
            $this->emitRejection($correlationId, $tenantId, 'field_not_filterable', $fieldName);
            throw new FieldNotFilterableException(
                "Field '{$fieldName}' has is_filterable=false; refusing the query "
                . 'per ADR 0004 (fail-fast on unindexed filters).'
            );
        }

        // ADR 0004 uniformly rejects `backfilling`, `tombstoned`, and
        // unmapped (no slot row) — all three are "no indexed slot
        // available right now." Filterable AND status IN ('assigned',
        // 'ready') is the sole acceptance condition.
        if (! $descriptor->isIndexedNow()) {
            $this->emitRejection($correlationId, $tenantId, 'field_not_indexed', $fieldName);
            throw new FieldNotIndexedException(
                "Field '{$fieldName}' is not currently served by an indexed slot "
                . "(status=" . ($descriptor->slotStatus ?? 'unmapped') . '); '
                . 'refusing the query per ADR 0004.'
            );
        }
    }

    private function emitRejection(
        string $correlationId,
        int $tenantId,
        string $reason,
        ?string $fieldName,
    ): void {
        $context = [
            'event'          => 'pre_flight_rejected',
            'source'         => 'api',
            'correlation_id' => $correlationId,
            'tenant_id'      => $tenantId,
            'reason'         => $reason,
        ];
        if ($fieldName !== null) {
            $context['field_name'] = $fieldName;
        }
        $this->logger->warning('read pre-flight rejected', $context);
    }
}
