<?php

declare(strict_types=1);

namespace StarDust\Watcher;

use PDO;
use Psr\Log\LoggerInterface;
use StarDust\Support\UuidV4;

/**
 * ADR 0031 slot-spread advisory.
 *
 * Measures, per `(tenant_id, model_id)`, how many distinct extension
 * pages the model's live filterable slots occupy, and how many of those
 * pages are avoidable. Emits `spread_sampled` on every sample and
 * `high_spread_model` when fragmentation has compounded past the
 * configured threshold.
 *
 * **Nothing here blocks, rejects, slows, or remediates anything.** The
 * posture is identical to ADR 0019's cardinality advisory: measure,
 * surface, let the operator decide. The cure (ADR 0033 compaction) is a
 * mass-I/O migration with a transient de-indexing window and is never
 * triggered automatically.
 *
 * ## Registry-only, and that is the point
 *
 * The sample never touches `entry_data` or an extension page — unlike
 * {@see CardinalitySampler}, which scans the tenant partition. Spread is
 * a pure registry concept (which model's slots sit on which pages), so
 * the whole measurement is one bounded join over three small tables.
 * That is what makes it safe to run over every model, every day,
 * indefinitely. Keep it that way.
 *
 * ## The join is not the one ADR 0031 prints
 *
 * ADR 0031 §Sampling Method filters `WHERE sa.tenant_id = :tenant_id`,
 * but `stardust_slot_assignments` **has no `tenant_id` column**. Tenancy
 * reaches a slot only through `field_id → stardust_fields.model_id →
 * stardust_models.tenant_id`, which is the join used below and the one
 * the operator runbook (`maintaining_low_spread.md` §3.3) already
 * documents. The ADR's SQL is an editorial error, not a different
 * design; do not "restore" it.
 *
 * ## Two predicates that look redundant and are not
 *
 * - `sa.status IN ('assigned','ready')` is the *liveness* discriminator,
 *   which is why the emitted field is `live_slot_count` and NOT
 *   `filterable_slot_count`. A `backfilling` slot belongs to a
 *   filterable field but services no query, so it contributes no join
 *   cost and must not be counted. Same for `tombstoned`.
 * - `f.is_filterable = 1` survives ADR 0034 even though that ADR makes
 *   non-filterable fields JSON-only and never slot-resident. Pre-0034
 *   databases can still hold grandfathered live slots on non-filterable
 *   fields, which ADR 0034 §5 declines to migrate, so the predicate is
 *   correct rather than dead.
 */
final class SpreadSampler
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LoggerInterface $logger,
        private readonly int $excessPageThreshold,
    ) {
    }

    /**
     * ADR 0031 trigger 1 — periodic.
     *
     * Every model of every tenant, on the Watcher's existing jittered
     * daily cadence. It deliberately shares {@see CardinalitySampler}'s
     * timer rather than owning one: a second schedule would be a second
     * stampede surface for no benefit, since spread drifts only on
     * registry mutation.
     */
    public function sampleAll(): void
    {
        $this->emitAll($this->collect(null, null), 'periodic');
    }

    /**
     * ADR 0031 trigger 2 — post-relocation one-shot.
     *
     * Called after a pipeline that relocates a slot commits, so the
     * spread delta the relocation caused is visible immediately instead
     * of up to a day later. For ADR 0033 compaction this is also the
     * built-in success check: `excess_pages` should have dropped.
     */
    public function sampleModel(int $tenantId, int $modelId): void
    {
        $this->emitAll($this->collect($tenantId, $modelId), 'post_relocation');
    }

    /**
     * ADR 0031 trigger 3 — on demand, for operator triage outside the
     * daily window. Backs `bin/stardust spread:report`.
     *
     * Emits the same events as the other triggers *and* returns the
     * samples, so the CLI can print a human-readable table without
     * running the query twice.
     *
     * @return list<SpreadSample>
     */
    public function report(?int $tenantId = null, ?int $modelId = null): array
    {
        $samples = $this->collect($tenantId, $modelId);
        $this->emitAll($samples, 'on_demand');

        return $samples;
    }

    /**
     * One bounded registry query, folded per `(tenant_id, model_id)`.
     *
     * A model with no live filterable slot produces no row and therefore
     * no sample — correct, since a model with nothing slotted has no
     * spread to report.
     *
     * @return list<SpreadSample>
     */
    private function collect(?int $tenantId, ?int $modelId): array
    {
        $sql = 'SELECT m.tenant_id, f.model_id, sa.page_id, sa.slot_column'
            . ' FROM stardust_slot_assignments sa'
            . ' JOIN stardust_fields f ON f.id = sa.field_id'
            . ' JOIN stardust_models m ON m.id = f.model_id'
            . " WHERE sa.status IN ('assigned','ready') AND f.is_filterable = 1";

        $params = [];
        if ($tenantId !== null) {
            $sql .= ' AND m.tenant_id = ?';
            $params[] = $tenantId;
        }
        if ($modelId !== null) {
            $sql .= ' AND f.model_id = ?';
            $params[] = $modelId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        /** @var array<string, array{tenantId: int, modelId: int, pageIds: list<int>, slotColumns: list<string>}> $grouped */
        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rowTenantId = (int) $row['tenant_id'];
            $rowModelId  = (int) $row['model_id'];
            $key = $rowTenantId . ':' . $rowModelId;

            $grouped[$key] ??= [
                'tenantId'    => $rowTenantId,
                'modelId'     => $rowModelId,
                'pageIds'     => [],
                'slotColumns' => [],
            ];
            $grouped[$key]['pageIds'][]     = (int) $row['page_id'];
            $grouped[$key]['slotColumns'][] = (string) $row['slot_column'];
        }

        $samples = [];
        foreach ($grouped as $group) {
            $samples[] = SpreadSample::fromLiveSlots(
                tenantId: $group['tenantId'],
                modelId: $group['modelId'],
                pageIds: $group['pageIds'],
                slotColumns: $group['slotColumns'],
            );
        }

        return $samples;
    }

    /**
     * One correlation id per sampling run, so every model measured in the
     * same sweep is tied together in the event stream.
     *
     * @param list<SpreadSample> $samples
     */
    private function emitAll(array $samples, string $trigger): void
    {
        $correlationId = UuidV4::generate();

        foreach ($samples as $sample) {
            $this->emit($sample, $trigger, $correlationId);
        }
    }

    private function emit(SpreadSample $sample, string $trigger, string $correlationId): void
    {
        $base = [
            'source'                => 'registry',
            'correlation_id'        => $correlationId,
            'tenant_id'             => $sample->tenantId,
            'model_id'              => $sample->modelId,
            'pages_occupied'        => $sample->pagesOccupied,
            'theoretical_min_pages' => $sample->theoreticalMinPages,
            'excess_pages'          => $sample->excessPages(),
            'live_slot_count'       => $sample->liveSlotCount,
            'trigger'               => $trigger,
        ];

        $this->logger->info('spread sampled', $base + ['event' => 'spread_sampled']);

        // Both bounds, per ADR 0031. The `pages_occupied >= 2` half is
        // what stops a model that legitimately fits on one page from
        // ever being flagged; the threshold half is what stops a single
        // avoidable join — rarely worth a compaction — from alerting.
        if ($sample->pagesOccupied >= 2 && $sample->excessPages() >= $this->excessPageThreshold) {
            $this->logger->warning('high spread model', $base + [
                'event'     => 'high_spread_model',
                'threshold' => $this->excessPageThreshold,
            ]);
        }
    }
}
