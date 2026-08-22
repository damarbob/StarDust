<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use StarDust\Clock\SystemClock;
use StarDust\Reconciler\Reconciler;
use StarDust\Rename\RenameBackfillExecutor;
use StarDust\Rename\RenameBackfillWorkSource;
use StarDust\Rename\RenameCheckpointRepository;
use StarDust\Rename\RenameInitiator;
use StarDust\Retype\RetypeBackfillExecutor;
use StarDust\Retype\RetypeBackfillWorkSource;
use StarDust\Retype\RetypeCheckpointRepository;
use StarDust\Retype\RetypeInitiator;
use StarDust\Slot\SlotReserver;
use StarDust\Watcher\CardinalitySampler;
use StarDust\Write\SlotRowUpserter;

/**
 * Shared scaffolding for Phase 6b retype/promotion smoke tests.
 *
 * Builds on {@see Phase6aTestCase} (which already provides
 * `seedSlotValues()`, `fetchSlotAssignment()`, `fetchSchemaVersion()`,
 * `pageTableNameFor()`, etc.). Adds factory helpers for the new
 * collaborators plus row-level inspection helpers the tests need to
 * assert post-initiation and post-backfill state.
 */
abstract class Phase6bTestCase extends Phase6aTestCase
{
    protected function makeRetypeInitiator(?LoggerInterface $logger = null): RetypeInitiator
    {
        $log = $logger ?? new NullLogger();
        return new RetypeInitiator(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: $log,
            slotReserver: new SlotReserver(
                pdo: $this->pdo,
                clock: new SystemClock(),
                logger: $log,
            ),
            checkpointRepository: new RetypeCheckpointRepository($this->pdo),
            renameCheckpointRepository: new RenameCheckpointRepository($this->pdo),
        );
    }

    protected function makeRenameInitiator(?LoggerInterface $logger = null): RenameInitiator
    {
        return new RenameInitiator(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: $logger ?? new NullLogger(),
            renameCheckpoints: new RenameCheckpointRepository($this->pdo),
            retypeCheckpoints: new RetypeCheckpointRepository($this->pdo),
        );
    }

    protected function makeRenameBackfillWorkSource(
        ?LoggerInterface $logger = null,
        int $chunkSize = 500,
    ): RenameBackfillWorkSource {
        return new RenameBackfillWorkSource(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: $logger ?? new NullLogger(),
            repository: new RenameCheckpointRepository($this->pdo),
            executor: new RenameBackfillExecutor(pdo: $this->pdo),
            chunkSize: $chunkSize,
        );
    }

    /**
     * Drives exactly one rename-backfill tick. Deliberately not routed
     * through the full Reconciler so a test can stop the drain
     * half-migrated and inspect the window.
     */
    protected function runRenameTick(
        ?LoggerInterface $logger = null,
        int $chunkSize = 500,
    ): \StarDust\Reconciler\TickOutcome {
        return $this->makeRenameBackfillWorkSource($logger, $chunkSize)
            ->tickOne('test-rename-' . bin2hex(random_bytes(4)));
    }

    /** @return array{id: int, status: string, last_processed_id: int}|null */
    protected function fetchRenameCheckpointForField(int $fieldId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, status, last_processed_id FROM backfill_checkpoints WHERE job_name = ?'
        );
        $stmt->execute([RenameCheckpointRepository::jobNameFor($fieldId)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id'                => (int) $row['id'],
            'status'            => (string) $row['status'],
            'last_processed_id' => (int) $row['last_processed_id'],
        ];
    }

    /** Raw payload for an entry, as stored — no assembler, no fallback. */
    protected function rawPayload(int $entryId): mixed
    {
        $json = (string) $this->pdo
            ->query('SELECT fields FROM entry_data WHERE id = ' . $entryId)
            ->fetchColumn();
        return json_decode($json, true);
    }

    protected function makeRetypeBackfillWorkSource(
        ?LoggerInterface $logger = null,
        int $chunkSize = 500,
    ): RetypeBackfillWorkSource {
        $log = $logger ?? new NullLogger();
        return new RetypeBackfillWorkSource(
            pdo: $this->pdo,
            clock: new SystemClock(),
            logger: $log,
            repository: new RetypeCheckpointRepository($this->pdo),
            executor: new RetypeBackfillExecutor(
                pdo: $this->pdo,
                slotRowUpserter: new SlotRowUpserter($this->pdo),
            ),
            slotReserver: new SlotReserver(
                pdo: $this->pdo,
                clock: new SystemClock(),
                logger: $log,
            ),
            cardinalitySampler: new CardinalitySampler(
                pdo: $this->pdo,
                logger: $log,
                selectivityThreshold: 0.01,
                rowFloor: 10_000,
                distinctFloor: 10,
            ),
            spreadSampler: $this->makeSpreadSampler($log),
            chunkSize: $chunkSize,
        );
    }

    /**
     * Builds a Reconciler whose ONLY work source is the retype
     * backfill source. Lets tests drive retype-backfill ticks
     * without touching sync_queue or import_jobs claims.
     */
    protected function makeRetypeReconciler(
        ?LoggerInterface $logger = null,
        int $chunkSize = 500,
    ): Reconciler {
        return new Reconciler(
            workSources: [$this->makeRetypeBackfillWorkSource($logger, $chunkSize)],
            capacityWaitMillis: 0,
            interChunkDelayMicros: 0,
            sleepFn: static fn (int $_micros) => null,
        );
    }

    /**
     * Loads the field's checkpoint row keyed `retype_field_{id}`.
     *
     * @return array{id: int, job_name: string, status: string,
     *               last_processed_id: int, source_declared_type: ?string,
     *               started_at: string, updated_at: string,
     *               completed_at: ?string, last_error: ?string}|null
     */
    protected function fetchCheckpointForField(int $fieldId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM backfill_checkpoints WHERE job_name = ?'
        );
        $stmt->execute(['retype_field_' . $fieldId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Loads the field's live slot (status in `assigned`, `backfilling`,
     * `ready`). Returns null if the field has no live slot — used to
     * assert deferred-assignment state.
     */
    protected function fetchLiveSlotForField(int $fieldId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM stardust_slot_assignments'
            . " WHERE field_id = ? AND status IN ('assigned','backfilling','ready')"
            . ' LIMIT 1'
        );
        $stmt->execute([$fieldId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * Loads the (most likely single) tombstoned slot whose page +
     * slot_column once belonged to this field. The Liberator may
     * reclaim it later; tests assert the state BEFORE that runs.
     */
    protected function fetchTombstonedSlotByPageColumn(int $pageId, string $slotColumn): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM stardust_slot_assignments'
            . " WHERE page_id = ? AND slot_column = ? AND status = 'tombstoned'"
            . ' LIMIT 1'
        );
        $stmt->execute([$pageId, $slotColumn]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    protected function fetchFieldRow(int $fieldId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM stardust_fields WHERE id = ?');
        $stmt->execute([$fieldId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row, "Field {$fieldId} not found.");
        return $row;
    }

    /**
     * Reads one slot column's value for one entry in one page table.
     *
     * @return mixed PHP-typed value (string|int|float|null depending on column type)
     */
    protected function fetchSlotValue(string $tableName, int $entryId, string $slotColumn): mixed
    {
        $stmt = $this->pdo->prepare(
            "SELECT {$slotColumn} FROM {$tableName} WHERE entry_id = ?"
        );
        $stmt->execute([$entryId]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : $value;
    }

    /**
     * Capture-recording PSR-3 logger. Returns the logger; call
     * `$logger->records()` to read buffered events. (We expose records
     * via a method rather than a by-reference tuple because PHP list
     * destructuring drops the reference.)
     */
    protected function makeRecordingLogger(): LoggerInterface
    {
        return new class() implements LoggerInterface {
            /** @var list<array{level: string, message: string, context: array<string,mixed>}> */
            private array $records = [];

            public function emergency(string|\Stringable $message, array $context = []): void
            {
                $this->log('emergency', $message, $context);
            }
            public function alert(string|\Stringable $message, array $context = []): void
            {
                $this->log('alert', $message, $context);
            }
            public function critical(string|\Stringable $message, array $context = []): void
            {
                $this->log('critical', $message, $context);
            }
            public function error(string|\Stringable $message, array $context = []): void
            {
                $this->log('error', $message, $context);
            }
            public function warning(string|\Stringable $message, array $context = []): void
            {
                $this->log('warning', $message, $context);
            }
            public function notice(string|\Stringable $message, array $context = []): void
            {
                $this->log('notice', $message, $context);
            }
            public function info(string|\Stringable $message, array $context = []): void
            {
                $this->log('info', $message, $context);
            }
            public function debug(string|\Stringable $message, array $context = []): void
            {
                $this->log('debug', $message, $context);
            }
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level'   => (string) $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }

            /** @return list<array{level: string, message: string, context: array<string,mixed>}> */
            public function records(): array
            {
                return $this->records;
            }
        };
    }

    /**
     * Filters captured records by `context.event` key. Many tests
     * only care about specific event names.
     *
     * @param list<array{level: string, message: string, context: array<string,mixed>}> $records
     * @return list<array{level: string, message: string, context: array<string,mixed>}>
     */
    protected function recordsWithEvent(array $records, string $eventName): array
    {
        return array_values(array_filter(
            $records,
            static fn (array $r) => ($r['context']['event'] ?? null) === $eventName,
        ));
    }
}
