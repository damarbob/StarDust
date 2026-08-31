<?php

declare(strict_types=1);

namespace StarDust\Examples;

use PDO;
use StarDust\Filter\Ast\LeafNode;
use StarDust\Read\EntryQuery;
use StarDust\StarDust;
use Throwable;

/**
 * Reads everything the lifecycle frame displays, once per tick.
 *
 * Three sources, deliberately kept distinct because the whole point of
 * the example is that they disagree with each other for a while:
 *
 *   1. **The public API** — `describeModel()` for `isFilterable` vs
 *      `isIndexed`, and a real `read()` that either returns rows or
 *      throws. This is what a consumer application can see.
 *   2. **The registry tables** — the slot's status and the backfill
 *      checkpoint's cursor. A consumer would never query these; the
 *      example does so it can show *why* the API is behaving as it is.
 *   3. **`entry_data` directly** — for the row counts that drive the
 *      progress bar. StarDust returns no `COUNT` of any kind, so there
 *      is no API-level way to get these. Every such query below is
 *      marked ESCAPE HATCH; they are the example's own illustration of
 *      that limitation, not a pattern to copy.
 */
final class LifecycleProbe
{
    public function __construct(
        private readonly StarDust $engine,
        private readonly PDO $pdo,
        private readonly int $tenantId,
        private readonly int $modelId,
        private readonly int $fieldId,
        private readonly string $fieldName,
        /** The predicate the frame runs live to answer "can I filter yet?". */
        private readonly string $probeOperator,
        private readonly mixed $probeValue,
    ) {
    }

    public function snapshot(): LifecycleSnapshot
    {
        $slot       = $this->slot();
        $checkpoint = $this->checkpoint();
        $bounds     = $this->entryIdBounds();

        [$probeRows, $probeError, $probeMessage] = $this->probeFilter();

        $description = $this->engine->describeModel($this->tenantId, $this->modelId);
        $field       = $description?->field($this->fieldName);

        return new LifecycleSnapshot(
            fieldName:        $this->fieldName,
            declaredType:     $field?->declaredType ?? '?',
            isFilterable:     $field?->isFilterable ?? false,
            isIndexed:        $field?->isIndexed ?? false,
            slotPageTable:    $slot['table_name'] ?? null,
            slotColumn:       $slot['slot_column'] ?? null,
            slotStatus:       $slot['status'] ?? null,
            checkpointStatus: $checkpoint['status'] ?? null,
            cursor:           (int) ($checkpoint['last_processed_id'] ?? 0),
            minEntryId:       $bounds[0],
            maxEntryId:       $bounds[1],
            totalRows:        $bounds[2],
            slotValuesWritten: $this->countSlotValues($slot),
            probeRows:        $probeRows,
            probeError:       $probeError,
            probeMessage:     $probeMessage,
        );
    }

    /**
     * The field's slot, whatever state it is in.
     *
     * Not restricted to live statuses on purpose — a `tombstoned` slot
     * has `field_id = NULL` and so drops out of this join by itself,
     * which is exactly the disappearance the frame should show.
     *
     * @return array<string, mixed>|null
     */
    private function slot(): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.status, a.slot_column, p.table_name'
            . ' FROM stardust_slot_assignments a'
            . ' JOIN stardust_pages p ON p.id = a.page_id'
            . ' WHERE a.field_id = ?'
            . ' LIMIT 1'
        );
        $stmt->execute([$this->fieldId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * The Reconciler's checkpoint row for this field's promotion.
     *
     * `retype_field_{id}` is the job-name convention a promotion and a
     * retype share — a promotion is the `newIsFilterable: true` shape of
     * the same lifecycle, so it lands in the same namespace.
     *
     * @return array<string, mixed>|null
     */
    private function checkpoint(): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT status, last_processed_id, updated_at'
            . ' FROM backfill_checkpoints WHERE job_name = ?'
        );
        $stmt->execute(["retype_field_{$this->fieldId}"]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * ESCAPE HATCH — `MIN(id)`, `MAX(id)`, `COUNT(*)` over the model's
     * partition, for the progress bar's denominator.
     *
     * The backfill checkpoint records a cursor *position*, not a
     * percentage, so turning it into a bar needs the id range it is
     * travelling. StarDust exposes no aggregate of any kind, so this is
     * raw SQL against `entry_data` by necessity.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private function entryIdBounds(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT MIN(id) AS lo, MAX(id) AS hi, COUNT(*) AS n FROM entry_data'
            . ' WHERE tenant_id = ? AND model_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$this->tenantId, $this->modelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row)
            ? [(int) ($row['lo'] ?? 0), (int) ($row['hi'] ?? 0), (int) ($row['n'] ?? 0)]
            : [0, 0, 0];
    }

    /**
     * ESCAPE HATCH — how many rows actually carry a value in the slot
     * column yet. This is the backfill's output measured directly, and
     * the one number that proves the copy is really happening rather
     * than the cursor merely moving.
     *
     * @param array<string, mixed>|null $slot
     */
    private function countSlotValues(?array $slot): ?int
    {
        if ($slot === null) {
            return null;
        }

        $table  = (string) $slot['table_name'];
        $column = (string) $slot['slot_column'];

        // Both come from the registry, whose universe is the generated
        // `entry_slots_page_N` / `i_{str|int|num|dt}_NN` names, so the
        // interpolation is safe. Guard anyway — this is example code and
        // someone will copy it.
        if (preg_match('/^entry_slots_page_\d+$/', $table) !== 1
            || preg_match('/^i_(str|int|num|dt)_\d{2}$/', $column) !== 1
        ) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE tenant_id = ? AND {$column} IS NOT NULL"
        );
        $stmt->execute([$this->tenantId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Run the real filter and report what a consumer would get.
     *
     * This is the heart of the example: the same call, unchanged, moves
     * from throwing to returning as the daemons do their work.
     *
     * @return array{0: int|null, 1: string|null, 2: string|null}
     */
    private function probeFilter(): array
    {
        try {
            $page = $this->engine->read(new EntryQuery(
                tenantId:     $this->tenantId,
                modelId:      $this->modelId,
                filter:       LeafNode::local($this->fieldName, $this->probeOperator, $this->probeValue),
                selectFields: [$this->fieldName],
                pageSize:     25,
            ));

            return [\count($page->rows), null, null];
        } catch (Throwable $e) {
            $class = $e::class;
            $short = substr($class, (int) strrpos($class, '\\') + 1);

            return [null, $short, $e->getMessage()];
        }
    }
}
