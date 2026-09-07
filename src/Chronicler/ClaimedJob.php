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
        /**
         * The submitting call's id, from
         * `stardust_export_jobs.correlation_id`.
         *
         * `job_claimed` / `job_complete` / `job_failed` are per-*job*
         * events, so under ADR 0020 their operation is the submission
         * and they carry this directly rather than through a companion
         * field. Null for a job submitted before the column existed, in
         * which case the worker mints one and behaves as before.
         */
        public readonly ?string $correlationId = null,
    ) {
    }
}
