<?php

declare(strict_types=1);

namespace StarDust\Support;

use PDO;

/**
 * The single definition of "this model's ADR 0038 deletion is in flight".
 *
 * Several guards need the answer and none of them is a snapshot consumer:
 * the two bulk submission paths, `compactModel()`, export submission, and
 * `SchemaBuilder`. The read and write snapshots carry the same flag as a
 * column they already select ({@see \StarDust\Read\SlotResolver},
 * {@see \StarDust\Write\LiveSlotMap}) and do **not** route through here —
 * paying a second query on the hot path to share nine lines would be a bad
 * trade.
 *
 * ## The predicate is positive, and that is the whole invariant
 *
 * Every method here asks `deleted_at IS NOT NULL`. None of them requires a
 * live `stardust_models` row, because **a missing model row must never
 * read as "deleting"**. `entry_data.model_id` is a deliberate logical-only
 * reference with no foreign key (`schema_reference` §3), so rows carrying
 * a `model_id` with no registry row are legal, exist in real deployments,
 * and appear in several fixtures. A guard phrased as "join to a live
 * model" would change every one of them at once, silently.
 *
 * If you are ever tempted to write `AND m.deleted_at IS NULL` as a join
 * requirement instead, use a LEFT join and keep the predicate on the
 * nullable side — see `EntryDeleter::delete()` for the one place that
 * shape is genuinely needed.
 */
final class ModelDeletionProbe
{
    /** True iff the model exists AND its deletion is in flight. */
    public static function isDeleting(PDO $pdo, int $modelId): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM stardust_models WHERE id = ? AND deleted_at IS NOT NULL LIMIT 1'
        );
        $stmt->execute([$modelId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * The subset of `$modelIds` whose deletion is in flight, in ascending
     * order.
     *
     * One query for the whole batch. The bulk paths are >1 000 entities by
     * construction, and a per-payload probe there would be pathological.
     *
     * @param  list<int> $modelIds
     * @return list<int>
     */
    public static function deletingAmong(PDO $pdo, array $modelIds): array
    {
        $unique = array_values(array_unique($modelIds));
        if ($unique === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($unique), '?'));
        $stmt = $pdo->prepare(
            "SELECT id FROM stardust_models WHERE id IN ({$placeholders})"
            . ' AND deleted_at IS NOT NULL ORDER BY id'
        );
        $stmt->execute($unique);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $out[] = (int) $id;
        }

        return $out;
    }
}
