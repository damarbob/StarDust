<?php

declare(strict_types=1);

namespace StarDust\Export;

use DateTimeImmutable;

/**
 * Read-side projection of one `stardust_export_jobs` row, returned by
 * {@see ExportJobSubmitter::getJob()} for consumer status polling.
 *
 * All `DateTimeImmutable` fields are in UTC. Nullable columns map to
 * `?DateTimeImmutable` / `?int` / `?string` so consumers can pattern
 * match on lifecycle stage (`status='pending'` ⇒ `claimedAt === null`,
 * `status='completed'` ⇒ `artifactPath !== null`, etc.).
 *
 * `modelId` is hoisted out of the stored envelope so consumers see it
 * as a typed first-class field. `filter` is the original consumer
 * QueryFilter as submitted — the engine's `model_id` stamping
 * convention is hidden from API consumers.
 */
final class ExportJob
{
    /**
     * @param array<string,mixed> $filter Original consumer QueryFilter
     *   payload (without the engine's `model_id` envelope).
     */
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly int $modelId,
        public readonly string $status,
        public readonly array $filter,
        public readonly string $format,
        public readonly ?int $lastCursor,
        public readonly ?string $artifactPath,
        public readonly ?string $failedReason,
        public readonly int $skipCount,
        public readonly ?string $workerIdentity,
        public readonly ?DateTimeImmutable $claimedAt,
        public readonly ?DateTimeImmutable $heartbeatAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $completedAt,
    ) {
    }
}
