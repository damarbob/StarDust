<?php

declare(strict_types=1);

namespace StarDust\Compaction;

use Closure;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Exception\CompactionCapacityException;
use StarDust\Exception\ModelDeletionInProgressException;
use StarDust\Exception\RetypeInProgressException;
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
 * ## Resume is re-run, once the window closes
 *
 * If the caller dies mid-operation nothing is stuck: in-flight
 * checkpoints are ordinary retype checkpoints the Reconciler drains
 * regardless. Re-running replans against the new state, and fields that
 * already reached a target page are no-ops — so the operation converges
 * idempotently with no cleanup step.
 *
 * **ADR 0039 narrows this: the re-run is not immediate.** A re-run
 * issued while the abandoned relocation is still draining is refused
 * rather than served a plan computed without it — see {@see self::plan()}.
 * Convergence and no-stuck-state both survive; what the operator loses
 * is the ability to re-run *inside* the drain window, and what they gain
 * is that the numbers compaction reports agree with the ADR 0031 spread
 * sample that verifies them.
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
     * @throws CompactionCapacityException    when the model is fragmented but no
     *                                        smaller page set can absorb the moves
     * @throws ModelDeletionInProgressException when the model is being deleted
     * @throws RetypeInProgressException      when a field of the model is mid-lifecycle,
     *                                        so the slot population is known-incomplete
     */
    public function plan(int $tenantId, int $modelId): CompactionPlan
    {
        // ADR 0038. Guarding `plan()` rather than `compact()` covers both,
        // including `compactModel(dryRun: true)` — which is the surface
        // that actually matters here, because without this it prints a
        // *successful empty plan* for a model being destroyed.
        //
        // `CompactionRepository::loadModelSlots()` selects on
        // `status IN ('assigned','ready') AND is_filterable = 1`, and a
        // severed field fails both, so the planner genuinely cannot see
        // the model's slots — "nothing to compact" and "this model is
        // being erased" would be reported identically.
        if ($this->repository->modelIsDeleting($modelId)) {
            throw new ModelDeletionInProgressException(sprintf(
                'Model %d is being deleted; it cannot be compacted.'
                . ' Run a reconciler to finish the purge.',
                $modelId,
            ));
        }

        // ADR 0039, and it sits here for the reason spelled out directly
        // above: guarding `plan()` covers `compactModel(dryRun: true)`
        // too, which is the surface that matters. A dry run reporting
        // `pages_after: 1` when the true answer is 2 is the whole defect.
        //
        // A field mid-relocation holds a `tombstoned` old slot and a
        // `backfilling` new one, and `loadModelSlots()` counts neither —
        // deliberately, since that population is ADR 0031's. So the
        // planner cannot see where such a field is going to land, and a
        // plan built without it reports a `pages_after` the ADR 0031
        // spread sample then contradicts. Refusing keeps the operation
        // and the metric that verifies it measuring one thing.
        //
        // Two things this guard is NOT:
        //
        // - It is not compaction-specific. An ordinary `retypeField()`
        //   or `promoteFieldToFilterable()` on any field of the model
        //   trips it, and that is correct — the planner is exactly as
        //   blind to those as it is to a relocation.
        // - It is not a lock. Two operators can still both get past this
        //   in the window between one's `plan()` and its first
        //   `initiateRelocation()`; `RetypeInitiator`'s per-field
        //   `RetypeInProgressException` catches the real collision.
        //   Closing that window needs a lock and is out of scope.
        if ($this->checkpointRepository->existsRunningForAnyFieldOfModel($modelId)) {
            throw new RetypeInProgressException(sprintf(
                'Model %d has a field with a retype, promotion, demotion or relocation'
                . ' still in flight, so its slot layout cannot be planned accurately yet.'
                . ' The checkpoint is durable and a running `bin/stardust reconciler` will'
                . ' drain it; wait for that and re-run. `spread:report` is safe meanwhile.',
                $modelId,
            ));
        }

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
            // The operation id goes down with each relocation, so the
            // `retype_started` and `slot_reserved` it produces join the
            // `compaction_planned` that ordered them rather than reading
            // as unrelated retypes.
            $this->retypeInitiator->initiateRelocation(
                $tenantId,
                $relocation->fieldId,
                $relocation->toPageId,
                $correlationId,
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
     * **There is no `failed` branch, because a retype checkpoint cannot
     * reach `failed`.** One used to sit here, aborting the run and
     * telling the operator to inspect the reconciler DLQ — advice for a
     * state nothing in `src/` could produce, since `markFailed()` had no
     * caller anywhere. It was removed rather than wired up: a lock
     * failure, the only realistic way a relocation stalls, is transient
     * contention that the work source now retries and then defers with
     * `TickOutcome::LOCK_WAIT`, leaving the checkpoint `running` and
     * drainable. Failing it would turn a self-clearing condition into
     * one needing operator action.
     *
     * A relocation that genuinely stops making progress therefore
     * surfaces through the poll budget below, which is the same signal
     * as a Reconciler that is not running — and in both cases the
     * remedy is identical.
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
