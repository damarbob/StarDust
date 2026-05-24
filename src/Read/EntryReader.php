<?php

declare(strict_types=1);

namespace StarDust\Read;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Psr\Log\LoggerInterface;
use StarDust\Support\UuidV4;
use StarDust\Write\TenantId;

/**
 * Phase 4 read-path orchestrator.
 *
 * `read(EntryQuery)` runs the canonical two-query bounded sequence of
 * ADR 0005:
 *
 *   1. Validate `tenant_id` at the boundary (Architecture Blueprint §1.2).
 *   2. Resolve the per-model {@see SnapshotEntry} via the
 *      {@see SchemaVersionCache} (ADR 0015).
 *   3. Pre-flight validation against the snapshot
 *      ({@see QueryValidator}) — rejects unindexed/unmapped filters
 *      *before* any data query executes (ADR 0004).
 *   4. {@see PaginatedProbe} — selects up to `pageSize + 1` entry IDs;
 *      the trailing row is the sole next-page signal.
 *   5. {@see BoundedFetch} — materialises the entry rows plus indexed
 *      slot columns for those IDs only.
 *   6. {@see ResultAssembler} — merges slot values with JSON-payload
 *      fallbacks into typed `Entry` DTOs.
 *
 * Emits an `api: request` structured-log event after every successful
 * call. `pre_flight_rejected` is emitted by `QueryValidator`;
 * `cache_miss` is emitted by `SchemaVersionCache`. The
 * `correlation_id` synthesised here threads through every event
 * emitted by the same request — operators can stitch a rejection,
 * a cache refresh, and the eventual request log together by ID.
 *
 * `get(int $tenantId, int $entryId)` is the single-entry point read
 * — no slot joins are needed at point-read scale; the JSON payload
 * is the system of record per ADR 0013 and {@see ResultAssembler}
 * sources every field from it.
 */
final class EntryReader
{
    private readonly QueryValidator $validator;
    private readonly SchemaVersionCache $cache;
    private readonly PaginatedProbe $probe;
    private readonly BoundedFetch $fetch;
    private readonly ResultAssembler $assembler;

    public function __construct(
        private readonly PDO $pdo,
        private readonly LoggerInterface $logger,
    ) {
        $this->validator  = new QueryValidator($this->logger);
        $this->cache      = new SchemaVersionCache($this->pdo, $this->logger);
        $this->probe      = new PaginatedProbe($this->pdo);
        $this->fetch      = new BoundedFetch($this->pdo);
        $this->assembler  = new ResultAssembler();
    }

    public function read(EntryQuery $query): EntryPage
    {
        TenantId::assertValid($query->tenantId);

        $correlationId = UuidV4::generate();
        $startedAt = hrtime(true);

        $snapshot = $this->cache->snapshotForModel($query->modelId, $query->tenantId, $correlationId);

        $this->validator->validate($query, $snapshot, $correlationId);

        $ids = $this->probe->probe($query, $snapshot);
        $hasMore = count($ids) > $query->pageSize;
        $rowIds = $hasMore ? array_slice($ids, 0, $query->pageSize) : $ids;

        $fetchResult = $this->fetch->fetch($query, $snapshot, $rowIds);
        $entries = $this->assembler->assemble(
            $fetchResult['rows'],
            $snapshot,
            $fetchResult['slotColumnByField'],
            $query->selectFields,
        );

        $nextCursor = $hasMore && $rowIds !== []
            ? CursorCodec::encode(end($rowIds))
            : null;

        $latencyMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
        $this->logger->info('read', [
            'event'          => 'request',
            'source'         => 'api',
            'correlation_id' => $correlationId,
            'tenant_id'      => $query->tenantId,
            'model_id'       => $query->modelId,
            'route'          => 'read',
            'latency_ms'     => $latencyMs,
            'rows_returned'  => count($entries),
            'has_more'       => $hasMore,
        ]);

        return new EntryPage(
            rows: $entries,
            nextCursor: $nextCursor,
            pageSize: $query->pageSize,
        );
    }

    public function get(int $tenantId, int $entryId): ?Entry
    {
        TenantId::assertValid($tenantId);

        $correlationId = UuidV4::generate();
        $startedAt = hrtime(true);

        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, model_id, created_at, deleted_at, fields'
            . ' FROM entry_data'
            . ' WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$entryId, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $latencyMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
        $this->logger->info('get', [
            'event'          => 'request',
            'source'         => 'api',
            'correlation_id' => $correlationId,
            'tenant_id'      => $tenantId,
            'route'          => 'get',
            'entry_id'       => $entryId,
            'latency_ms'     => $latencyMs,
            'found'          => $row !== false,
        ]);

        if ($row === false) {
            return null;
        }

        $payload = json_decode((string) $row['fields'], true);
        return new Entry(
            id: (int) $row['id'],
            tenantId: (int) $row['tenant_id'],
            modelId: (int) $row['model_id'],
            fields: is_array($payload) ? $payload : [],
            createdAt: new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')),
            deletedAt: $row['deleted_at'] === null
                ? null
                : new DateTimeImmutable((string) $row['deleted_at'], new DateTimeZone('UTC')),
        );
    }

}
