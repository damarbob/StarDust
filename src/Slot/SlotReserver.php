<?php

declare(strict_types=1);

namespace StarDust\Slot;

use DateTimeZone;
use InvalidArgumentException;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use StarDust\Exception\NonFilterableFieldSlotException;
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
        $slotType = $this->resolveReservableSlotType($fieldId);

        return $this->reserveCore($fieldId, $slotType, 'backfilling', $requireIndexed);
    }

    private function reserveInOwnTransaction(
        int $fieldId,
        string $targetStatus,
        bool $requireIndexed,
    ): ?SlotAssignment {
        // Resolved (and guarded) before `beginTransaction()` so a
        // rejected field never opens a transaction or takes the
        // `FOR UPDATE` gap locks the candidate SELECT would acquire.
        $slotType = $this->resolveReservableSlotType($fieldId);

        $this->pdo->beginTransaction();
        try {
            $assignment = $this->reserveCore($fieldId, $slotType, $targetStatus, $requireIndexed);
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
     * @param string $slotType the family resolved by
     *                         {@see self::resolveReservableSlotType()} —
     *                         taking it as a parameter is what keeps the
     *                         ADR 0034 filterability guard unbypassable
     */
    private function reserveCore(
        int $fieldId,
        string $slotType,
        string $targetStatus,
        bool $requireIndexed,
    ): ?SlotAssignment {
        $now = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        // ORDER BY page_id keeps reservations packed on the oldest
        // pages; FOR UPDATE prevents two concurrent reservers from
        // claiming the same row.
        //
        // When the caller demands an indexed slot, the shared
        // {@see IndexedSlotPredicate} filters to columns that carry an
        // index. It is shared because the Watcher counts usable
        // capacity with the same predicate — if the two drift, the
        // Watcher reports capacity this method refuses.
        $sql = 'SELECT a.id, a.page_id, a.slot_column'
            . ' FROM stardust_slot_assignments a';
        if ($requireIndexed) {
            $sql .= ' JOIN stardust_pages p ON p.id = a.page_id'
                . ' WHERE a.status = \'free\' AND a.slot_type = ?'
                . ' AND ' . IndexedSlotPredicate::existsSql('a', 'p');
        } else {
            $sql .= ' WHERE a.status = \'free\' AND a.slot_type = ?';
        }
        $sql .= ' ORDER BY a.page_id, a.id LIMIT 1 FOR UPDATE';

        $select = $this->pdo->prepare($sql);
        $select->execute([$slotType]);
        $row = $select->fetch(PDO::FETCH_ASSOC);

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
        );
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
        ]);
    }

    /**
     * Resolves the field's slot-type family, rejecting any field that
     * may not hold a slot at all.
     *
     * Returns the `slot_type` rather than the raw `declared_type` so
     * that `reserveCore()` cannot be reached without passing through
     * this guard — a future fourth entry point gets the ADR 0034 check
     * for free.
     *
     * @throws NonFilterableFieldSlotException when the field is
     *                                         non-filterable (ADR 0034 —
     *                                         JSON-only, never slotted)
     * @throws InvalidArgumentException        when the field does not
     *                                         exist or carries an
     *                                         unrecognised declared_type
     */
    private function resolveReservableSlotType(int $fieldId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT declared_type, is_filterable FROM stardust_fields WHERE id = ?'
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

        return self::DECLARED_TYPE_TO_SLOT_TYPE[$declaredType]
            ?? throw new InvalidArgumentException(
                "SlotReserver: field {$fieldId} has unrecognised declared_type '{$declaredType}'."
            );
    }
}
