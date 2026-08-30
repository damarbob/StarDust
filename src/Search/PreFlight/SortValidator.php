<?php

declare(strict_types=1);

namespace StarDust\Search\PreFlight;

use Psr\Log\LoggerInterface;
use StarDust\Exception\FieldNotSortableException;
use StarDust\Exception\InvalidCursorException;
use StarDust\Exception\UnknownFieldException;
use StarDust\Read\Cursor;
use StarDust\Read\CursorCodec;
use StarDust\Read\SnapshotEntry;
use StarDust\Read\SortSpec;
use StarDust\Search\EntrySearchInterface;

/**
 * Pre-flight visitor for the sort key — the fourth stage of
 * {@see PreFlightPipeline}, and the one that makes ADR 0004's sort
 * clause real. That clause ("reject filters **and sorts** on fields that
 * are not explicitly provisioned as queryable") had been unenforceable
 * since the ADR was accepted, because there was no sort to reject.
 *
 * Three rejections, in order:
 *
 *   - the sort names a field with no registry row → {@see UnknownFieldException}.
 *     Deliberately the same exception a filter raises for the same fact;
 *     the `reason` on the event is what distinguishes them.
 *   - the active driver declines to order by it → {@see FieldNotSortableException}.
 *     Asked through `supportsSortOn()` per ADR 0022, never by reading the
 *     registry here — the pipeline is driver-agnostic and must stay so.
 *   - the cursor was issued for a different ordering →
 *     {@see InvalidCursorException}. ADR 0006 says a cursor is invalidated
 *     when the caller changes sort order; this is the first release in
 *     which that is detectable rather than merely documented.
 *
 * Intrinsic targets (`entry_data.id`, `entry_data.created_at`) skip the
 * first two checks entirely: they are columns of the core table, not
 * fields, so there is no registry row to resolve and no driver may
 * decline them.
 */
final class SortValidator
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function validate(
        ?SortSpec $sort,
        ?Cursor $cursor,
        SnapshotEntry $snapshot,
        EntrySearchInterface $driver,
        int $tenantId,
        string $correlationId,
    ): void {
        if ($sort !== null && $sort->isFieldSort()) {
            $this->checkFieldTarget($sort, $snapshot, $driver, $tenantId, $correlationId);
        }

        // Runs even when $sort is null: a v2 cursor replayed against an
        // unsorted read is just as much a mismatch as the reverse, and
        // silently walking a different ordering is the failure mode this
        // check exists to prevent.
        if ($cursor !== null) {
            $this->checkCursorMatchesSort($sort, $cursor, $tenantId, $correlationId);
        }
    }

    private function checkFieldTarget(
        SortSpec $sort,
        SnapshotEntry $snapshot,
        EntrySearchInterface $driver,
        int $tenantId,
        string $correlationId,
    ): void {
        $fieldName  = $sort->fieldNameOrFail();
        $descriptor = $snapshot->field($fieldName);

        if ($descriptor === null) {
            $this->reject($tenantId, $correlationId, 'sort_field_unknown', $fieldName);
            throw new UnknownFieldException(
                "Sort target '{$fieldName}' is not a registered field of this model."
            );
        }

        if (! $driver->supportsSortOn($descriptor->fieldId)) {
            $this->reject($tenantId, $correlationId, 'sort_field_not_sortable', $fieldName);
            throw new FieldNotSortableException(
                "Field '{$fieldName}' is not sortable on the active driver."
            );
        }
    }

    private function checkCursorMatchesSort(
        ?SortSpec $sort,
        Cursor $cursor,
        int $tenantId,
        string $correlationId,
    ): void {
        // A structurally malformed cursor raises InvalidCursorException
        // from the codec, which is the same class this method throws —
        // both mean "restart pagination", so they need no separation.
        $payload = CursorCodec::decodePayload($cursor);

        if (! $payload->matchesSort($sort)) {
            $this->reject(
                $tenantId,
                $correlationId,
                'cursor_sort_mismatch',
                $sort?->keyIdentity() ?? '$id',
            );
            throw new InvalidCursorException(
                'Cursor was issued for a different sort order; restart pagination from the'
                . ' first page after changing the sort.'
            );
        }
    }

    private function reject(int $tenantId, string $correlationId, string $reason, string $target): void
    {
        $this->logger->warning('search pre-flight rejected', [
            'event'          => 'pre_flight_rejected',
            'source'         => 'api',
            'correlation_id' => $correlationId,
            'tenant_id'      => $tenantId,
            'reason'         => $reason,
            'field_name'     => $target,
        ]);
    }
}
