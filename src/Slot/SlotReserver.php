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
        $declaredType = $this->resolveDeclaredType($fieldId);
        $slotType = self::DECLARED_TYPE_TO_SLOT_TYPE[$declaredType]
            ?? throw new InvalidArgumentException(
                "SlotReserver: field {$fieldId} has unrecognised declared_type '{$declaredType}'."
            );

        $now = $this->clock->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            // ORDER BY page_id keeps reservations packed on the oldest
            // pages; FOR UPDATE prevents two concurrent reservers from
            // claiming the same row (relevant in Phase 5 once the Watcher
            // runs; harmless in Phase 2 where the caller is single-threaded).
            $select = $this->pdo->prepare(
                'SELECT id, page_id, slot_column'
                . ' FROM stardust_slot_assignments'
                . ' WHERE status = \'free\' AND slot_type = ?'
                . ' ORDER BY page_id, id'
                . ' LIMIT 1 FOR UPDATE'
            );
            $select->execute([$slotType]);
            $row = $select->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                $this->pdo->commit();
                return null;
            }

            $assignmentId = (int) $row['id'];
            $pageId       = (int) $row['page_id'];
            $slotColumn   = (string) $row['slot_column'];

            // `AND status = 'free'` is a belt-and-braces guard on top of
            // FOR UPDATE. The partial unique `ux_slot_assignments_field_live`
            // surfaces a PDOException here if `$fieldId` already has a live
            // slot — the catch below rolls back and rethrows.
            $update = $this->pdo->prepare(
                'UPDATE stardust_slot_assignments'
                . ' SET status = \'assigned\', field_id = ?, updated_at = ?'
                . ' WHERE id = ? AND status = \'free\''
            );
            $update->execute([$fieldId, $now, $assignmentId]);

            $bumpVersion = $this->pdo->prepare(
                'UPDATE stardust_schema_version'
                . ' SET version = version + 1, updated_at = ?'
                . ' WHERE id = 1'
            );
            $bumpVersion->execute([$now]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->logger->info('slot reserved', [
            'event'              => 'slot_reserved',
            'source'             => 'registry',
            'field_id'           => $fieldId,
            'slot_assignment_id' => $assignmentId,
            'page_id'            => $pageId,
            'slot_column'        => $slotColumn,
            'slot_type'          => $slotType,
        ]);

        return new SlotAssignment(
            pageId: $pageId,
            slotColumn: $slotColumn,
            slotAssignmentId: $assignmentId,
            slotType: $slotType,
        );
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
