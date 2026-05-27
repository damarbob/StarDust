<?php

declare(strict_types=1);

namespace StarDust\Chronicler;

use JsonException;
use PDO;

/**
 * Cursor-paginated bounded read over `entry_data` for the Chronicler.
 *
 * The export read shape is intentionally narrower than the Phase 4
 * read path: no filter predicates, no slot joins, no result assembly.
 * Exports must include every field for matching entries (ADR 0013 —
 * the JSON payload is the system of record), so the Chronicler reads
 * `(id, fields)` directly and decodes the JSON in PHP. Reusing
 * {@see \StarDust\Read\PaginatedProbe} would force fabrication of an
 * empty {@see \StarDust\Read\EntryQuery} + {@see \StarDust\Read\SnapshotEntry}
 * for no benefit; the bounded-read invariant of ADR 0005 is preserved
 * here by inheriting the same `LIMIT pageSize + 1` shape.
 *
 * SQL pattern:
 *   SELECT id, fields FROM entry_data
 *    WHERE tenant_id = ? AND model_id = ? AND deleted_at IS NULL
 *      AND id > ?
 *    ORDER BY id ASC
 *    LIMIT ?  (= pageSize + 1)
 *
 * The trailing row (`pageSize + 1`-th) is the next-page signal; the
 * caller treats `count($rows) <= $pageSize` as "final chunk". The
 * page already carries the composite index `(tenant_id, model_id)`
 * via {@see \StarDust\Bootstrap\Bootstrapper}'s
 * `testEntryDataCompositeIndexesPresent` invariant, so this query
 * is index-bounded.
 *
 * The pager intentionally lets {@see \PDOException} bubble up — the
 * processor classifies deadlocks (`SQLSTATE 40001`) and disconnect
 * errors at a single catch site and translates them into events per
 * ADR 0025.
 */
final class EntryDataPager
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return list<EntryDataRow> Up to `$pageSize + 1` rows. Caller
     *   treats the trailing row as the next-page signal.
     */
    public function fetchChunk(int $tenantId, int $modelId, int $cursor, int $pageSize): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, fields FROM entry_data'
            . ' WHERE tenant_id = ? AND model_id = ? AND deleted_at IS NULL'
            . '   AND id > ?'
            . ' ORDER BY id ASC'
            . ' LIMIT ?'
        );
        $stmt->bindValue(1, $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(2, $modelId, PDO::PARAM_INT);
        $stmt->bindValue(3, $cursor, PDO::PARAM_INT);
        $stmt->bindValue(4, $pageSize + 1, PDO::PARAM_INT);
        $stmt->execute();

        $rows = [];
        while (($r = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $fields = $this->decodeFields((string) $r['fields']);
            $rows[] = new EntryDataRow((int) $r['id'], $fields);
        }
        return $rows;
    }

    /** @return array<string,mixed> */
    private function decodeFields(string $json): array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // entry_data.fields is written by EntryWriter which always
            // emits valid JSON, so a malformed value here points at
            // direct DB corruption. Surface as an empty payload rather
            // than crashing the entire export — the row will simply
            // emit empty cells / an empty object. Operators see the
            // anomaly when comparing row counts.
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }
}
