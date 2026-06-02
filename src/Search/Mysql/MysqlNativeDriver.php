<?php

declare(strict_types=1);

namespace StarDust\Search\Mysql;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Psr\Log\LoggerInterface;
use StarDust\Filter\Operator;
use StarDust\Read\BoundedFetch;
use StarDust\Read\CursorCodec;
use StarDust\Read\Entry;
use StarDust\Read\EntryQuery;
use StarDust\Read\PaginatedProbe;
use StarDust\Read\ResultAssembler;
use StarDust\Read\SchemaVersionCache;
use StarDust\Search\ConsistencyModel;
use StarDust\Search\EntrySearchInterface;
use StarDust\Search\SearchRequest;
use StarDust\Search\SearchResult;

/**
 * Phase 8 default driver — wraps the Phase 4 read path behind the
 * {@see EntrySearchInterface} contract.
 *
 * `list(SearchRequest)` is structurally identical to Phase 4's
 * {@see \StarDust\Read\EntryReader::read()}: snapshot the schema,
 * probe, fetch, assemble, encode-next-cursor. The
 * {@see SqlFilterCompiler} (composed via {@see PaginatedProbe}) picks
 * the JOIN or EXISTS strategy per the request's filter shape.
 *
 * `supportsFilterOn()` is the MySQL-driver jurisdiction of
 * `is_filterable` per ADR 0022: it returns true iff the field's
 * registry row carries `is_filterable = true` AND has a live slot in
 * `assigned` or `ready` status — the same definition used by
 * {@see \StarDust\Read\FieldDescriptor::isIndexedNow()}.
 *
 * Strong consistency: every read sees every committed write
 * ({@see ConsistencyModel::STRONG}).
 */
final class MysqlNativeDriver implements EntrySearchInterface
{
    private string $lastCompileStrategy = 'joins';

    private readonly SqlFilterCompiler $compiler;
    private readonly PaginatedProbe $probe;
    private readonly BoundedFetch $fetch;
    private readonly ResultAssembler $assembler;

    public function __construct(
        private readonly PDO $pdo,
        // Kept for EntrySearchInterface implementation uniformity: a custom
        // driver may emit its own diagnostics. The default MySQL driver is a
        // pure executor and delegates all logging to SearchService.
        // @phpstan-ignore property.onlyWritten
        private readonly LoggerInterface $logger,
        private readonly SchemaVersionCache $cache,
        ?SqlFilterCompiler $compiler = null,
        ?PaginatedProbe $probe = null,
        ?BoundedFetch $fetch = null,
        ?ResultAssembler $assembler = null,
    ) {
        $this->compiler  = $compiler ?? new SqlFilterCompiler();
        $this->probe     = $probe ?? new PaginatedProbe($this->pdo, $this->compiler);
        $this->fetch     = $fetch ?? new BoundedFetch($this->pdo);
        $this->assembler = $assembler ?? new ResultAssembler();
    }

    public function list(SearchRequest $request): SearchResult
    {
        $snapshot = $this->cache->snapshotForModel(
            $request->modelId,
            $request->tenantId,
            $request->correlationId,
        );

        $query = new EntryQuery(
            tenantId:     $request->tenantId,
            modelId:      $request->modelId,
            filter:       $request->filter,
            selectFields: $request->selectFields,
            pageSize:     $request->pageSize,
            cursor:       $request->cursor,
        );

        $this->lastCompileStrategy = $this->compiler->chooseStrategy($request->filter);

        $ids = $this->probe->probe($query, $snapshot);
        $hasMore = count($ids) > $request->pageSize;
        $rowIds = $hasMore ? array_slice($ids, 0, $request->pageSize) : $ids;

        $fetchResult = $this->fetch->fetch($query, $snapshot, $rowIds);
        $entries = $this->assembler->assemble(
            $fetchResult['rows'],
            $snapshot,
            $fetchResult['slotColumnByField'],
            $request->selectFields,
        );

        $nextCursor = $hasMore && $rowIds !== []
            ? CursorCodec::encode(end($rowIds))
            : null;

        return new SearchResult(
            rows:       $entries,
            nextCursor: $nextCursor,
            pageSize:   $request->pageSize,
        );
    }

    public function get(int $tenantId, int $entryId): ?Entry
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, model_id, created_at, deleted_at, fields'
            . ' FROM entry_data'
            . ' WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$entryId, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $payload = json_decode((string) $row['fields'], true);
        return new Entry(
            id:        (int) $row['id'],
            tenantId:  (int) $row['tenant_id'],
            modelId:   (int) $row['model_id'],
            fields:    is_array($payload) ? $payload : [],
            createdAt: new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')),
            deletedAt: $row['deleted_at'] === null
                ? null
                : new DateTimeImmutable((string) $row['deleted_at'], new DateTimeZone('UTC')),
        );
    }

    public function supportedOperators(): array
    {
        return Operator::CLOSED_V1;
    }

    public function supportsFilterOn(int $fieldId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT f.is_filterable AS is_filterable, a.status AS slot_status'
            . ' FROM stardust_fields f'
            . ' LEFT JOIN stardust_slot_assignments a'
            . "   ON a.field_id = f.id AND a.status IN ('assigned','backfilling','ready','tombstoned')"
            . ' WHERE f.id = ?'
        );
        $stmt->execute([$fieldId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return false;
        }
        if (!(bool) $row['is_filterable']) {
            return false;
        }
        return $row['slot_status'] === 'assigned' || $row['slot_status'] === 'ready';
    }

    public function supportsFuzzySearch(): bool
    {
        return false;
    }

    public function consistencyModel(): string
    {
        return ConsistencyModel::STRONG;
    }

    /**
     * Exposed for `SearchService::execute()` so the `search_request`
     * event can carry the chosen compile strategy (`joins` or
     * `exists`). Reset on every `list()` call.
     */
    public function lastCompileStrategy(): string
    {
        return $this->lastCompileStrategy;
    }
}
