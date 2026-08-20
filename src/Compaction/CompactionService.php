<?php

declare(strict_types=1);

namespace StarDust\Compaction;

use Closure;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Exception\CompactionCapacityException;
use StarDust\Retype\RetypeCheckpointRepository;
use StarDust\Retype\RetypeInitiator;
use StarDust\Support\UuidV4;

/**
 * ADR 0033 operator-initiated model compaction.
 *
 * Relocates a model's live filterable slots onto a minimal page set, as
 * a sequence of same-type retypes riding the Phase 6b pipeline
 * unmodified. **Never scheduled, never daemon-triggered, never
 * automatic** — the trigger is always an operator reading
 * `high_spread_model` and deciding this specific model is worth it.
 *
 * ## Thin by design
 *
 * The chunked backfill, `backfilling → ready` promotion, schema-version
 * bumps, the ADR 0019 cardinality sample and the ADR 0031
 * `post_relocation` spread sample are all the existing pipeline. This
 * class only decides *which* field moves *where*, initiates each move,
 * and waits. There is no compaction daemon, no work source, and no
 * compaction state table — the retype checkpoints are the durable state.
 *
 * ## Sequential, and that is the safe default
 *
 * One field in flight at a time. During a field's relocation window its
 * filters are rejected (ADR 0004 / ADR 0016) while reads fall back to
 * the JSON payload (ADR 0013), so relocating K fields at once would
 * reject filters on all K simultaneously. `--parallel=N` is described by
 * ADR 0033 as an explicit opt-in and is deliberately not implemented
 * yet; the surface stays forward-compatible.
 *
 * ## Resume is re-run
 *
 * If the caller dies mid-operation nothing is stuck: in-flight
 * checkpoints are ordinary retype checkpoints the Reconciler drains
 * regardless. Re-running replans against the new state, and fields that
 * already reached a target page are no-ops — so the operation converges
 * idempotently with no cleanup step.
 */
final class CompactionService
{
    /** @var Closure(int): void */
    private readonly Closure $sleepFn;

    /**
     * @param callable(int):void|null $sleepFn injected for tests; defaults to `usleep`
     */
    public function __construct(
        private readonly CompactionRepository $repository,
        private readonly RetypeInitiator $retypeInitiator,
        private readonly RetypeCheckpointRepository $checkpointRepository,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
        private readonly int $pollIntervalMicros = 200_000,
        private readonly int $maxPollsPerField = 3_000,
        ?callable $sleepFn = null,
    ) {
        $this->sleepFn = $sleepFn !== null
            ? Closure::fromCallable($sleepFn)
            : static fn (int $micros) => usleep($micros);
    }

    /**
     * Build the plan without touching anything.
     *
     * `--dry-run` calls this and prints the result. It emits **no
     * events**: an event describing a plan that was never committed to
     * would violate the write-then-log discipline the rest of the engine
     * holds to.
     *
     * @throws CompactionCapacityException when the model is fragmented but no
     *                                     smaller page set can absorb the moves
     */
    public function plan(int $tenantId, int $modelId): CompactionPlan
    {
        return CompactionPlanner::plan(
            tenantId: $tenantId,
            modelId: $modelId,
            modelSlots: $this->repository->loadModelSlots($tenantId, $modelId),
            freeCapacity: $this->repository->loadIndexedFreeCapacity(),
        );
    }

    /**
     * Plan and execute, one relocation at a time.
     *
     * Returns the plan that was executed so callers can report on it. A
     * plan with nothing to relocate short-circuits before emitting
     * anything — re-running on an already-compact model is free and
     * silent, which is what makes "just re-run it" safe advice.
     *
     * @throws CompactionCapacityException from planning, or mid-run if the
     *                                     registry changed under the plan
     */
    public function compact(int $tenantId, int $modelId): CompactionPlan
    {
        $plan = $this->plan($tenantId, $modelId);

        if ($plan->isNoop()) {
            return $plan;
        }

        $correlationId = UuidV4::generate();
        $startedAt = $this->clock->now()->getTimestamp();

        $this->logger->info('compaction planned', [
            'event'              => 'compaction_planned',
            'source'             => 'registry',
            'correlation_id'     => $correlationId,
            'tenant_id'          => $tenantId,
            'model_id'           => $modelId,
            'pages_before'       => $plan->pagesBefore,
            'target_pages'       => $plan->targetPageIds,
            'fields_to_relocate' => $plan->relocationCount(),
            'noop_count'         => $plan->noopCount,
        ]);

        foreach ($plan->relocations as $relocation) {
            $this->retypeInitiator->initiateRelocation(
                $tenantId,
                $relocation->fieldId,
                $relocation->toPageId,
            );

            $this->awaitRelocation($relocation);
        }

        $this->logger->info('compaction complete', [
            'event'            => 'compaction_complete',
            'source'           => 'registry',
            'correlation_id'   => $correlationId,
            'tenant_id'        => $tenantId,
            'model_id'         => $modelId,
            'fields_relocated' => $plan->relocationCount(),
            'pages_before'     => $plan->pagesBefore,
            'pages_after'      => $plan->pagesAfter(),
            'duration_seconds' => $this->clock->now()->getTimestamp() - $startedAt,
        ]);

        return $plan;
    }

    /**
     * Block until the Reconciler finishes this field, so the next
     * relocation only starts once this one's filterability is restored.
     *
     * A `failed` checkpoint aborts the run rather than marching on: the
     * remaining plan was computed against capacity this field was
     * supposed to consume, and continuing would report success on a
     * compaction that left a field behind. The operator re-runs, which
     * replans against whatever actually happened.
     *
     * The poll budget exists so a stopped Reconciler surfaces as a clear
     * error instead of an operator process that hangs until someone
     * notices — the single most likely operational cause of "compaction
     * makes no progress" per the runbook.
     */
    private function awaitRelocation(FieldRelocation $relocation): void
    {
        for ($poll = 0; $poll < $this->maxPollsPerField; $poll++) {
            $status = $this->checkpointRepository->statusForField($relocation->fieldId);

            if ($status === 'completed' || $status === null) {
                return;
            }

            if ($status === 'failed') {
                throw new CompactionCapacityException(sprintf(
                    'Relocation of field %d (%s) to page %d failed during backfill; its checkpoint'
                    . ' is marked failed. Compaction stopped rather than continuing against a plan'
                    . ' that no longer holds. Inspect the reconciler DLQ, then re-run to replan.',
                    $relocation->fieldId,
                    $relocation->fieldName,
                    $relocation->toPageId,
                ));
            }

            ($this->sleepFn)($this->pollIntervalMicros);
        }

        throw new CompactionCapacityException(sprintf(
            'Relocation of field %d (%s) to page %d did not complete within the poll budget.'
            . ' The checkpoint is durable and the Reconciler will still drain it — the usual'
            . ' cause is that no `bin/stardust reconciler` process is running. Start one and'
            . ' re-run; already-relocated fields are no-ops.',
            $relocation->fieldId,
            $relocation->fieldName,
            $relocation->toPageId,
        ));
    }
}
