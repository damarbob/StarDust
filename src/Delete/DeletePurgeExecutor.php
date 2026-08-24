<?php

declare(strict_types=1);

namespace StarDust\Delete;

use PDO;
use StarDust\Rename\RenameBackfillExecutor;

/**
 * Removes one bounded chunk of `entry_data.fields` keys belonging to a
 * field being deleted.
 *
 * The caller owns the transaction, mirroring
 * {@see \StarDust\Rename\RenameBackfillExecutor} so the work source can
 * commit the cursor advance and the data change together.
 *
 * ## Why this exists at all
 *
 * `entry_data.fields` is keyed by field name (ADR 0036), and a payload
 * key with no matching registry row is preserved verbatim — stored,
 * returned by `get()`, and written into JSON export artifacts. Deleting
 * only the registry row would therefore leave the field's values
 * consumer-visible forever, which is precisely the "dead key in every
 * payload" the demote-and-leave workaround was criticised for. The
 * registry row dying last is what makes the deletion real.
 *
 * ## The rewrite is SQL, not decode-mutate-encode
 *
 * Same reasoning as the rename executor, and the same trap:
 * `json_decode($json, true)` is not round-trip faithful, because a
 * payload whose keys form a complete sequential list from zero
 * re-encodes as a JSON *array* — `{"0":"x","1":"y"}` silently becomes
 * `["x","y"]`. Field names are `VARCHAR(128)` with no numeric
 * restriction, so that payload is reachable. `JSON_REMOVE` sidesteps it,
 * keeps full fidelity for nested objects and floats, and does the whole
 * chunk in one statement.
 *
 * ## One path guard, not two
 *
 * `JSON_CONTAINS_PATH(fields, 'one', :path)` makes the statement
 * idempotent and keeps `rowCount()` honest — re-running over an
 * already-purged range, or over rows that never carried the field, is a
 * no-op. (`JSON_REMOVE` on an absent path is itself a no-op returning
 * the document unchanged, so this guard is about observability and
 * write amplification rather than correctness.)
 *
 * The rename executor's second guard has no analogue here: it exists to
 * stop a stale value clobbering a fresh write under the new key, and a
 * delete has no destination key to protect. A post-delete write cannot
 * reintroduce the key either — `LiveSlotMap` excludes soft-deleted
 * fields, so `PayloadSplitter` drops it as an unknown key before the
 * payload is ever encoded.
 */
final class DeletePurgeExecutor
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function processChunk(DeleteCheckpoint $checkpoint, int $chunkSize): DeleteChunkResult
    {
        $ids = $this->fetchChunkIds(
            $checkpoint->tenantId,
            $checkpoint->modelId,
            $checkpoint->lastProcessedId,
            $chunkSize,
        );

        if ($ids === []) {
            return new DeleteChunkResult(
                rowsScanned: 0,
                rowsPurged: 0,
                newCursor: $checkpoint->lastProcessedId,
                isFinalChunk: true,
            );
        }

        // Reused rather than re-derived: the quoted `$."name"` form is
        // required, not cosmetic — bare `$.name` is only valid for names
        // matching a bare ECMAScript identifier, and field names permit
        // spaces, hyphens, leading digits and Unicode.
        $path = RenameBackfillExecutor::jsonPathFor($checkpoint->fieldName);

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            'UPDATE entry_data'
            . ' SET fields = JSON_REMOVE(fields, ?)'
            . " WHERE id IN ({$placeholders})"
            . "   AND JSON_CONTAINS_PATH(fields, 'one', ?)"
        );
        $stmt->execute(array_merge([$path], $ids, [$path]));

        return new DeleteChunkResult(
            rowsScanned: count($ids),
            rowsPurged: $stmt->rowCount(),
            newCursor: $ids[count($ids) - 1],
            isFinalChunk: count($ids) < $chunkSize,
        );
    }

    /**
     * No `deleted_at IS NULL` predicate, matching the rename and retype
     * drains: a soft-deleted entry can be read by nothing, but leaving
     * its payload carrying a key whose field no longer exists would
     * permanently desynchronise it from the registry, and `deleted_at`
     * is not a hard delete.
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
}
