<?php

declare(strict_types=1);

namespace StarDust\Search\Mysql;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Psr\Log\LoggerInterface;
use StarDust\Filter\Limits\FilterLimits;
use StarDust\Filter\Operator;
use StarDust\Read\BoundedFetch;
use StarDust\Read\CursorCodec;
use StarDust\Read\Entry;
use StarDust\Read\EntryQuery;
use StarDust\Read\PaginatedProbe;
use StarDust\Read\ResultAssembler;
use StarDust\Read\SchemaVersionCache;
use StarDust\Search\ConsistencyModel;
use StarDust\Search\EntrySearchInterface;
use StarDust\Search\SearchRequest;
use StarDust\Search\SearchResult;
use StarDust\Support\ServerEngine;
use StarDust\Support\ServerEngineDetector;
use StarDust\Support\UuidV4;

/**
 * Phase 8 default driver — wraps the Phase 4 read path behind the
 * {@see EntrySearchInterface} contract.
 *
 * `list(SearchRequest)` is structurally identical to Phase 4's
 * {@see \StarDust\Read\EntryReader::read()}: snapshot the schema,
 * probe, fetch, assemble, encode-next-cursor. The
 * {@see SqlFilterCompiler} (composed via {@see PaginatedProbe}) picks
 * the JOIN or EXISTS strategy per the request's filter shape.
 *
 * `supportsFilterOn()` is the MySQL-driver jurisdiction of
 * `is_filterable` per ADR 0022: it returns true iff the field's
 * registry row carries `is_filterable = true` AND has a live slot in
 * `assigned` or `ready` status — the same definition used by
 * {@see \StarDust\Read\FieldDescriptor::isIndexedNow()}.
 *
 * Strong consistency: every read sees every committed write
 * ({@see ConsistencyModel::STRONG}).
 */
final class MysqlNativeDriver implements EntrySearchInterface
{
    private string $lastCompileStrategy = 'joins';

    private readonly SqlFilterCompiler $compiler;
    private readonly PaginatedProbe $probe;
    private readonly BoundedFetch $fetch;
    private readonly ResultAssembler $assembler;

    /**
     * `$engine` defaults to self-detection (`ServerEngineDetector::detect()`)
     * rather than a required parameter — unlike `Bootstrapper` and
     * `PageProvisioner`, this class is constructed by the legacy
     * `Read\EntryReader` façade too, whose own constructor is
     * deliberately frozen at `(PDO, LoggerInterface)` for Phase 4
     * backward compatibility, so it cannot thread one through. Passing
     * it explicitly (as `StarDust::searchDriver()` does, via
     * `StarDust::serverEngine()`) only avoids a redundant detection
     * call; omitting it is safe, per ADR 0055 — detection, not a
     * config declaration, so there is no wrong-guess risk to avoid.
     */
    public function __construct(
        private readonly PDO $pdo,
        // Kept for EntrySearchInterface implementation uniformity: a custom
        // driver may emit its own diagnostics. The default MySQL driver is a
        // pure executor and delegates all logging to SearchService.
        // @phpstan-ignore property.onlyWritten
        private readonly LoggerInterface $logger,
        private readonly SchemaVersionCache $cache,
        ?SqlFilterCompiler $compiler = null,
        ?PaginatedProbe $probe = null,
        ?BoundedFetch $fetch = null,
        ?ResultAssembler $assembler = null,
        ?ServerEngine $engine = null,
    ) {
        $engine ??= ServerEngineDetector::detect($this->pdo);

        // MariaDB genuinely truncates ORDER BY comparison at
        // max_sort_length (default 1024 bytes, same as MySQL) for TEXT
        // columns — measured directly in SQL on real 10.6/10.11/11
        // servers: rows sharing an identical ≥1024-byte prefix come
        // back in scan order, not sort order, collation-independent
        // (reproduced with both utf8mb4_unicode_520_nopad_ci and
        // utf8mb4_general_ci). MySQL 8.0.13 does not have this problem
        // at all — verified exact even with the setting forced to 8 —
        // which is the documented premise `src/Read/CLAUDE.md`'s
        // "String slots sort exactly, on the full value" relies on and
        // ADR 0041 assumes. Silently wrong here is worse than slow: the
        // keyset pagination predicate in `SqlFilterCompiler` compares
        // the FULL value, so a truncated ORDER BY that disagrees with
        // it can skip or repeat rows across a page boundary. Raised to
        // cover the full string-slot bound (4 bytes/char utf8mb4 worst
        // case) rather than a guessed constant, so a future change to
        // `FilterLimits::DEFAULT_MAX_STRING_LENGTH` keeps this correct
        // without anyone having to remember it lives here. MySQL is
        // left untouched — its correctness does not depend on this
        // setting, so there is nothing to fix there and no reason to
        // change its resource profile.
        if ($engine === ServerEngine::MARIADB) {
            $this->pdo->exec(
                'SET SESSION max_sort_length = ' . (FilterLimits::DEFAULT_MAX_STRING_LENGTH * 4)
            );
        }

        $this->compiler  = $compiler ?? new SqlFilterCompiler();
        $this->probe     = $probe ?? new PaginatedProbe($this->pdo, $this->compiler);
        $this->fetch     = $fetch ?? new BoundedFetch($this->pdo);
        $this->assembler = $assembler ?? new ResultAssembler();
    }

    public function list(SearchRequest $request): SearchResult
    {
        $snapshot = $this->cache->snapshotForModel(
            $request->modelId,
            $request->tenantId,
            $request->correlationId,
        );

        // ADR 0038: the read surfaces go DARK for a model being deleted —
        // an empty page, indistinguishable from a model that never
        // existed, matching the posture `SchemaReader::describeModel()`
        // already takes for tenant isolation.
        //
        // This belongs here rather than in `SearchService` or the
        // pre-flight, and the reason is structural: `SearchService`
        // resolves the snapshot only when `filter !== null`, so a
        // match-all read would sail straight past a check placed there.
        // The driver resolves it unconditionally, so this one site covers
        // filtered and unfiltered alike — and covers `read()`, which is a
        // façade over exactly this call.
        if ($snapshot->isModelDeleting()) {
            return new SearchResult(
                rows:       [],
                nextCursor: null,
                pageSize:   $request->pageSize,
            );
        }

        $query = new EntryQuery(
            tenantId:     $request->tenantId,
            modelId:      $request->modelId,
            filter:       $request->filter,
            selectFields: $request->selectFields,
            pageSize:     $request->pageSize,
            cursor:       $request->cursor,
            sort:         $request->sort,
        );

        $this->lastCompileStrategy = $this->compiler->chooseStrategy($request->filter);

        $ids = $this->probe->probe($query, $snapshot);
        $hasMore = count($ids) > $request->pageSize;
        $rowIds = $hasMore ? array_slice($ids, 0, $request->pageSize) : $ids;

        $fetchResult = $this->fetch->fetch($query, $snapshot, $rowIds);
        $entries = $this->assembler->assemble(
            $fetchResult['rows'],
            $snapshot,
            $fetchResult['slotColumnByField'],
            $request->selectFields,
        );

        // encodeFor(), not encode(): the token records which ordering it
        // was issued under, so replaying it against a different sort is
        // rejected instead of quietly walking a different sequence. A
        // null sort still emits the v1 format, byte-identical to what an
        // unsorted read produced before sorting existed.
        $nextCursor = $hasMore && $rowIds !== []
            ? CursorCodec::encodeFor($request->sort, end($rowIds))
            : null;

        return new SearchResult(
            rows:       $entries,
            nextCursor: $nextCursor,
            pageSize:   $request->pageSize,
        );
    }

    public function get(int $tenantId, int $entryId): ?Entry
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, tenant_id, model_id, created_at, deleted_at, fields'
            . ' FROM entry_data'
            . ' WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$entryId, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $payload = json_decode((string) $row['fields'], true);
        $fields  = is_array($payload) ? $payload : [];
        $modelId = (int) $row['model_id'];

        // ADR 0036: the point read returns the payload verbatim, so
        // without this it would hand back the pre-rename key for rows
        // behind the backfill cursor and the post-rename key for rows
        // ahead of it — inconsistent between two entries of the same
        // model, and inconsistent with read() on the very same entry.
        //
        // ADR 0037 adds the mirror case: a field whose deletion has
        // committed is excluded from the snapshot outright, so read()
        // has already stopped returning it while the values are still
        // physically present for the length of the purge. Without the
        // strip, get() would keep serving a field read() denies exists.
        //
        // This costs a schema-version probe that get() did not
        // previously pay, which is a deliberate trade: read() pays it on
        // every call, ADR 0015 designs it to be sub-millisecond, and the
        // rewrite itself is gated behind precomputed booleans so the
        // steady state is a no-op.
        $snapshot = $this->cache->snapshotForModel(
            $modelId,
            $tenantId,
            UuidV4::generate(),
        );
        // ADR 0038, and this MUST come before the canonicalisation below
        // rather than being folded into it. A model deletion marks every
        // field, so `pendingDeletionNames` holds all of them and
        // `canonicalisePayloadKeys()` would strip every key — returning a
        // real `Entry` with a real id and `fields: []`. That is a positive
        // claim that the entry exists, which is worse than the leak it
        // was meant to prevent. Returning null matches the `$row === false`
        // path above, so a caller cannot tell the two apart.
        if ($snapshot->isModelDeleting()) {
            return null;
        }

        if ($snapshot->hasRenamesInFlight() || $snapshot->hasPendingDeletions()) {
            $fields = $snapshot->canonicalisePayloadKeys($fields);
        }

        return new Entry(
            id:        (int) $row['id'],
            tenantId:  (int) $row['tenant_id'],
            modelId:   $modelId,
            fields:    $fields,
            createdAt: new DateTimeImmutable((string) $row['created_at'], new DateTimeZone('UTC')),
            deletedAt: $row['deleted_at'] === null
                ? null
                : new DateTimeImmutable((string) $row['deleted_at'], new DateTimeZone('UTC')),
        );
    }

    public function supportedOperators(): array
    {
        return Operator::CLOSED_V1;
    }

    public function supportsFilterOn(int $fieldId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT f.is_filterable AS is_filterable, a.status AS slot_status'
            . ' FROM stardust_fields f'
            . ' LEFT JOIN stardust_slot_assignments a'
            . "   ON a.field_id = f.id AND a.status IN ('assigned','backfilling','ready','tombstoned')"
            // ADR 0037: redundant today, since the deletion clears
            // `is_filterable` and the check below already returns false.
            // Kept explicit so this cannot silently start answering
            // `true` if that clearing is ever moved.
            . ' WHERE f.id = ? AND f.deleted_at IS NULL'
        );
        $stmt->execute([$fieldId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return false;
        }
        if (!(bool) $row['is_filterable']) {
            return false;
        }
        return $row['slot_status'] === 'assigned' || $row['slot_status'] === 'ready';
    }

    /**
     * On MySQL, sortability and filterability reduce to the same fact —
     * the field has a live indexed slot — so this delegates rather than
     * duplicating the lookup.
     *
     * It is still a distinct method on the interface because the two
     * answers coincide only *here*. An external engine may index a field
     * for matching without keeping it orderable, and ADR 0022 places that
     * judgement on the driver rather than in the shared pipeline.
     */
    public function supportsSortOn(int $fieldId): bool
    {
        return $this->supportsFilterOn($fieldId);
    }

    public function supportsFuzzySearch(): bool
    {
        return false;
    }

    public function consistencyModel(): string
    {
        return ConsistencyModel::STRONG;
    }

    /**
     * Exposed for `SearchService::execute()` so the `search_request`
     * event can carry the chosen compile strategy (`joins` or
     * `exists`). Reset on every `list()` call.
     */
    public function lastCompileStrategy(): string
    {
        return $this->lastCompileStrategy;
    }
}
