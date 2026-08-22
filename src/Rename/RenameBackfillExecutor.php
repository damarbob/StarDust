<?php

declare(strict_types=1);

namespace StarDust\Rename;

use PDO;

/**
 * Rewrites one bounded chunk of `entry_data.fields`, moving each row's
 * value from the field's pre-rename key to its current key.
 *
 * The caller owns the transaction — this mirrors
 * {@see \StarDust\Retype\RetypeBackfillExecutor} so the work source can
 * commit the cursor advance and the data change together.
 *
 * ## Why the rewrite is SQL rather than decode-mutate-encode
 *
 * This would otherwise be the first place in the engine that decodes a
 * stored payload and re-encodes it, and `json_decode($json, true)` is
 * not round-trip faithful: a payload whose keys happen to form a
 * complete sequential list from zero decodes to a PHP list and
 * re-encodes as a JSON *array*, so `{"0":"x","1":"y"}` silently becomes
 * `["x","y"]`. Field names are `VARCHAR(128)` with no numeric
 * restriction, so `"0"` is a legal name and that payload is reachable.
 * Rewriting in SQL sidesteps the question entirely, keeps full value
 * fidelity for nested objects and floats, and does the whole chunk in
 * one statement instead of one per row.
 *
 * ## The two path guards
 *
 * `JSON_CONTAINS_PATH(fields, 'one', :oldPath)` makes the statement
 * idempotent — re-running over an already-migrated range, or over rows
 * that never carried the field, is a no-op. It also avoids inserting a
 * spurious `newKey: null`, which is what `JSON_SET` would otherwise
 * write when the source path is absent. (It is *not* needed to protect
 * `fields JSON NOT NULL`: `JSON_SET` with an absent source returns a
 * document containing a JSON null, not SQL NULL.)
 *
 * `NOT JSON_CONTAINS_PATH(fields, 'one', :newPath)` makes a post-rename
 * write win over the stale key. {@see \StarDust\Write\LiveSlotMap::canonicalise()}
 * should already prevent both keys coexisting, but if they ever do, the
 * current name is the truth and the backfill must not clobber it with
 * an older value.
 */
final class RenameBackfillExecutor
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function processChunk(RenameCheckpoint $checkpoint, int $chunkSize): RenameChunkResult
    {
        $ids = $this->fetchChunkIds(
            $checkpoint->tenantId,
            $checkpoint->modelId,
            $checkpoint->lastProcessedId,
            $chunkSize,
        );

        if ($ids === []) {
            return new RenameChunkResult(
                rowsScanned: 0,
                rowsRewritten: 0,
                newCursor: $checkpoint->lastProcessedId,
                isFinalChunk: true,
            );
        }

        $oldPath = self::jsonPathFor($checkpoint->previousName);
        $newPath = self::jsonPathFor($checkpoint->currentName);

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            'UPDATE entry_data'
            . ' SET fields = JSON_REMOVE(JSON_SET(fields, ?, JSON_EXTRACT(fields, ?)), ?)'
            . " WHERE id IN ({$placeholders})"
            . "   AND JSON_CONTAINS_PATH(fields, 'one', ?)"
            . "   AND NOT JSON_CONTAINS_PATH(fields, 'one', ?)"
        );
        $stmt->execute(array_merge(
            [$newPath, $oldPath, $oldPath],
            $ids,
            [$oldPath, $newPath],
        ));

        return new RenameChunkResult(
            rowsScanned: count($ids),
            rowsRewritten: $stmt->rowCount(),
            newCursor: $ids[count($ids) - 1],
            isFinalChunk: count($ids) < $chunkSize,
        );
    }

    /**
     * No `deleted_at IS NULL` predicate, matching the retype drain: a
     * soft-deleted row can be read by nothing, but leaving it on the
     * stale key would permanently desynchronise its payload from the
     * registry, and `deleted_at` is not a hard delete.
     *
     * @return list<int>
     */
    private function fetchChunkIds(int $tenantId, int $modelId, int $cursor, int $chunkSize): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM entry_data'
            . ' WHERE tenant_id = ? AND model_id = ? AND id > ?'
            . ' ORDER BY id ASC'
            . ' LIMIT ?'
        );
        $stmt->bindValue(1, $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(2, $modelId, PDO::PARAM_INT);
        $stmt->bindValue(3, $cursor, PDO::PARAM_INT);
        $stmt->bindValue(4, $chunkSize, PDO::PARAM_INT);
        $stmt->execute();

        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $ids[] = (int) $id;
        }
        return $ids;
    }

    /**
     * Builds a quoted JSON path (`$."name"`) for a field name.
     *
     * The quoted form is required rather than cosmetic: an unquoted
     * `$.name` is only valid for names matching a bare ECMAScript
     * identifier, and `stardust_fields.name` permits spaces, hyphens,
     * leading digits and Unicode. Backslashes and double quotes are
     * escaped so a name containing either cannot terminate the path
     * early.
     */
    public static function jsonPathFor(string $fieldName): string
    {
        return '$."' . str_replace(['\\', '"'], ['\\\\', '\\"'], $fieldName) . '"';
    }
}
