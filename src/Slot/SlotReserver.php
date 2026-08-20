<?php

declare(strict_types=1);

namespace StarDust\Slot;

use DateTimeZone;
use InvalidArgumentException;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Exception\NonFilterableFieldSlotException;
use StarDust\Write\LiveSlotMap;
use Throwable;

/**
 * Phase 2 slot reservation.
 *
 * Performs the `free → assigned` transition on exactly one
 * `stardust_slot_assignments` row in the same transaction as a
 * `stardust_schema_version.version` bump (ADR 0017 §4.6). The chosen slot
 * matches the field's `declared_type → slot_type` family and is taken
 * from the oldest page first so assignments stay compactly packed (helps
 * Liberator efficiency later).
 *
 * Only a filterable field may hold a slot. Per ADR 0034 a non-filterable
 * field is JSON-only, so every entry point rejects one with a
 * {@see NonFilterableFieldSlotException} before any row is touched —
 * before the transaction opens on the own-transaction paths. The guard
 * lives in {@see self::resolveReservableSlotType()}, whose return value
 * `reserveCore()` requires, so no reservation path can bypass it.
 *
 * If no free slot of the required family exists, the own-transaction
 * entry points commit a no-op transaction and return `null`. The caller
 * decides whether to fall back to a JSON-only write, enqueue, or wait
 * for the Watcher to provision.
 */
final class SlotReserver
{
    /**
     * Maps `stardust_fields.declared_type` to `stardust_slot_assignments.slot_type`.
     *
     * Public because the Watcher's demand reader folds waiting fields
     * into slot families with the same mapping; one source keeps the
     * reserver and the provisioner's demand view from drifting.
     */
    public const DECLARED_TYPE_TO_SLOT_TYPE = [
        'string'   => 'str',
        'int'      => 'int',
        'numeric'  => 'num',
        'datetime' => 'dt',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function reserve(int $fieldId): ?SlotAssignment
    {
        return $this->reserveInOwnTransaction($fieldId, 'assigned', false);
    }

    /**
     * The ADR 0007 exhaustion-backfill entry point.
     *
     * Called by the Reconciler's sync-queue work source when a
     * registered *filterable* field turns out to still have no live
     * slot — the condition ADR 0007 resolves with "once a free slot
     * becomes available … the Reconciler … writes the field value into
     * the newly freed slot". Before this existed, nothing in `src/`
     * reserved for a plain unmapped filterable field, so the Watcher's
     * `pending_demand` gauge had nothing that drained it.
     *
     * Two differences from {@see self::reserve()}, both load-bearing:
     *
     *   - `$requireIndexed` is hardcoded `true`, not defaulted. The
     *     slot goes live as `assigned`, which makes
     *     `MysqlNativeDriver::supportsFilterOn()` return true at once;
     *     landing on an unindexed column would then have the compiler
     *     emit a predicate against a column with no index, violating
     *     ADR 0004.
     *   - It is called AFTER the work source rolls its chunk back, so
     *     the reservation owns its own short transaction rather than
     *     holding `FOR UPDATE` locks and the `stardust_schema_version`
     *     singleton bump inside a chunk transaction (ADR 0008).
     *
     * Returns `null` when no indexed free slot of the family exists —
     * the caller emits `capacity_wait` and retries on a later tick,
     * once the Watcher's ADR 0035 unsatisfiable-demand trigger has
     * provisioned a page carrying the starved family's index.
     */
    public function reserveForExhaustionBackfill(int $fieldId): ?SlotAssignment
    {
        return $this->reserveInOwnTransaction($fieldId, 'assigned', true);
    }

    /**
     * Phase 6b composition entry point: reserves a `backfilling` slot
     * inside the caller's existing transaction. The {@see RetypeInitiator}
     * uses this so the registry tuple (field update + old-slot
     * tombstone + new-slot reservation + schema_version bump +
     * checkpoint insert) commits atomically.
     *
     * The caller is responsible for emitting the `slot_reserved`
     * event after its own commit succeeds, so a rolled-back outer
     * transaction never produces a misleading log line.
     */
    public function reserveForBackfillWithinTransaction(
        int $fieldId,
        bool $requireIndexed = false,
    ): ?SlotAssignment {
        $field = $this->resolveReservableField($fieldId);

        return $this->reserveCore($fieldId, $field, 'backfilling', $requireIndexed);
    }

    /**
     * ADR 0033 compaction: reserve a `backfilling` slot on **one exact
     * page**, or nothing.
     *
     * Affinity is a bias that may spill to any page; compaction cannot
     * tolerate that. Its planner has already decided which page this
     * field belongs on, and a reservation that lands anywhere else
     * produces a compaction that does not compact. So this variant does
     * not fall back: if the pinned page has no indexed free slot of the
     * family, it returns `null` and the caller turns that into a hard
     * failure (pin-or-fail, ADR 0033's one deliberate divergence from
     * ADR 0016 commitment 4).
     *
     * `requireIndexed` is hardcoded `true` — a relocated field stays
     * filterable throughout, so ADR 0016 commitment 1 and ADR 0004
     * demand an indexed slot exactly as for any other live filterable
     * field.
     *
     * The caller owns the transaction and emits `slot_reserved` after
     * its own commit, matching {@see self::reserveForBackfillWithinTransaction()}.
     */
    public function reserveForBackfillOnPageWithinTransaction(
        int $fieldId,
        int $pageId,
    ): ?SlotAssignment {
        $field = $this->resolveReservableField($fieldId);

        return $this->reserveCore($fieldId, $field, 'backfilling', true, $pageId);
    }

    private function reserveInOwnTransaction(
        int $fieldId,
        string $targetStatus,
        bool $requireIndexed,
    ): ?SlotAssignment {
        // Resolved (and guarded) before `beginTransaction()` so a
        // rejected field never opens a transaction or takes the
        // `FOR UPDATE` gap locks the candidate SELECT would acquire.
        $field = $this->resolveReservableField($fieldId);

        $this->pdo->beginTransaction();
        try {
            $assignment = $this->reserveCore($fieldId, $field, $targetStatus, $requireIndexed);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        if ($assignment !== null) {
            $this->emitSlotReservedEvent($fieldId, $assignment, $targetStatus);
        }

        return $assignment;
    }

    /**
     * @param array{slotType: string, modelId: int} $field resolved by
     *        {@see self::resolveReservableField()} — taking it as a
     *        parameter is what keeps the ADR 0034 filterability guard
     *        unbypassable, and it carries the model id that ADR 0032
     *        affinity needs without adding a public parameter
     */
    private function reserveCore(
        int $fieldId,
        array $field,
        string $targetStatus,
        bool $requireIndexed,
        ?int $pinnedPageId = null,
    ): ?SlotAssignment {
        $slotType = $field['slotType'];

        $now = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        // ADR 0032: resolved in a PRIOR, NON-LOCKING read. This must not
        // happen inline in the `FOR UPDATE` statement below — a
        // correlated EXISTS there can, depending on plan and isolation
        // level, lock the model's live sibling slots, which the write
        // path reads and relocations mutate. That contention does not
        // exist today and must not be introduced.
        $affinePageIds = $this->affinePageIds($field['modelId']);

        // Affinity is expressed as TWO queries, not as an ORDER BY key.
        // See {@see self::selectCandidate()} for why — the obvious
        // single-query form is a serious concurrency regression.
        $row      = false;
        $affinity = SlotAssignment::AFFINITY_FALLBACK;

        if ($pinnedPageId !== null) {
            // ADR 0033: exactly this page or nothing. No affinity pass,
            // no global-oldest spill — a compaction that silently landed
            // elsewhere would not compact. The affinity label is still
            // computed honestly, so the event reports whether the pinned
            // page was one the model already occupied.
            $row = $this->selectCandidate($slotType, $requireIndexed, [$pinnedPageId]);

            if ($row !== false && in_array($pinnedPageId, $affinePageIds, true)) {
                $affinity = SlotAssignment::AFFINITY_CO_LOCATED;
            }
        } else {
            if ($affinePageIds !== []) {
                $row = $this->selectCandidate($slotType, $requireIndexed, $affinePageIds);
                if ($row !== false) {
                    $affinity = SlotAssignment::AFFINITY_CO_LOCATED;
                }
            }

            // Spill to global-oldest whenever affinity found nothing. ADR
            // 0032 is emphatic that this is a bias and never a constraint:
            // affinity must not fail a reservation that would otherwise have
            // succeeded, or write availability (ADR 0007) breaks and a
            // reservation can starve indefinitely.
            if ($row === false) {
                $row = $this->selectCandidate($slotType, $requireIndexed, null);
            }
        }

        if ($row === false) {
            return null;
        }

        $assignmentId = (int) $row['id'];
        $pageId       = (int) $row['page_id'];
        $slotColumn   = (string) $row['slot_column'];

        // `AND status = 'free'` is a belt-and-braces guard on top of
        // FOR UPDATE. The partial unique `ux_slot_assignments_field_live`
        // rejects this write if `$fieldId` already has a live slot —
        // under `ERRMODE_EXCEPTION` as a PDOException the caller's catch
        // rolls back and rethrows.
        $update = $this->pdo->prepare(
            'UPDATE stardust_slot_assignments'
            . ' SET status = ?, field_id = ?, updated_at = ?'
            . ' WHERE id = ? AND status = \'free\''
        );
        $update->execute([$targetStatus, $fieldId, $now, $assignmentId]);

        // The engine takes an *injected* PDO (ADR 0026), so a consumer
        // on `ERRMODE_SILENT` gets no exception from that constraint —
        // `execute()` merely returns false. Without this check the
        // method would then bump the schema version and hand back a
        // SlotAssignment for a slot it never claimed, and the ADR 0007
        // caller would log `slot_reserved`, report progress, and re-try
        // the identical no-op every tick — a silent loop with no
        // `capacity_wait` to show an operator that anything is wrong.
        // Verified against MySQL 8.0.13: the duplicate surfaces as errno
        // 1062 when the rival reservation has committed, and as errno
        // 1205 (lock wait timeout) while it is still open.
        //
        // `rowCount() === 0` is the same "my write did not land"
        // detector `ImportJobWorkSource` uses for lease loss, and it is
        // exact here: the UPDATE always changes `status`, so a matched
        // row is always a changed row.
        if ($update->rowCount() === 0) {
            return null;
        }

        $bumpVersion = $this->pdo->prepare(
            'UPDATE stardust_schema_version'
            . ' SET version = version + 1, updated_at = ?'
            . ' WHERE id = 1'
        );
        $bumpVersion->execute([$now]);

        return new SlotAssignment(
            pageId: $pageId,
            slotColumn: $slotColumn,
            slotAssignmentId: $assignmentId,
            slotType: $slotType,
            affinity: $affinity,
        );
    }

    /**
     * One candidate `SELECT … FOR UPDATE`, optionally scoped to a set of
     * pages.
     *
     * ## Why affinity is two queries and not an `ORDER BY` key
     *
     * The natural expression of ADR 0032's conceptual ordering is a
     * single query with `ORDER BY (a.page_id IN (…)) DESC, a.page_id,
     * a.id`. **Measured on MySQL 8.0.13, that is a serious concurrency
     * regression** and it was rejected on the evidence:
     *
     * | shape                     | plan                                    | free rows locked |
     * | :------------------------ | :-------------------------------------- | :--------------- |
     * | today's global-oldest     | `type=index`, no filesort               | 1 of 8           |
     * | `ORDER BY (page_id IN …)` | `type=ref`, **filesort**                | **8 of 8**       |
     * | affine-scoped + FORCE     | `type=range`, no filesort               | 1 of 8           |
     *
     * The ordering expression cannot be satisfied from an index, so the
     * optimiser abandons the index-ordered `LIMIT 1` walk and filesorts
     * the whole free pool of that family — and `FOR UPDATE` locks every
     * row the scan examines. Two reservers for unrelated models of the
     * same family would then serialise completely. Scoping affinity into
     * its own `WHERE` clause keeps both queries index-ordered, so each
     * locks ~1 row exactly as today.
     *
     * `FORCE INDEX` is deliberate, not cargo-cult. Without it the
     * optimiser picks `index_merge … Using intersect(...)` for the
     * `page_id IN (…) AND status='free'` combination, which re-examines
     * (and re-locks) the whole family — measured at 8 of 8 and 5 of 12
     * on two fixtures. Forcing `(page_id, status)` keeps the tight range
     * scan the ordering already wants.
     *
     * @param  list<int>|null $affinePageIds null ⇒ the unscoped
     *                                       global-oldest fallback query,
     *                                       byte-identical to pre-ADR-0032
     *                                       behaviour
     * @return array{id: int|string, page_id: int|string, slot_column: string}|false
     */
    private function selectCandidate(
        string $slotType,
        bool $requireIndexed,
        ?array $affinePageIds,
    ): array|false {
        $sql = 'SELECT a.id, a.page_id, a.slot_column'
            . ' FROM stardust_slot_assignments a';

        if ($affinePageIds !== null) {
            $sql .= ' FORCE INDEX (ix_slot_assignments_page_status)';
        }

        // When the caller demands an indexed slot, the shared
        // {@see IndexedSlotPredicate} filters to columns that carry an
        // index. It is shared because the Watcher counts usable capacity
        // with the same predicate — if the two drift, the Watcher
        // reports capacity this method refuses. Note it narrows
        // *eligibility*, so it outranks affinity's *ordering*: an
        // unindexed affine page loses to an indexed non-affine one.
        if ($requireIndexed) {
            $sql .= ' JOIN stardust_pages p ON p.id = a.page_id'
                . ' WHERE a.status = \'free\' AND a.slot_type = ?'
                . ' AND ' . IndexedSlotPredicate::existsSql('a', 'p');
        } else {
            $sql .= ' WHERE a.status = \'free\' AND a.slot_type = ?';
        }

        $params = [$slotType];

        if ($affinePageIds !== null) {
            // `IN ()` is a MySQL syntax error; the caller only passes a
            // non-empty list, but the guard belongs beside the SQL that
            // depends on it — same placement as
            // `SyncQueueWorkSource::deleteQueueRows()`.
            if ($affinePageIds === []) {
                return false;
            }
            $placeholders = implode(',', array_fill(0, count($affinePageIds), '?'));
            $sql .= " AND a.page_id IN ({$placeholders})";
            $params = array_merge($params, $affinePageIds);
        }

        // ORDER BY page_id keeps reservations packed on the oldest pages
        // (density); FOR UPDATE prevents two concurrent reservers from
        // claiming the same row.
        $sql .= ' ORDER BY a.page_id, a.id LIMIT 1 FOR UPDATE';

        $select = $this->pdo->prepare($sql);
        $select->execute($params);

        return $select->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Pages already hosting a live slot of this model — the ADR 0032
     * affine set.
     *
     * **A plain read, deliberately.** No `FOR UPDATE`, and it runs
     * before the candidate selection rather than inside it. The ADR
     * makes this normative: the rows it reads are the model's in-use
     * slots, and locking them would create write-path/relocation
     * contention that does not exist today.
     *
     * Note the status set is {@see LiveSlotMap::LIVE_STATUSES}, which
     * **includes `backfilling`** — and that is deliberately different
     * from {@see \StarDust\Watcher\SpreadSampler}'s `('assigned','ready')`.
     * The two answer different questions. Spread measures join cost, and
     * a `backfilling` slot services no query so it adds none. Affinity
     * asks where the model *will* live, and a `backfilling` slot is
     * exactly that. Do not "unify" them.
     *
     * @return list<int>
     */
    private function affinePageIds(int $modelId): array
    {
        $placeholders = implode(',', array_fill(0, count(LiveSlotMap::LIVE_STATUSES), '?'));

        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT a.page_id'
            . ' FROM stardust_slot_assignments a'
            . ' JOIN stardust_fields f ON f.id = a.field_id'
            . " WHERE f.model_id = ? AND a.status IN ({$placeholders})"
        );
        $stmt->execute(array_merge([$modelId], LiveSlotMap::LIVE_STATUSES));

        $pageIds = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $pageId) {
            $pageIds[] = (int) $pageId;
        }

        return $pageIds;
    }

    /**
     * Public for the RetypeInitiator to call after its outer commit
     * succeeds. Phase 2 / Phase 5 callers use the own-transaction
     * variants which emit internally.
     */
    public function emitSlotReservedEvent(int $fieldId, SlotAssignment $assignment, string $status): void
    {
        $this->logger->info('slot reserved', [
            'event'              => 'slot_reserved',
            'source'             => 'registry',
            'field_id'           => $fieldId,
            'slot_assignment_id' => $assignment->slotAssignmentId,
            'page_id'            => $assignment->pageId,
            'slot_column'        => $assignment->slotColumn,
            'slot_type'          => $assignment->slotType,
            'status'             => $status,
            // ADR 0032 observability. Additive field on the existing
            // event, so no ADR 0020 amendment and no EventVocabularyTest
            // change. Read off the assignment rather than passed in,
            // because RetypeInitiator and RetypeBackfillWorkSource emit
            // this post-commit and would otherwise have to guess.
            'affinity'           => $assignment->affinity,
        ]);
    }

    /**
     * Resolves everything `reserveCore()` needs about the field, and
     * rejects any field that may not hold a slot at all.
     *
     * Returns the mapped `slot_type` rather than the raw `declared_type`
     * so that `reserveCore()` cannot be reached without passing through
     * this guard — a future entry point gets the ADR 0034 check for free.
     *
     * `model_id` rides along from the *same* row read. ADR 0032 requires
     * the model be derived here rather than passed in, which is what
     * keeps affinity from adding a parameter to any of the three public
     * `reserve*()` signatures.
     *
     * @return array{slotType: string, modelId: int}
     *
     * @throws NonFilterableFieldSlotException when the field is
     *                                         non-filterable (ADR 0034 —
     *                                         JSON-only, never slotted)
     * @throws InvalidArgumentException        when the field does not
     *                                         exist or carries an
     *                                         unrecognised declared_type
     */
    private function resolveReservableField(int $fieldId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT declared_type, is_filterable, model_id FROM stardust_fields WHERE id = ?'
        );
        $stmt->execute([$fieldId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new InvalidArgumentException("SlotReserver: unknown field id {$fieldId}.");
        }

        if (! (bool) $row['is_filterable']) {
            throw new NonFilterableFieldSlotException(
                "SlotReserver: field {$fieldId} is not filterable and cannot hold a slot"
                . ' (ADR 0034 — non-filterable fields are JSON-only).'
            );
        }

        $declaredType = (string) $row['declared_type'];

        $slotType = self::DECLARED_TYPE_TO_SLOT_TYPE[$declaredType]
            ?? throw new InvalidArgumentException(
                "SlotReserver: field {$fieldId} has unrecognised declared_type '{$declaredType}'."
            );

        return ['slotType' => $slotType, 'modelId' => (int) $row['model_id']];
    }
}
