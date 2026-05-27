<?php

declare(strict_types=1);

namespace StarDust\Chronicler;

/**
 * Immutable view of one `stardust_export_jobs` row that the Chronicler
 * has just transitioned into `processing` for this worker.
 *
 * The `lastCursor` is non-null on an abandoned-claim resumption (the
 * previous worker had committed at least one chunk before its lease
 * expired); fresh pending claims see it as `null` and the processor
 * treats null as `0` (`WHERE id > 0`).
 *
 * `skipCount` is the cumulative skip charge persisted by the previous
 * worker (zero on a fresh pending claim). Per the design plan, the
 * processor MUST start charging from this value rather than reset to
 * zero — otherwise a dying worker could let a re-claimer charge another
 * full cap before tripping `excessive_skips`.
 */
final class ClaimedJob
{
    /**
     * @param array<string,mixed> $filter Decoded `filter` column;
     *   Phase 7 MVP ignores filter contents beyond (tenant_id, model_id).
     */
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly int $modelId,
        public readonly string $format,
        public readonly array $filter,
        public readonly ?int $lastCursor,
        public readonly string $workerIdentity,
        public readonly ClaimKind $claimKind,
        public readonly int $skipCount,
    ) {
    }
}
