<?php

declare(strict_types=1);

namespace StarDust\Watcher;

use PDO;
use Psr\Log\LoggerInterface;
use StarDust\Support\PdoQuery;
use StarDust\Support\UuidV4;

/**
 * ADR 0019 cardinality advisory.
 *
 * The Watcher schedules `sample()` on a configurable cadence (default
 * 24 h, jittered ±10 %). Each invocation samples every live slot
 * (`status IN ('assigned','ready')`) across every tenant present on
 * that slot's page, runs the normative aggregate, and emits a
 * `cardinality_sampled` event. When either selectivity or distinct
 * thresholds are violated, a paired `low_cardinality_index` event
 * fires.
 *
 * Both events carry `source: 'registry'` per ADR 0020 §Event
 * Vocabulary — the Watcher merely owns the schedule; the events
 * describe registry-level state.
 *
 * Three triggers, one method each, mirroring {@see SpreadSampler}:
 * {@see self::sample()} (`periodic`), {@see self::sampleSlot()}
 * (`post_backfill`), and {@see self::report()} (`on_demand`, the only
 * one that returns its samples, so `bin/stardust cardinality:report`
 * can print them).
 *
 * **Unlike `SpreadSampler` this is not registry-only.** It reads
 * `COUNT(*)` and `COUNT(DISTINCT col)` off the extension pages
 * themselves, so it does not inherit the spread advisory's "safe
 * against production at any time" property and the CLI help says so.
 */
final class CardinalitySampler
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LoggerInterface $logger,
        private readonly float $selectivityThreshold,
        private readonly int $rowFloor,
        private readonly int $distinctFloor,
    ) {
    }

    /**
     * ADR 0019 trigger 1 — the periodic scan, on the Watcher's jittered
     * daily cadence.
     *
     * `$correlationId` is the Watcher cycle this sweep runs inside, so
     * every `cardinality_sampled` it emits joins that tick's
     * `poll_started` / `poll_complete`. Null mints one, for a caller
     * that is its own operation boundary.
     */
    public function sample(?string $correlationId = null): void
    {
        $this->collect(null, null, 'periodic', $correlationId ?? UuidV4::generate());
    }

    /**
     * ADR 0019 trigger 3 — on demand, for operator triage outside the
     * daily window. Backs `bin/stardust cardinality:report`.
     *
     * Emits the same `cardinality_sampled` / `low_cardinality_index`
     * pair as the other triggers (with `trigger='on_demand'`) *and*
     * returns the samples, so the CLI can print a table without running
     * the aggregates twice — the same contract as
     * {@see SpreadSampler::report()}.
     *
     * **`$modelId` selects which *slots* to sample, not which *rows* to
     * count.** ADR 0019's aggregate is per `(tenant, slot)` over the
     * whole page table, because the composite index it describes is
     * `(tenant_id, slot_column)` and a page is shared by every model
     * with a slot on it. Filtering by model narrows the slots examined;
     * each one's row count still covers the tenant's whole partition on
     * that page.
     *
     * Unlike `spread:report` this is **not** registry-only: it runs
     * `COUNT(*)` and `COUNT(DISTINCT col)` over every matching extension
     * page. Read-only, but not free.
     *
     * @return list<CardinalitySample>
     */
    public function report(?int $tenantId = null, ?int $modelId = null): array
    {
        return $this->collect($tenantId, $modelId, 'on_demand', UuidV4::generate());
    }

    /**
     * The live-slot scan shared by {@see self::sample()} and
     * {@see self::report()}.
     *
     * The join to `stardust_fields` is a LEFT join and must stay one: a
     * tombstoned or grandfathered slot can carry `field_id = NULL`, and
     * an INNER join would silently drop rows from the unfiltered scan
     * that the periodic trigger has always covered.
     *
     * @return list<CardinalitySample>
     */
    private function collect(?int $tenantId, ?int $modelId, string $trigger, string $correlationId): array
    {
        $sql = 'SELECT a.id AS slot_assignment_id, a.field_id, a.page_id, a.slot_column,'
            . ' p.table_name'
            . ' FROM stardust_slot_assignments a'
            . ' JOIN stardust_pages p ON p.id = a.page_id'
            . ' LEFT JOIN stardust_fields f ON f.id = a.field_id'
            . " WHERE a.status IN ('assigned','ready')";

        $params = [];
        if ($modelId !== null) {
            $sql .= ' AND f.model_id = ?';
            $params[] = $modelId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $samples = [];
        foreach ($slots as $slot) {
            foreach ($this->sampleSlotRow($slot, $correlationId, $trigger, $tenantId) as $sample) {
                $samples[] = $sample;
            }
        }

        return $samples;
    }

    /**
     * Phase 6b post-backfill trigger (ADR 0019 §Sampling Triggers §1).
     *
     * Called by the retype work source after it advances a slot
     * `backfilling → ready`. Emits `cardinality_sampled` (always) and
     * `low_cardinality_index` (if the policy threshold is crossed)
     * with `trigger='post_backfill'` so operators can distinguish
     * post-promotion baselines from the periodic 24-h scan.
     *
     * The slot is looked up by id; the row may be in `assigned` or
     * `ready` status at sample time. Pre-promotion `backfilling`
     * rows are intentionally not sampled — they have incomplete data
     * and would skew the baseline.
     *
     * `$correlationId` is the promotion's id — the same one
     * `promote_to_ready` carries. This sample exists *because* that
     * promotion happened and reports the state it produced, so under
     * ADR 0020 it is a sub-event of the retype rather than an operation
     * of its own.
     */
    public function sampleSlot(int $slotAssignmentId, ?string $correlationId = null): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id AS slot_assignment_id, a.field_id, a.page_id, a.slot_column,'
            . ' p.table_name'
            . ' FROM stardust_slot_assignments a'
            . ' JOIN stardust_pages p ON p.id = a.page_id'
            . " WHERE a.id = ? AND a.status IN ('assigned','ready')"
        );
        $stmt->execute([$slotAssignmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return;
        }
        $this->sampleSlotRow($row, $correlationId ?? UuidV4::generate(), 'post_backfill');
    }

    /**
     * `[$tenantId]` when that tenant has at least one row on the page,
     * `[]` otherwise — the filtered counterpart of the `SELECT DISTINCT`
     * above, and deliberately a confirmation rather than an assumption.
     *
     * `tenant_id` is the leading column of every slot's composite index,
     * so this is an indexed point lookup: the filter still avoids the
     * full DISTINCT scan it replaces.
     *
     * @return list<int>
     */
    private function tenantsPresentOn(string $tableName, int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT tenant_id FROM {$tableName} WHERE tenant_id = ? LIMIT 1"
        );
        $stmt->execute([$tenantId]);

        return $stmt->fetchColumn() === false ? [] : [$tenantId];
    }

    /**
     * @param array{slot_assignment_id: int|string, field_id: int|string|null,
     *              page_id: int|string, slot_column: string, table_name: string} $slot
     * @return list<CardinalitySample>
     */
    private function sampleSlotRow(
        array $slot,
        string $correlationId,
        string $trigger,
        ?int $onlyTenantId = null,
    ): array {
        $tableName = (string) $slot['table_name'];
        $slotColumn = (string) $slot['slot_column'];

        // Sample per-tenant per ADR 0019 — a slot's cardinality is
        // tenant-scoped because the composite index it's read through is
        // `(tenant_id, slot_column)`.
        //
        // A tenant filter narrows to a point lookup rather than scanning
        // the whole DISTINCT set and discarding the rest — but it still
        // has to CONFIRM the tenant is present on this page, not assume
        // it. Taking `[$onlyTenantId]` on trust invents a sample: a
        // tenant with no rows here reports `row_count: 0`,
        // `distinct_values: 0`, and then trips
        // `low_cardinality_index` on the distinct floor, warning about
        // an index that holds none of that tenant's data. Measured —
        // `report(999)` emitted exactly that.
        $tenants = $onlyTenantId !== null
            ? $this->tenantsPresentOn($tableName, $onlyTenantId)
            : PdoQuery::run($this->pdo, "SELECT DISTINCT tenant_id FROM {$tableName}")
                ->fetchAll(PDO::FETCH_COLUMN);

        $samples = [];
        foreach ($tenants as $tenantIdRaw) {
            $tenantId = (int) $tenantIdRaw;
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) AS row_count,"
                . " COUNT(DISTINCT {$slotColumn}) AS distinct_values"
                . " FROM {$tableName}"
                . ' WHERE tenant_id = ?'
            );
            $stmt->execute([$tenantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $rowCount       = (int) $row['row_count'];
            $distinctValues = (int) $row['distinct_values'];
            $selectivity = $rowCount > 0 ? round($distinctValues / $rowCount, 4) : 0.0;

            $base = [
                'source'             => 'registry',
                'tenant_id'          => $tenantId,
                'correlation_id'     => $correlationId,
                'slot_assignment_id' => (int) $slot['slot_assignment_id'],
                'field_id'           => $slot['field_id'] === null ? null : (int) $slot['field_id'],
                'page_id'            => (int) $slot['page_id'],
                'slot_column'        => $slotColumn,
                'row_count'          => $rowCount,
                'distinct_values'    => $distinctValues,
                'selectivity'        => $selectivity,
                'trigger'            => $trigger,
            ];

            $this->logger->info('cardinality sampled', $base + ['event' => 'cardinality_sampled']);

            $violations = [];
            if ($selectivity < $this->selectivityThreshold && $rowCount >= $this->rowFloor) {
                $violations[] = 'selectivity';
            }
            if ($distinctValues < $this->distinctFloor) {
                $violations[] = 'distinct_floor';
            }
            if ($violations !== []) {
                $this->logger->warning('low cardinality index', $base + [
                    'event'              => 'low_cardinality_index',
                    'threshold_violated' => implode(',', $violations),
                ]);
            }

            $samples[] = new CardinalitySample(
                slotAssignmentId: (int) $slot['slot_assignment_id'],
                fieldId: $slot['field_id'] === null ? null : (int) $slot['field_id'],
                tenantId: $tenantId,
                pageId: (int) $slot['page_id'],
                slotColumn: $slotColumn,
                rowCount: $rowCount,
                distinctValues: $distinctValues,
                selectivity: $selectivity,
            );
        }

        return $samples;
    }
}
