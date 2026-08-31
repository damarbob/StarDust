<?php

declare(strict_types=1);

namespace StarDust\Examples;

/**
 * One tick's observation of a field mid-promotion.
 *
 * Immutable and dumb on purpose: {@see LifecycleProbe} gathers it and
 * {@see LifecycleView} renders it, so the frame can never accidentally
 * re-query mid-draw and show two different moments in one picture.
 */
final class LifecycleSnapshot
{
    public function __construct(
        public readonly string $fieldName,
        public readonly string $declaredType,
        /** The registry's declared intent — what you asked for. */
        public readonly bool $isFilterable,
        /** Whether a filter works right now — what you actually get. */
        public readonly bool $isIndexed,
        public readonly ?string $slotPageTable,
        public readonly ?string $slotColumn,
        public readonly ?string $slotStatus,
        public readonly ?string $checkpointStatus,
        public readonly int $cursor,
        public readonly int $minEntryId,
        public readonly int $maxEntryId,
        public readonly int $totalRows,
        public readonly ?int $slotValuesWritten,
        /** Rows on the probe filter's first page, or null when it threw. */
        public readonly ?int $probeRows,
        public readonly ?string $probeError,
        public readonly ?string $probeMessage,
    ) {
    }

    /**
     * How far the backfill cursor has travelled, in [0, 1].
     *
     * Measured over the model's id range rather than its row count: the
     * cursor is an `entry_data.id`, and ids are not dense once anything
     * else has written to the table.
     */
    public function progress(): float
    {
        if ($this->checkpointStatus === 'completed') {
            return 1.0;
        }
        if ($this->cursor <= 0 || $this->maxEntryId <= $this->minEntryId) {
            return 0.0;
        }

        $span = $this->maxEntryId - $this->minEntryId + 1;

        return max(0.0, min(1.0, ($this->cursor - $this->minEntryId + 1) / $span));
    }

    /**
     * A short label for where in `free → assigned → backfilling → ready`
     * this field's slot currently sits, or null when it holds none.
     */
    public function slotStage(): ?string
    {
        return $this->slotStatus;
    }

    /** True once a filter against the field returns instead of throwing. */
    public function filterWorks(): bool
    {
        return $this->probeError === null;
    }

    /**
     * A stable fingerprint of everything the frame considers a *change*.
     *
     * The append-mode fallback prints a line only when this differs from
     * the previous tick, which is what keeps piped output readable
     * instead of one frame per poll.
     */
    public function fingerprint(): string
    {
        return implode('|', [
            $this->isFilterable ? '1' : '0',
            $this->isIndexed ? '1' : '0',
            $this->slotStatus ?? '-',
            $this->slotColumn ?? '-',
            $this->checkpointStatus ?? '-',
            $this->probeError ?? 'ok',
        ]);
    }
}
