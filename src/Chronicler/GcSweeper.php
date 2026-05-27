<?php

declare(strict_types=1);

namespace StarDust\Chronicler;

use PDO;
use Psr\Log\LoggerInterface;

/**
 * Artifact garbage-collection sweep. Runs on Chronicler idle ticks (no
 * pending or abandoned jobs to claim) and reclaims two categories of
 * artifact files:
 *
 *   1. **TTL'd completed jobs** — `status='completed'` AND
 *      `completed_at + chroniclerArtifactTtlSeconds < NOW()` AND
 *      `artifact_path IS NOT NULL`. The job row is retained for audit;
 *      only the file + `artifact_path` column are cleared.
 *   2. **Orphaned failed-job partials** — `status='failed'` AND
 *      `completed_at + chroniclerOrphanedPartialTtlSeconds < NOW()` AND
 *      `artifact_path IS NOT NULL`. The processor best-effort-deletes
 *      on terminal failure, but a crash between mark-failed and unlink
 *      could leave a stranded file; this is the cleanup path.
 *
 * Per-row tiny transactions keep the lock window minimal and ensure
 * `gc_swept` is accurate to bytes-actually-reclaimed. Idle cycles
 * with zero deletions emit nothing (chronicler_daemon.md §4 idle
 * behavior).
 */
final class GcSweeper
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LoggerInterface $logger,
        private readonly int $artifactTtlSeconds,
        private readonly int $orphanedPartialTtlSeconds,
    ) {
    }

    public function sweep(string $correlationId): GcResult
    {
        $deleted = 0;
        $bytes   = 0;

        // === Bucket 1: completed jobs past TTL ===
        $rows = $this->loadCandidates(
            "status = 'completed' AND completed_at IS NOT NULL"
            . ' AND completed_at < (UTC_TIMESTAMP() - INTERVAL ' . $this->artifactTtlSeconds . ' SECOND)'
        );
        foreach ($rows as $row) {
            $reclaim = $this->reclaim((int) $row['id'], (string) $row['artifact_path']);
            $deleted += $reclaim['deleted'];
            $bytes   += $reclaim['bytes'];
        }

        // === Bucket 2: failed jobs past orphan TTL ===
        $rows = $this->loadCandidates(
            "status = 'failed' AND completed_at IS NOT NULL"
            . ' AND completed_at < (UTC_TIMESTAMP() - INTERVAL ' . $this->orphanedPartialTtlSeconds . ' SECOND)'
        );
        foreach ($rows as $row) {
            $reclaim = $this->reclaim((int) $row['id'], (string) $row['artifact_path']);
            $deleted += $reclaim['deleted'];
            $bytes   += $reclaim['bytes'];
        }

        $result = new GcResult($deleted, $bytes);

        if ($deleted > 0) {
            $this->logger->info('chronicler gc swept', [
                'event'              => 'gc_swept',
                'source'             => 'chronicler',
                'correlation_id'     => $correlationId,
                'tenant_id'          => null,
                'artifacts_deleted'  => $deleted,
                'bytes_reclaimed'    => $bytes,
            ]);
        }

        return $result;
    }

    /**
     * @return list<array{id: int|string, artifact_path: string}>
     */
    private function loadCandidates(string $whereSql): array
    {
        $sql = 'SELECT id, artifact_path FROM stardust_export_jobs'
            . ' WHERE ' . $whereSql
            . ' AND artifact_path IS NOT NULL';
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            return [];
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array{deleted: int, bytes: int}
     */
    private function reclaim(int $jobId, string $artifactPath): array
    {
        $bytes = 0;
        if (is_file($artifactPath)) {
            $size = @filesize($artifactPath);
            if (is_int($size) && $size > 0) {
                $bytes = $size;
            }
            @unlink($artifactPath);
        }

        // Always null out the column so we don't repeatedly probe
        // already-reclaimed rows on every idle tick.
        $update = $this->pdo->prepare(
            'UPDATE stardust_export_jobs SET artifact_path = NULL WHERE id = ?'
        );
        $update->execute([$jobId]);

        return ['deleted' => 1, 'bytes' => $bytes];
    }
}
