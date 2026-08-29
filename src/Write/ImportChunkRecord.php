<?php

declare(strict_types=1);

namespace StarDust\Write;

/**
 * One chunk's durable outcome inside an import job's manifest — the
 * asynchronous counterpart of {@see BulkChunkResult}, which ADR 0011
 * §26 requires the job record to carry for polling.
 *
 * **Deliberately not a reuse of `BulkChunkResult`.** That DTO's
 * `entryIds` is the chunk's full id list, which is right for a
 * synchronous call returning in-process. This manifest is re-encoded
 * into the job row inside *every* chunk transaction, so a full list
 * would put a payload proportional to the whole import into the
 * transaction ADR 0011 exists to keep short. §26 asks for "the entity
 * ID range", and a range is what this stores — matching the
 * `entry_id_first` / `entry_id_last` pair `BulkIngestor` already logs.
 *
 * **`outcome` is `committed` or `failed`, never `rolled_back`** —
 * see ADR 0040. On the synchronous path a rolled-back chunk is skipped
 * and the batch continues, so all three outcomes occur. On the
 * asynchronous path the first failing chunk fails the whole job, so
 * the sequence is always N committed records followed by at most one
 * terminal `failed` one.
 *
 * `entryIdFirst` / `entryIdLast` are null on a `failed` record: the
 * chunk's transaction rolled back, so it owns no `entry_data` rows and
 * the ids it would have taken were never durable.
 */
final class ImportChunkRecord
{
    public const OUTCOME_COMMITTED = 'committed';
    public const OUTCOME_FAILED    = 'failed';

    public function __construct(
        /** 1-based, and continuous across an abandoned-claim resume. */
        public readonly int $index,
        /** Entries in this chunk; the final chunk is usually short. */
        public readonly int $size,
        /** `committed` | `failed`. */
        public readonly string $outcome,
        /** First `entry_data.id` written; null on a failed chunk. */
        public readonly ?int $entryIdFirst,
        /** Last `entry_data.id` written; null on a failed chunk. */
        public readonly ?int $entryIdLast,
        /** `entry_write_failed`; null on a committed chunk. */
        public readonly ?string $failureReason = null,
    ) {
    }
}
