<?php

declare(strict_types=1);

namespace StarDust\Read;

use PDO;
use PDOException;
use Psr\Log\LoggerInterface;

/**
 * In-process schema-version cache per ADR 0015.
 *
 * On every read-path entry, the cache probes
 * `SELECT version FROM stardust_schema_version`. If the value matches
 * the cached snapshot's `capturedAtVersion`, the snapshot is reused
 * (a "cache hit"). Otherwise the snapshot is reloaded from the
 * registry and `api: cache_miss` is emitted (closed-vocabulary
 * vocabulary expanded for Phase 4 — see ADR 0020).
 *
 * A bounded-staleness 60-second TTL fallback applies when the version
 * probe itself fails with a `PDOException` — implementation_phases.md
 * Phase 4 §"Schema-version cache". A cached snapshot older than the
 * TTL during a probe failure surfaces the underlying exception rather
 * than serving silently stale data.
 *
 * The cache is per-instance (one cache per `EntryReader` lifetime).
 * It is not shared across PDO connections or processes — ADR 0015's
 * "database as sole coordination point" rule explicitly forbids
 * out-of-band cache invalidation channels.
 */
final class SchemaVersionCache
{
    private const TTL_SECONDS = 60;

    /** @var array<int, SnapshotEntry> modelId → snapshot */
    private array $snapshotsByModel = [];

    public function __construct(
        private readonly PDO $pdo,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Returns the snapshot for `$modelId`, refreshing from the registry
     * if the live `stardust_schema_version.version` has advanced since
     * the cached snapshot was captured.
     *
     * `$correlationId` is threaded into any emitted `cache_miss` event
     * so operators can stitch the cache refresh to the triggering API
     * request.
     */
    public function snapshotForModel(int $modelId, int $tenantId, string $correlationId): SnapshotEntry
    {
        $cached = $this->snapshotsByModel[$modelId] ?? null;

        try {
            $liveVersion = $this->readLiveVersion();
        } catch (PDOException $e) {
            // ADR 0015 bounded-staleness fallback: reuse the cached
            // snapshot if it exists and is younger than the TTL.
            // Beyond the TTL — or when nothing is cached — the read
            // path cannot proceed safely; surface the underlying
            // failure to the caller.
            if ($cached !== null && (time() - $cached->capturedAtUnixTs) < self::TTL_SECONDS) {
                return $cached;
            }
            throw $e;
        }

        if ($cached !== null && $cached->capturedAtVersion === $liveVersion) {
            return $cached;
        }

        $fresh = SlotResolver::load($this->pdo, $modelId, $liveVersion);
        $this->snapshotsByModel[$modelId] = $fresh;

        $this->logger->info('schema cache miss', [
            'event'          => 'cache_miss',
            'source'         => 'api',
            'correlation_id' => $correlationId,
            'tenant_id'      => $tenantId,
            'model_id'       => $modelId,
            'cached_version' => $cached?->capturedAtVersion,
            'live_version'   => $liveVersion,
        ]);

        return $fresh;
    }

    private function readLiveVersion(): int
    {
        $stmt = $this->pdo->query('SELECT version FROM stardust_schema_version WHERE id = 1');
        if ($stmt === false) {
            throw new PDOException('stardust_schema_version probe returned no statement');
        }
        $value = $stmt->fetchColumn();
        if ($value === false) {
            throw new PDOException('stardust_schema_version row is missing');
        }
        return (int) $value;
    }
}
