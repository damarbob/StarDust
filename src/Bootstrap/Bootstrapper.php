<?php

declare(strict_types=1);

namespace StarDust\Bootstrap;

use PDO;
use PDOException;
use StarDust\Support\PdoQuery;

/**
 * Phase 1 migration runner.
 *
 * Applies the data plane, schema registry, and operational table DDL in
 * one idempotent pass — safe on a blank database (creates everything) and
 * safe on an already-bootstrapped database (no-op, no duplicate-table
 * errors, no data destruction).
 *
 * Normative references: registry contract (ADR 0017), MySQL 8.0.13+ floor
 * (ADR 0023) — the partial unique index on stardust_slot_assignments uses
 * 8.0.13+ functional-index syntax — and the schema reference (§1–§5),
 * which is the source of truth for column shapes, indexes, and atomicity
 * invariants implemented here.
 */
final class Bootstrapper
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function run(): void
    {
        $this->createEntryData();
        $this->createSyncQueue();
        $this->createModels();
        $this->createFields();
        $this->createPages();
        $this->createSlotAssignments();
        $this->createSchemaVersion();
        $this->createExportJobs();
        $this->createImportJobs();
        $this->createReconcilerDlq();
        $this->createBackfillCheckpoints();

        $this->ensureSlotAssignmentFieldLiveUniqueIndex();
        $this->ensureSlotAssignmentSweepGapColumn();
        $this->ensureBackfillCheckpointsSourceTypeColumn();
        $this->ensureBackfillCheckpointsCorrelationIdColumn();
        $this->ensureFieldsPreviousNameColumn();
        $this->ensureFieldsDeletedAtColumn();
        $this->ensureModelsDeletedAtColumn();
        $this->ensureSyncQueueEntryIdIndex();
        $this->ensureSyncQueueOriginCorrelationIdColumn();
        $this->ensureDlqOriginCorrelationIdColumn();
        $this->ensureImportJobsCorrelationIdColumn();
        $this->ensureExportJobsCorrelationIdColumn();
        $this->seedSchemaVersionSingleton();
    }

    private function createEntryData(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS entry_data (
                id          BIGINT       NOT NULL AUTO_INCREMENT,
                tenant_id   BIGINT       NOT NULL,
                model_id    INT          NOT NULL,
                created_at  DATETIME     NOT NULL,
                updated_at  DATETIME     NOT NULL,
                deleted_at  DATETIME         NULL DEFAULT NULL,
                fields      JSON         NOT NULL,
                PRIMARY KEY (id),
                KEY ix_entry_data_tenant_model (tenant_id, model_id),
                KEY ix_entry_data_tenant_lifecycle (tenant_id, deleted_at, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL);
    }

    private function createSyncQueue(): void
    {
        // Schema reference §3 specifies the primary key only. Auxiliary
        // indexes (e.g., on entry_id or created_at) are deliberately not
        // added here — the table is "tiny" by design and the Reconciler's
        // access patterns (Phase 5) will introduce any indexes they need
        // as a separate, reviewable schema change.
        //
        // ADR 0038 is the first such change: see
        // `ensureSyncQueueEntryIdIndex()`, which adds `(entry_id)` because
        // the model purge deletes queue rows by that column. It stays an
        // ALTER rather than moving here, so an existing deployment picks
        // it up on the next bootstrap.
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS stardust_sync_queue (
                id          BIGINT   NOT NULL AUTO_INCREMENT,
                entry_id    BIGINT   NOT NULL,
                created_at  DATETIME NOT NULL,
                PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL);
    }

    private function createModels(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS stardust_models (
                id          INT          NOT NULL AUTO_INCREMENT,
                tenant_id   BIGINT       NOT NULL,
                name        VARCHAR(128) NOT NULL,
                created_at  DATETIME     NOT NULL,
                deleted_at  DATETIME         NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ux_models_tenant_name (tenant_id, name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL);
    }

    private function createFields(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS stardust_fields (
                id              BIGINT       NOT NULL AUTO_INCREMENT,
                model_id        INT          NOT NULL,
                name            VARCHAR(128) NOT NULL,
                declared_type   ENUM('string','int','numeric','datetime') NOT NULL,
                is_filterable   BOOLEAN      NOT NULL DEFAULT FALSE,
                created_at      DATETIME     NOT NULL,
                updated_at      DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ux_fields_model_name (model_id, name),
                CONSTRAINT fk_fields_model
                    FOREIGN KEY (model_id) REFERENCES stardust_models (id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL);
    }

    private function createPages(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS stardust_pages (
                id              INT          NOT NULL AUTO_INCREMENT,
                table_name      VARCHAR(64)  NOT NULL,
                provisioned_at  DATETIME     NOT NULL,
                provisioned_by  VARCHAR(128) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ux_pages_table_name (table_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL);
    }

    private function createSlotAssignments(): void
    {
        // The partial unique on field_id (live statuses only) is provisioned
        // separately in ensureSlotAssignmentFieldLiveUniqueIndex(): MySQL has
        // no `CREATE INDEX IF NOT EXISTS`, and functional-index DDL must be
        // gated on an information_schema probe to stay idempotent.
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS stardust_slot_assignments (
                id                  BIGINT       NOT NULL AUTO_INCREMENT,
                page_id             INT          NOT NULL,
                slot_column         VARCHAR(16)  NOT NULL,
                slot_type           ENUM('str','int','num','dt') NOT NULL,
                field_id            BIGINT           NULL DEFAULT NULL,
                status              ENUM('free','assigned','tombstoned','backfilling','ready')
                                    NOT NULL DEFAULT 'free',
                sweep_cursor_id     BIGINT           NULL DEFAULT NULL,
                tombstoned_at       DATETIME         NULL DEFAULT NULL,
                updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY ux_slot_assignments_page_column (page_id, slot_column),
                KEY ix_slot_assignments_status_type (status, slot_type),
                KEY ix_slot_assignments_page_status (page_id, status),
                CONSTRAINT fk_slot_assignments_page
                    FOREIGN KEY (page_id) REFERENCES stardust_pages (id),
                CONSTRAINT fk_slot_assignments_field
                    FOREIGN KEY (field_id) REFERENCES stardust_fields (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL);
    }

    private function createSchemaVersion(): void
    {
        // CHECK (id = 1) is declared per schema reference §5.1 / Phase 1
        // deliverable. MySQL 8.0.13–8.0.15 silently drops CHECK clauses
        // (information_schema.CHECK_CONSTRAINTS does not exist on those
        // versions); 8.0.16+ stores and enforces the constraint. On any
        // version, the operational singleton guarantee rests on
        // PRIMARY KEY (id) + the seed step in
        // seedSchemaVersionSingleton(), with the CHECK acting as an
        // additional defensive layer on 8.0.16+.
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS stardust_schema_version (
                id          TINYINT          NOT NULL,
                version     BIGINT UNSIGNED  NOT NULL DEFAULT 0,
                updated_at  DATETIME         NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT ck_schema_version_singleton CHECK (id = 1)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL);
    }

    private function createExportJobs(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS stardust_export_jobs (
                id               BIGINT        NOT NULL AUTO_INCREMENT,
                tenant_id        BIGINT        NOT NULL,
                status           ENUM('pending','processing','completed','failed')
                                 NOT NULL DEFAULT 'pending',
                filter           JSON          NOT NULL,
                format           ENUM('csv','json') NOT NULL,
                last_cursor      BIGINT            NULL DEFAULT NULL,
                artifact_path    VARCHAR(512)      NULL DEFAULT NULL,
                failed_reason    VARCHAR(64)       NULL DEFAULT NULL,
                skip_count       INT UNSIGNED  NOT NULL DEFAULT 0,
                worker_identity  VARCHAR(128)      NULL DEFAULT NULL,
                claimed_at       DATETIME          NULL DEFAULT NULL,
                heartbeat_at     DATETIME          NULL DEFAULT NULL,
                created_at       DATETIME      NOT NULL,
                completed_at     DATETIME          NULL DEFAULT NULL,
                PRIMARY KEY (id),
                KEY ix_export_jobs_status_created (status, created_at),
                KEY ix_export_jobs_tenant_status (tenant_id, status),
                KEY ix_export_jobs_status_heartbeat (status, heartbeat_at),
                KEY ix_export_jobs_completed (completed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL);
    }

    /**
     * Phase 3 async bulk-ingest job queue. Mirrors `stardust_export_jobs`
     * per ADR 0011 ("artifact path on local disk, identical to the export
     * pattern") — the Reconciler (Phase 5) will drain these. Phase 3 only
     * persists rows; processing is out of scope.
     *
     * The `(tenant_id, idempotency_key)` UNIQUE enforces ADR 0011's
     * idempotency-key contract at the database level. MySQL UNIQUE allows
     * multiple NULL idempotency_key rows so unkeyed submissions never
     * collide with each other.
     */
    private function createImportJobs(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS stardust_import_jobs (
                id               BIGINT        NOT NULL AUTO_INCREMENT,
                tenant_id        BIGINT        NOT NULL,
                status           ENUM('pending','processing','completed','failed')
                                 NOT NULL DEFAULT 'pending',
                idempotency_key  VARCHAR(128)      NULL DEFAULT NULL,
                artifact_path    VARCHAR(512) NOT NULL,
                entry_count      INT UNSIGNED NOT NULL,
                manifest         JSON              NULL DEFAULT NULL,
                failed_reason    VARCHAR(64)       NULL DEFAULT NULL,
                worker_identity  VARCHAR(128)      NULL DEFAULT NULL,
                claimed_at       DATETIME          NULL DEFAULT NULL,
                heartbeat_at     DATETIME          NULL DEFAULT NULL,
                created_at       DATETIME     NOT NULL,
                completed_at     DATETIME          NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ux_import_jobs_tenant_idempotency (tenant_id, idempotency_key),
                KEY ix_import_jobs_status_created (status, created_at),
                KEY ix_import_jobs_tenant_status (tenant_id, status),
                KEY ix_import_jobs_status_heartbeat (status, heartbeat_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL);
    }

    private function createReconcilerDlq(): void
    {
        // No FK to entry_data by design — the `missing_entry_data` reason
        // exists precisely so a DLQ row outlives its source row (ADR 0018).
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS stardust_reconciler_dlq (
                id                    BIGINT        NOT NULL AUTO_INCREMENT,
                source                ENUM('sync_queue','bulk_import') NOT NULL,
                entry_id              BIGINT            NULL DEFAULT NULL,
                tenant_id             BIGINT        NOT NULL,
                model_id              INT           NOT NULL,
                reason                ENUM('malformed_json','missing_entry_data','schema_incompatibility','other') NOT NULL,
                error_message         TEXT              NULL,
                failed_at             DATETIME      NOT NULL,
                retry_count           INT           NOT NULL DEFAULT 0,
                chunk_correlation_id  VARCHAR(36)   NOT NULL,
                PRIMARY KEY (id),
                KEY ix_dlq_source_failed_at (source, failed_at),
                KEY ix_dlq_entry (entry_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL);
    }

    private function createBackfillCheckpoints(): void
    {
        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS backfill_checkpoints (
                id                  BIGINT       NOT NULL AUTO_INCREMENT,
                job_name            VARCHAR(128) NOT NULL,
                last_processed_id   BIGINT       NOT NULL DEFAULT 0,
                status              ENUM('running','paused','completed','failed')
                                    NOT NULL DEFAULT 'running',
                started_at          DATETIME     NOT NULL,
                updated_at          DATETIME     NOT NULL,
                completed_at        DATETIME         NULL DEFAULT NULL,
                last_error          VARCHAR(512)     NULL DEFAULT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY ux_backfill_job_name (job_name),
                KEY ix_backfill_status_updated (status, updated_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL);
    }

    /**
     * Implements ADR 0017's "at most one live slot per field" invariant via
     * a functional unique index. The CASE expression yields field_id only
     * while the row is live (assigned, backfilling, ready) and NULL
     * otherwise — and NULLs are exempt from MySQL's UNIQUE constraint, so
     * tombstoned and free rows do not block reassignment.
     *
     * MySQL has no CREATE INDEX IF NOT EXISTS, so we self-check via
     * information_schema to stay idempotent across re-runs. The follow-up
     * catch on SQLSTATE 42000 / 1061 is defense in depth: a stale
     * information_schema cache on the connection can let the probe miss an
     * index that the storage engine still holds — without the catch, an
     * otherwise-correct re-bootstrap would explode on the duplicate-name
     * collision. Treating it as "already there" matches the table-level
     * `IF NOT EXISTS` semantics every other DDL in this runner uses.
     */
    private function ensureSlotAssignmentFieldLiveUniqueIndex(): void
    {
        $exists = (int) PdoQuery::run($this->pdo, <<<'SQL'
            SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE table_schema = DATABASE()
              AND table_name = 'stardust_slot_assignments'
              AND index_name = 'ux_slot_assignments_field_live'
        SQL)->fetchColumn();

        if ($exists > 0) {
            return;
        }

        try {
            $this->pdo->exec(<<<'SQL'
                CREATE UNIQUE INDEX ux_slot_assignments_field_live
                    ON stardust_slot_assignments (
                        (CASE WHEN status IN ('assigned', 'backfilling', 'ready')
                              THEN field_id END)
                    )
            SQL);
        } catch (PDOException $e) {
            // MySQL ER_DUP_KEYNAME = 1061. We only swallow this one
            // — anything else (permissions, syntax, connection) must
            // surface so the bootstrap genuinely fails fast.
            if (! $this->isDuplicateKeyName($e)) {
                throw $e;
            }
        }
    }

    private function isDuplicateKeyName(PDOException $e): bool
    {
        // PDO/MySQL drivers expose the raw error code in different
        // positions depending on the driver build; check both the
        // ANSI SQLSTATE in errorInfo[0] and the vendor code in [1].
        $info = $e->errorInfo;
        if (is_array($info) && isset($info[1]) && (int) $info[1] === 1061) {
            return true;
        }
        return str_contains($e->getMessage(), '1061');
    }

    /**
     * Phase 6a Liberator registry annotation. ADR 0009's bounded
     * deadlock-retry policy ends the third consecutive deadlock by
     * advancing the cursor and incrementing this counter — operators
     * read it to spot slots whose sweep skipped over rows.
     *
     * Same idempotency shape as the partial unique index above:
     * probe information_schema first, defensively swallow MySQL's
     * `ER_DUP_FIELDNAME` (1060) if a stale connection cache lets the
     * probe miss a column the engine still holds.
     */
    private function ensureSlotAssignmentSweepGapColumn(): void
    {
        $exists = (int) PdoQuery::run($this->pdo, <<<'SQL'
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE table_schema = DATABASE()
              AND table_name = 'stardust_slot_assignments'
              AND column_name = 'sweep_gap_count'
        SQL)->fetchColumn();

        if ($exists > 0) {
            return;
        }

        try {
            $this->pdo->exec(<<<'SQL'
                ALTER TABLE stardust_slot_assignments
                    ADD COLUMN sweep_gap_count INT NOT NULL DEFAULT 0
            SQL);
        } catch (PDOException $e) {
            if (! $this->isDuplicateFieldName($e)) {
                throw $e;
            }
        }
    }

    /**
     * Phase 6b: the Reconciler's retype work source needs to know the
     * `declared_type` the field had BEFORE the {@see \StarDust\Retype\RetypeInitiator}
     * overwrote it, so the {@see \StarDust\Retype\RetypeCoercionEngine}
     * can pick the right ADR 0024 matrix cell. We store it on the
     * checkpoint row so the work source does not have to derive it from
     * a tombstoned slot (which the Liberator may have already reclaimed).
     *
     * Nullable: existing Backfill Pump CLI checkpoints (`job_name` not
     * starting with `retype_field_`) never populate this column.
     */
    private function ensureBackfillCheckpointsSourceTypeColumn(): void
    {
        $exists = (int) PdoQuery::run($this->pdo, <<<'SQL'
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE table_schema = DATABASE()
              AND table_name = 'backfill_checkpoints'
              AND column_name = 'source_declared_type'
        SQL)->fetchColumn();

        if ($exists > 0) {
            return;
        }

        try {
            $this->pdo->exec(<<<'SQL'
                ALTER TABLE backfill_checkpoints
                    ADD COLUMN source_declared_type VARCHAR(16) NULL DEFAULT NULL
            SQL);
        } catch (PDOException $e) {
            if (! $this->isDuplicateFieldName($e)) {
                throw $e;
            }
        }
    }

    /**
     * ADR 0020's `correlation_id` must be "carried through any sub-events
     * emitted within the same operation" — and for the four asynchronous
     * lifecycles (rename, retype, field delete, model delete) the
     * operation spans two processes. The initiator commits and returns;
     * the Reconciler emits the completion event minutes or hours later.
     *
     * This column is what joins them. The initiator mints one id, writes
     * it here in the same transaction that opens the checkpoint, and
     * emits its `*_started` event under it; the work source reads it back
     * and emits `rename_complete` / `promote_to_ready` / `delete_complete`
     * / `model_delete_complete` under the same id. Before it, both halves
     * carried ids that correlated to nothing across the seam — the
     * completion events rode the *per-chunk* id, which changes every
     * tick.
     *
     * Nullable, and every reader falls back to the chunk id when it is
     * null. That is what makes the column safe to add under a running
     * fleet: a checkpoint already `running` when the ALTER lands keeps
     * the previous behaviour rather than emitting a null id. Backfill
     * Pump CLI checkpoints never populate it either, for the same reason
     * `source_declared_type` above is nullable.
     *
     * VARCHAR(36) is the canonical hyphenated v4 UUID width that
     * {@see \StarDust\Support\UuidV4::generate()} emits.
     */
    private function ensureBackfillCheckpointsCorrelationIdColumn(): void
    {
        $exists = (int) PdoQuery::run($this->pdo, <<<'SQL'
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE table_schema = DATABASE()
              AND table_name = 'backfill_checkpoints'
              AND column_name = 'correlation_id'
        SQL)->fetchColumn();

        if ($exists > 0) {
            return;
        }

        try {
            $this->pdo->exec(<<<'SQL'
                ALTER TABLE backfill_checkpoints
                    ADD COLUMN correlation_id VARCHAR(36) NULL DEFAULT NULL
            SQL);
        } catch (PDOException $e) {
            if (! $this->isDuplicateFieldName($e)) {
                throw $e;
            }
        }
    }

    /**
     * The originating write's ADR 0020 operation id, carried on the
     * ADR 0007 exhaustion-fallback queue row.
     *
     * `entry_written` and its paired `exhaustion_fallback` tell a
     * consumer the value went to the JSON payload and is waiting on a
     * slot. What happens next is a Reconciler chunk, and until this
     * column existed there was no way back from that chunk to the write
     * — so a dead-lettered backfill named the tick that failed it and
     * nothing about its provenance.
     *
     * **A chunk cannot carry one write's id**, which is why this is a
     * column here rather than a field on `chunk_complete`:
     * `SyncQueueWorkSource::claimChunk()` takes N rows originating from N
     * different writes. The reachable join is the failure path, so the
     * value is copied onto the dead-letter row instead (see
     * `ensureDlqOriginCorrelationIdColumn()`).
     *
     * Nullable, and every reader tolerates null: rows enqueued before
     * this landed drain normally. **This is the third schema change to a
     * table Phase 1 documented as PK-only**, after ADR 0038's
     * `ix_sync_queue_entry`, and it is a column rather than an index for
     * a reason — `EntryWriter`'s enqueue sits on ADR 0007's
     * write-availability path, so widening the row is acceptable where
     * adding a second index to maintain per INSERT would not be.
     */
    private function ensureSyncQueueOriginCorrelationIdColumn(): void
    {
        $exists = (int) PdoQuery::run($this->pdo, <<<'SQL'
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE table_schema = DATABASE()
              AND table_name = 'stardust_sync_queue'
              AND column_name = 'origin_correlation_id'
        SQL)->fetchColumn();

        if ($exists > 0) {
            return;
        }

        try {
            $this->pdo->exec(<<<'SQL'
                ALTER TABLE stardust_sync_queue
                    ADD COLUMN origin_correlation_id VARCHAR(36) NULL DEFAULT NULL
            SQL);
        } catch (PDOException $e) {
            if (! $this->isDuplicateFieldName($e)) {
                throw $e;
            }
        }
    }

    /**
     * The provenance half of the pair above: which write produced the
     * row that ended up quarantined.
     *
     * It sits **beside** `chunk_correlation_id`, which is NOT NULL and
     * unchanged, because the two answer different questions — "which
     * tick failed this" and "which write created it". An operator
     * triaging ADR 0018 dead letters needs both, and before this only
     * the first was recordable.
     *
     * Nullable because the `bulk_import` source has no single
     * originating write, and because a row quarantined from a queue
     * entry enqueued before the column existed has nothing to copy.
     */
    private function ensureDlqOriginCorrelationIdColumn(): void
    {
        $exists = (int) PdoQuery::run($this->pdo, <<<'SQL'
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE table_schema = DATABASE()
              AND table_name = 'stardust_reconciler_dlq'
              AND column_name = 'origin_correlation_id'
        SQL)->fetchColumn();

        if ($exists > 0) {
            return;
        }

        try {
            $this->pdo->exec(<<<'SQL'
                ALTER TABLE stardust_reconciler_dlq
                    ADD COLUMN origin_correlation_id VARCHAR(36) NULL DEFAULT NULL
            SQL);
        } catch (PDOException $e) {
            if (! $this->isDuplicateFieldName($e)) {
                throw $e;
            }
        }
    }

    /**
     * The submitting call's ADR 0020 operation id, so `bulk_accepted`
     * and the Reconciler chunks that drain the job join up.
     *
     * `ImportJobWorkSource` has no per-job completion event — only chunk
     * events, whose operation genuinely is the chunk. So those keep
     * their own `correlation_id` and name this one as
     * `job_correlation_id` alongside, which is the same
     * companion-field shape `chunk_correlation_id` established.
     *
     * Nullable: a job submitted before this landed drains normally with
     * the field simply absent.
     */
    private function ensureImportJobsCorrelationIdColumn(): void
    {
        $exists = (int) PdoQuery::run($this->pdo, <<<'SQL'
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE table_schema = DATABASE()
              AND table_name = 'stardust_import_jobs'
              AND column_name = 'correlation_id'
        SQL)->fetchColumn();

        if ($exists > 0) {
            return;
        }

        try {
            $this->pdo->exec(<<<'SQL'
                ALTER TABLE stardust_import_jobs
                    ADD COLUMN correlation_id VARCHAR(36) NULL DEFAULT NULL
            SQL);
        } catch (PDOException $e) {
            if (! $this->isDuplicateFieldName($e)) {
                throw $e;
            }
        }
    }

    /**
     * The export counterpart, and the cleanest of the three: the
     * Chronicler emits a genuine per-job pair (`job_claimed` →
     * `job_complete` / `job_failed`), so those take this id directly
     * rather than through a companion field. It is the closest analogue
     * in the engine to the four registry lifecycles.
     *
     * Nullable for the same in-flight reason as the others.
     */
    private function ensureExportJobsCorrelationIdColumn(): void
    {
        $exists = (int) PdoQuery::run($this->pdo, <<<'SQL'
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE table_schema = DATABASE()
              AND table_name = 'stardust_export_jobs'
              AND column_name = 'correlation_id'
        SQL)->fetchColumn();

        if ($exists > 0) {
            return;
        }

        try {
            $this->pdo->exec(<<<'SQL'
                ALTER TABLE stardust_export_jobs
                    ADD COLUMN correlation_id VARCHAR(36) NULL DEFAULT NULL
            SQL);
        } catch (PDOException $e) {
            if (! $this->isDuplicateFieldName($e)) {
                throw $e;
            }
        }
    }

    /**
     * ADR 0036: `entry_data.fields` is keyed by `stardust_fields.name`,
     * so renaming a field is a payload rewrite over every entry in the
     * model rather than a registry write. The rename flips `name`
     * immediately and drains the payload asynchronously, which leaves a
     * window in which some rows carry the old key and some the new.
     *
     * `previous_name` is what bridges that window. It is set by the
     * {@see \StarDust\Rename\RenameInitiator} and cleared in the same
     * transaction that completes the backfill, so a non-null value means
     * exactly "a rename is in flight for this field".
     *
     * It lives here rather than on `backfill_checkpoints` (where
     * `source_declared_type` lives) because the read and write paths
     * both need it on the hot path: `SlotResolver` and `LiveSlotMap`
     * already SELECT from `stardust_fields` with no join, so the alias
     * rides along for free. A checkpoint column would force both to
     * take a join they otherwise do not need.
     *
     * VARCHAR(128) matches `stardust_fields.name`. Nullable, and null
     * in steady state.
     */
    private function ensureFieldsPreviousNameColumn(): void
    {
        $exists = (int) PdoQuery::run($this->pdo, <<<'SQL'
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE table_schema = DATABASE()
              AND table_name = 'stardust_fields'
              AND column_name = 'previous_name'
        SQL)->fetchColumn();

        if ($exists > 0) {
            return;
        }

        try {
            $this->pdo->exec(<<<'SQL'
                ALTER TABLE stardust_fields
                    ADD COLUMN previous_name VARCHAR(128) NULL DEFAULT NULL
            SQL);
        } catch (PDOException $e) {
            if (! $this->isDuplicateFieldName($e)) {
                throw $e;
            }
        }
    }

    /**
     * ADR 0037: a field's values live in `entry_data.fields` under its
     * name (ADR 0036), so deleting a field is a payload rewrite over
     * every entry in the model — not a registry DELETE. The registry row
     * has to outlive the purge, because the purge work source needs the
     * field's name, model and tenant to build its JSON path and
     * `backfill_checkpoints` has nowhere to put them.
     *
     * `deleted_at` is what keeps that row alive without letting anything
     * see it: **a non-null value means exactly "a deletion is in flight
     * for this field"**, and every registry reader excludes it from that
     * moment on. The row is hard-deleted by the final purge chunk, so
     * this is a marker for the drain window, not a soft-delete tier —
     * there is no undelete and nothing retains it afterwards.
     *
     * Deliberately the same shape as `previous_name` above, for the same
     * reason: `SlotResolver` and `LiveSlotMap` already SELECT this table
     * with no join, so the predicate costs them nothing.
     *
     * Nullable, and null in steady state.
     */
    private function ensureFieldsDeletedAtColumn(): void
    {
        $exists = (int) PdoQuery::run($this->pdo, <<<'SQL'
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE table_schema = DATABASE()
              AND table_name = 'stardust_fields'
              AND column_name = 'deleted_at'
        SQL)->fetchColumn();

        if ($exists > 0) {
            return;
        }

        try {
            $this->pdo->exec(<<<'SQL'
                ALTER TABLE stardust_fields
                    ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL
            SQL);
        } catch (PDOException $e) {
            if (! $this->isDuplicateFieldName($e)) {
                throw $e;
            }
        }
    }

    private function isDuplicateFieldName(PDOException $e): bool
    {
        $info = $e->errorInfo;
        if (is_array($info) && isset($info[1]) && (int) $info[1] === 1060) {
            return true;
        }
        return str_contains($e->getMessage(), '1060');
    }

    /**
     * ADR 0038 model-deletion drain marker. The model-level analogue of
     * `stardust_fields.deleted_at` above, and **not redundant with it**.
     *
     * A model deletion marks every field of the model as well, which is
     * what makes every existing field-severance guard fire with no new
     * predicates. But a guard derived only from those markers has to be
     * spelled "no field of this model is live" — which is true of every
     * brand-new empty model, and a model registered with no fields at all
     * is legal. So the model needs its own marker: it is what lets the
     * purge's claim query assert its own integrity, what keeps a deleting
     * model out of `listModels()` / `describeModel()`, and what stops the
     * get-or-create model lookup handing back the id of a model whose
     * entries are being erased.
     *
     * A non-null value means exactly "a model deletion is in flight". The
     * row is hard-deleted by the final purge chunk, which cascades the
     * field rows away with it — so this is a drain-window marker, not a
     * soft-delete tier. There is no undelete.
     *
     * This gives `stardust_models` its first nullable column. The table
     * still has no `updated_at`, so `ModelRenamer` still needs no clock.
     *
     * Nullable, and null in steady state.
     */
    private function ensureModelsDeletedAtColumn(): void
    {
        $exists = (int) PdoQuery::run($this->pdo, <<<'SQL'
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE table_schema = DATABASE()
              AND table_name = 'stardust_models'
              AND column_name = 'deleted_at'
        SQL)->fetchColumn();

        if ($exists > 0) {
            return;
        }

        try {
            $this->pdo->exec(<<<'SQL'
                ALTER TABLE stardust_models
                    ADD COLUMN deleted_at DATETIME NULL DEFAULT NULL
            SQL);
        } catch (PDOException $e) {
            if (! $this->isDuplicateFieldName($e)) {
                throw $e;
            }
        }
    }

    /**
     * The "separate, reviewable schema change" `createSyncQueue()` above
     * has been deferring since Phase 1 — ADR 0038 is the access pattern
     * that needs it.
     *
     * The model purge deletes each chunk's queue rows by `entry_id` in the
     * same transaction as the entries themselves, or `SyncQueueWorkSource`
     * quarantines every one of them as `missing_entry_data` and the purge
     * manufactures a dead-letter row per pending write. Measured on MySQL
     * 8.0.13: without this index, deleting ten rows by `entry_id` from a
     * 100 000-row queue is a full table scan taking **100 261 exclusive
     * record locks** — held for the length of a chunk transaction that is
     * also holding the `entry_data` deletes and their page cascade. With
     * it, the same delete takes 30.
     *
     * That is a write-availability prerequisite rather than a purge
     * optimisation: `SyncQueueWorkSource` claims with `SKIP LOCKED` and
     * steps aside, but `EntryWriter`'s exhaustion enqueue does not, and a
     * blocked sync-queue INSERT is a blocked `write()` — which ADR 0007
     * does not permit.
     *
     * Callers must bind the chunk's ids as literals; expressed as a
     * subquery the delete reverts to the full scan this index exists to
     * prevent.
     */
    private function ensureSyncQueueEntryIdIndex(): void
    {
        $exists = (int) PdoQuery::run($this->pdo, <<<'SQL'
            SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE table_schema = DATABASE()
              AND table_name = 'stardust_sync_queue'
              AND index_name = 'ix_sync_queue_entry'
        SQL)->fetchColumn();

        if ($exists > 0) {
            return;
        }

        try {
            $this->pdo->exec(<<<'SQL'
                CREATE INDEX ix_sync_queue_entry
                    ON stardust_sync_queue (entry_id)
            SQL);
        } catch (PDOException $e) {
            if (! $this->isDuplicateKeyName($e)) {
                throw $e;
            }
        }
    }

    private function seedSchemaVersionSingleton(): void
    {
        $stmt = $this->pdo->prepare(<<<'SQL'
            INSERT INTO stardust_schema_version (id, version, updated_at)
            VALUES (1, 0, UTC_TIMESTAMP())
            ON DUPLICATE KEY UPDATE id = id
        SQL);
        $stmt->execute();
    }
}
