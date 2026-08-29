<?php

declare(strict_types=1);

namespace StarDust\Write;

use DateTimeImmutable;

/**
 * Read-side projection of one `stardust_import_jobs` row, returned by
 * {@see BulkIngestSubmitter::getJob()} for consumer status polling —
 * the import-side counterpart of {@see \StarDust\Export\ExportJob}.
 *
 * All `DateTimeImmutable` fields are in UTC.
 *
 * `chunks`, `entriesWritten` and `chunkManifest` are hoisted out of
 * the stored `manifest` JSON so consumers see typed fields rather than
 * an untyped array. The manifest is written only by the Reconciler's
 * import work source, in a closed shape a consumer never supplies, so
 * — unlike an export's consumer-supplied `filter` — there is nothing
 * to preserve verbatim. `entriesWritten` against `entryCount` is the
 * progress fraction; `chunkManifest` is the per-chunk enumeration ADR
 * 0011 §26 requires, and is the async counterpart of the
 * {@see BulkChunkResult} list a synchronous `bulkWrite()` returns.
 *
 * Three things differ from the export DTO in ways that break an
 * intuition carried over from it:
 *
 *  - **`chunks` / `entriesWritten` are null until the first chunk
 *    commits**, and a `malformed_json` failure fails before any chunk
 *    runs. So `null` ("nothing was committed") is a real state,
 *    distinct from `0` ("started, wrote nothing"). On a `failed` job a
 *    non-null `entriesWritten` is the replay boundary: the count of
 *    entries already durably written, which the manifest retains
 *    because it is checkpointed inside each chunk transaction and the
 *    failure path never overwrites it.
 *  - **`artifactPath` is NOT NULL**, unlike an export's, so the export
 *    idiom `status === 'completed'` ⇒ `artifactPath !== null` does not
 *    hold here — the path is present from submission and signals
 *    nothing about lifecycle stage. It is also the stored value
 *    verbatim, which may be absolute or relative depending on who
 *    wrote the row; resolving it against `Config::$artifactDir` is the
 *    Reconciler's job, not this DTO's.
 *  - **`heartbeatAt` is stamped on the terminal transition too**, not
 *    only while a worker holds the lease, so it is non-null on every
 *    completed and failed job.
 */
final class ImportJob
{
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        /** `pending` | `processing` | `completed` | `failed`. */
        public readonly string $status,
        public readonly ?string $idempotencyKey,
        public readonly string $artifactPath,
        /** Entries in the submitted batch. */
        public readonly int $entryCount,
        /** Chunks committed so far; null before the first commits. */
        public readonly ?int $chunks,
        /** Entries durably written; null before the first chunk commits. */
        public readonly ?int $entriesWritten,
        /** `malformed_json` | `entry_write_failed`; null unless failed. */
        public readonly ?string $failedReason,
        public readonly ?string $workerIdentity,
        public readonly ?DateTimeImmutable $claimedAt,
        public readonly ?DateTimeImmutable $heartbeatAt,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?DateTimeImmutable $completedAt,
        /**
         * Per-chunk records (ADR 0011 §26 / ADR 0040), oldest first.
         * Empty until the first chunk commits, and empty for a job that
         * resumed across the ADR 0040 upgrade whose prior worker wrote
         * only the counters.
         *
         * @var list<ImportChunkRecord>
         */
        public readonly array $chunkManifest = [],
    ) {
    }
}
