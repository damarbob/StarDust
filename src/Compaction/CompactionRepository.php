<?php

declare(strict_types=1);

namespace StarDust\Compaction;

use PDO;
use StarDust\Slot\IndexedFreeCapacityReader;
use StarDust\Support\ModelDeletionProbe;

/**
 * The two registry reads {@see CompactionPlanner} needs.
 *
 * Registry-only, exactly like ADR 0031's spread sample — it never
 * touches `entry_data` or an extension page, so planning (and
 * `--dry-run`) is cheap and safe to run against production at any time.
 * No locks are held: planning is a snapshot, and admissibility is
 * re-checked implicitly by the pinned reservation, which fails loudly if
 * the snapshot went stale.
 */
final class CompactionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * ADR 0038: is this model's deletion in flight?
     *
     * Registry-only, like the rest of this class, so it does not weaken
     * the "safe against production at any time" property `--dry-run`
     * depends on.
     *
     * Compaction needs it because `loadModelSlots()` below reports an
     * *empty slot set* for a severed model — its fields fail both
     * `is_filterable = 1` and the live-status predicate — so without this
     * "nothing to compact" and "this model is being erased" are
     * indistinguishable, and a dry run prints a successful empty plan for
     * a model that is being destroyed.
     */
    public function modelIsDeleting(int $modelId): bool
    {
        return ModelDeletionProbe::isDeleting($this->pdo, $modelId);
    }

    /**
     * The model's live filterable slots.
     *
     * **The population is ADR 0031's, deliberately** — `status IN
     * ('assigned','ready')` joined to `is_filterable = 1`, the identical
     * predicate pair {@see \StarDust\Watcher\SpreadSampler} uses. Keep
     * them the same: the metric that says a model needs compacting and
     * the operation that compacts it must be measuring one thing, or
     * `excess_pages → 0` stops being a success criterion.
     *
     * `backfilling` is excluded here even though ADR 0032 affinity
     * counts it, for the same reason spread excludes it: a slot that
     * serves no query is not part of the join cost being repaired. A
     * field mid-relocation is also one the planner must not try to move
     * again — `RetypeInProgressException` would reject it anyway.
     *
     * Tenant scoping goes through `stardust_models`, because
     * `stardust_slot_assignments` carries no `tenant_id`.
     *
     * @return list<ModelSlot>
     */
    public function loadModelSlots(int $tenantId, int $modelId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT f.id AS field_id, f.name AS field_name,'
            . ' sa.slot_type, sa.page_id, sa.slot_column'
            . ' FROM stardust_slot_assignments sa'
            . ' JOIN stardust_fields f ON f.id = sa.field_id'
            . ' JOIN stardust_models m ON m.id = f.model_id'
            . " WHERE m.tenant_id = ? AND f.model_id = ?"
            . "   AND sa.status IN ('assigned','ready') AND f.is_filterable = 1"
            . ' ORDER BY sa.page_id, sa.id'
        );
        $stmt->execute([$tenantId, $modelId]);

        $slots = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $slots[] = new ModelSlot(
                fieldId: (int) $row['field_id'],
                fieldName: (string) $row['field_name'],
                slotType: (string) $row['slot_type'],
                pageId: (int) $row['page_id'],
                slotColumn: (string) $row['slot_column'],
            );
        }

        return $slots;
    }

    /**
     * Free slots per page per family, restricted to columns that are
     * actually indexed.
     *
     * Delegates to {@see IndexedFreeCapacityReader}, which ADR 0031's
     * spread advisory now shares: since ADR 0044 the sampler derives its
     * `theoretical_min_pages` from the same per-page capacity this
     * planner assigns against, and two implementations of "free and
     * indexed" would be free to disagree.
     *
     * @return array<int, array<string, int>> pageId ⇒ family ⇒ count
     */
    public function loadIndexedFreeCapacity(): array
    {
        return (new IndexedFreeCapacityReader($this->pdo))->load();
    }
}
