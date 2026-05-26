<?php

declare(strict_types=1);

namespace StarDust\Slot;

use DateTimeZone;
use InvalidArgumentException;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
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
 * If no free slot of the required family exists, `reserve()` commits a
 * no-op transaction and returns `null`. The caller (Phase 3 write path,
 * Phase 5 Watcher loop) decides whether to provision a new page, fall
 * back to a JSON-only write, or enqueue.
 */
final class SlotReserver
{
    /** Maps `stardust_fields.declared_type` to `stardust_slot_assignments.slot_type`. */
    private const DECLARED_TYPE_TO_SLOT_TYPE = [
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
     * Phase 6b retype + filterability-promotion variant.
     *
     * Transitions a free slot to `backfilling` (not `assigned`) and,
     * when `$requireIndexed === true`, restricts candidates to slot
     * columns that have a `(tenant_id, slot_column)` composite index
     * on their page. The Reconciler's retype work source calls this
     * (a) when a retype was deferred at initiation because no
     * matching free slot existed, or (b) on every filterability
     * promotion (which must land on an indexed slot per ADR 0016
     * commitment 1).
     *
     * Returns `null` if no candidate exists — the caller treats that
     * as a capacity-wait and retries on the next tick after the
     * Watcher provisions.
     */
    public function reserveForBackfill(int $fieldId, bool $requireIndexed = false): ?SlotAssignment
    {
        return $this->reserveInOwnTransaction($fieldId, 'backfilling', $requireIndexed);
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
        return $this->reserveCore($fieldId, 'backfilling', $requireIndexed);
    }

    private function reserveInOwnTransaction(
        int $fieldId,
        string $targetStatus,
        bool $requireIndexed,
    ): ?SlotAssignment {
        $this->pdo->beginTransaction();
        try {
            $assignment = $this->reserveCore($fieldId, $targetStatus, $requireIndexed);
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

    private function reserveCore(
        int $fieldId,
        string $targetStatus,
        bool $requireIndexed,
    ): ?SlotAssignment {
        $declaredType = $this->resolveDeclaredType($fieldId);
        $slotType = self::DECLARED_TYPE_TO_SLOT_TYPE[$declaredType]
            ?? throw new InvalidArgumentException(
                "SlotReserver: field {$fieldId} has unrecognised declared_type '{$declaredType}'."
            );

        $now = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        // ORDER BY page_id keeps reservations packed on the oldest
        // pages; FOR UPDATE prevents two concurrent reservers from
        // claiming the same row.
        //
        // When the caller demands an indexed slot, an EXISTS subquery
        // against `information_schema.STATISTICS` filters to columns
        // that participate in a `(tenant_id, <slot>)` composite index
        // (PageProvisioner names these `ix_<table>_<slot>`).
        $sql = 'SELECT a.id, a.page_id, a.slot_column'
            . ' FROM stardust_slot_assignments a';
        if ($requireIndexed) {
            $sql .= ' JOIN stardust_pages p ON p.id = a.page_id'
                . ' WHERE a.status = \'free\' AND a.slot_type = ?'
                . ' AND EXISTS ('
                . '   SELECT 1 FROM information_schema.STATISTICS s'
                . '   WHERE s.TABLE_SCHEMA = DATABASE()'
                . '     AND s.TABLE_NAME = p.table_name'
                . '     AND s.COLUMN_NAME = a.slot_column'
                . ' )';
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
        // surfaces a PDOException here if `$fieldId` already has a live
        // slot — the caller's catch rolls back and rethrows.
        $update = $this->pdo->prepare(
            'UPDATE stardust_slot_assignments'
            . ' SET status = ?, field_id = ?, updated_at = ?'
            . ' WHERE id = ? AND status = \'free\''
        );
        $update->execute([$targetStatus, $fieldId, $now, $assignmentId]);

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

    private function resolveDeclaredType(int $fieldId): string
    {
        $stmt = $this->pdo->prepare('SELECT declared_type FROM stardust_fields WHERE id = ?');
        $stmt->execute([$fieldId]);
        $type = $stmt->fetchColumn();

        if ($type === false) {
            throw new InvalidArgumentException("SlotReserver: unknown field id {$fieldId}.");
        }

        return (string) $type;
    }
}
