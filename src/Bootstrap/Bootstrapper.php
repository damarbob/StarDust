<?php

declare(strict_types=1);

namespace StarDust\Bootstrap;

use PDO;

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
     * information_schema to stay idempotent across re-runs.
     */
    private function ensureSlotAssignmentFieldLiveUniqueIndex(): void
    {
        $exists = (int) $this->pdo->query(<<<'SQL'
            SELECT COUNT(*) FROM information_schema.STATISTICS
            WHERE table_schema = DATABASE()
              AND table_name = 'stardust_slot_assignments'
              AND index_name = 'ux_slot_assignments_field_live'
        SQL)->fetchColumn();

        if ($exists > 0) {
            return;
        }

        $this->pdo->exec(<<<'SQL'
            CREATE UNIQUE INDEX ux_slot_assignments_field_live
                ON stardust_slot_assignments (
                    (CASE WHEN status IN ('assigned', 'backfilling', 'ready')
                          THEN field_id END)
                )
        SQL);
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
