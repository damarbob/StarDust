<?php

declare(strict_types=1);

namespace StarDust\Watcher;

use PDO;
use Psr\Log\LoggerInterface;
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

    public function sample(): void
    {
        $correlationId = UuidV4::generate();

        $slots = $this->pdo->query(
            "SELECT a.id AS slot_assignment_id, a.field_id, a.page_id, a.slot_column,"
            . ' p.table_name'
            . ' FROM stardust_slot_assignments a'
            . ' JOIN stardust_pages p ON p.id = a.page_id'
            . " WHERE a.status IN ('assigned','ready')"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($slots as $slot) {
            $this->sampleOneSlot($slot, $correlationId);
        }
    }

    /**
     * @param array{slot_assignment_id: int|string, field_id: int|string|null,
     *              page_id: int|string, slot_column: string, table_name: string} $slot
     */
    private function sampleOneSlot(array $slot, string $correlationId): void
    {
        $tableName = (string) $slot['table_name'];
        $slotColumn = (string) $slot['slot_column'];

        // Sample per-tenant per ADR 0019 — a slot's cardinality is
        // tenant-scoped because the composite index it's read through is
        // `(tenant_id, slot_column)`.
        $tenants = $this->pdo->query(
            "SELECT DISTINCT tenant_id FROM {$tableName}"
        )->fetchAll(PDO::FETCH_COLUMN);

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
                'trigger'            => 'periodic',
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
        }
    }
}
