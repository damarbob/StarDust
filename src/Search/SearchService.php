<?php

declare(strict_types=1);

namespace StarDust\Search;

use Psr\Clock\ClockInterface;
use PDO;
use Psr\Log\LoggerInterface;
use StarDust\Read\SchemaVersionCache;
use StarDust\Search\PreFlight\PreFlightPipeline;
use StarDust\Support\UuidV4;

/**
 * Phase 8 top-level search orchestrator.
 *
 * Drives one {@see EntrySearchInterface::list()} call end-to-end:
 *
 *   1. Allocate a `correlation_id` (UUID v4) if the request didn't
 *      carry one. The id threads through every event emitted by the
 *      pre-flight visitors and the eventual `search_request` event.
 *   2. Resolve the per-model {@see \StarDust\Read\SnapshotEntry} via
 *      {@see SchemaVersionCache} (ADR 0015).
 *   3. Run {@see PreFlightPipeline::validate()} which resolves field
 *      refs, checks driver capabilities, and validates typed values —
 *      throws typed exceptions on rejection (Phase 8 wire-format
 *      blueprint §4.7).
 *   4. Hand the resolved filter to the active driver and capture the
 *      result.
 *   5. Emit one `search_request` event carrying latency, row count,
 *      whether a next page exists, the tree node count, and the
 *      compile strategy ('joins' or 'exists') so operators can spot
 *      OR/NOT usage.
 *
 * `null` filter (match-all) skips pre-flight entirely.
 */
final class SearchService
{
    public function __construct(
        private readonly EntrySearchInterface $driver,
        private readonly SchemaVersionCache $cache,
        private readonly PreFlightPipeline $preFlight,
        private readonly LoggerInterface $logger,
        // Injected for constructor-shape uniformity across services. This
        // class needs no wall-clock value: latency is measured with the
        // monotonic hrtime() and the event `ts` is stamped by the logger.
        // @phpstan-ignore property.onlyWritten
        private readonly ClockInterface $clock,
    ) {
    }

    public function execute(SearchRequest $request): SearchResult
    {
        $correlationId = $request->correlationId !== '' ? $request->correlationId : UuidV4::generate();
        $request = $request->withCorrelationId($correlationId);

        $startedAt = hrtime(true);

        // The snapshot is resolved when there is a filter OR a sort OR a
        // cursor. The filter-only condition this replaces would have let
        // a match-all sorted read skip pre-flight entirely — no field
        // resolution, no capability check, and no cursor/sort agreement
        // check — which is exactly the shape a "newest first" listing
        // takes.
        if ($request->filter !== null || $request->sort !== null || $request->cursor !== null) {
            $snapshot = $this->cache->snapshotForModel(
                $request->modelId,
                $request->tenantId,
                $correlationId,
            );

            if ($request->filter !== null) {
                $resolved = $this->preFlight->validate(
                    $request->filter,
                    $snapshot,
                    $this->driver,
                    $request->tenantId,
                    $correlationId,
                );
                $request = $request->withFilter($resolved);
            }

            $this->preFlight->validateSort(
                $request->sort,
                $request->cursor,
                $snapshot,
                $this->driver,
                $request->tenantId,
                $correlationId,
            );
        }

        $result = $this->driver->list($request);

        $latencyMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
        $this->logger->info('search executed', [
            'event'            => 'search_request',
            'source'           => 'api',
            'correlation_id'   => $correlationId,
            'tenant_id'        => $request->tenantId,
            'model_id'         => $request->modelId,
            'route'            => 'search',
            'latency_ms'       => $latencyMs,
            'rows_returned'    => count($result->rows),
            'has_more'         => $result->nextCursor !== null,
            'tree_node_count'  => $this->nodeCount($request->filter),
            'compile_strategy' => $this->driver instanceof Mysql\MysqlNativeDriver
                ? $this->driver->lastCompileStrategy()
                : 'driver_specific',
        ]);

        return $result;
    }

    private function nodeCount(?\StarDust\Filter\Ast\FilterNode $node): int
    {
        if ($node === null) {
            return 0;
        }
        $count = 1;
        if ($node instanceof \StarDust\Filter\Ast\AndNode || $node instanceof \StarDust\Filter\Ast\OrNode) {
            foreach ($node->args as $child) {
                $count += $this->nodeCount($child);
            }
        } elseif ($node instanceof \StarDust\Filter\Ast\NotNode) {
            $count += $this->nodeCount($node->arg);
        }
        return $count;
    }
}
