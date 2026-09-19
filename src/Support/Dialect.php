<?php

declare(strict_types=1);

namespace StarDust\Support;

/**
 * The one definition of every MySQL-specific DDL/SQL construct in the
 * engine.
 *
 * StarDust's supported database is MySQL 8.0.13+ / Percona 8.0.13+
 * (ADR 0023), and two constructs in the current schema rely on syntax
 * or behaviour that is not portable to a second engine:
 *
 * - {@see self::tableOptionsClause()} — the table-level charset/collation
 *   clause. `utf8mb4_0900_ai_ci` is a MySQL 8.0 collation; it does not
 *   exist on MariaDB (rejected, errno 1273).
 * - {@see self::liveSlotUniqueIndexDdl()} — the ADR 0017 "at most one
 *   live slot per field" functional unique index. Its `CASE … END`
 *   expression inside a `CREATE UNIQUE INDEX` is 8.0.13+ functional-index
 *   syntax and does not parse on MariaDB (rejected, errno 1064).
 *
 * A third construct is named for completeness but has no method here
 * yet: the collation of a JSON-fallback comparison
 * (`JSON_UNQUOTE(JSON_EXTRACT(...))`) for a non-filterable field's
 * value. No such comparison exists in shipped code today —
 * `Search\Mysql\SqlFilterCompiler` can never reach a non-filterable
 * field, because the ADR 0004 pre-flight rejects a filter against one
 * before compilation, and the ADR 0013 JSON-fallback read path
 * (`Read\ResultAssembler`, `Search\Mysql\MysqlNativeDriver::get()`) is
 * pure PHP `json_decode()`, not a SQL comparison. Add a method here,
 * not an inline literal elsewhere, the day a JSON-fallback SQL
 * comparison first ships.
 *
 * Each existing construct is used from more than one package
 * (`Bootstrap\Bootstrapper` and `Page\PageProvisioner` both need the
 * collation), so this lives in `Support/` on the
 * {@see RetryableLockFailure} precedent rather than beside either
 * caller. **Do not re-inline either literal** — the entire point of
 * naming them here once is that a future second-engine change is a
 * one-file edit rather than a grep. Enforced by
 * `tests/Smoke/DialectTest.php`.
 *
 * Every method returns MySQL syntax unconditionally today — there is no
 * dialect switch anywhere in the engine, and this class does not add
 * one. It is only the seam a future switch would need.
 */
final class Dialect
{
    private function __construct()
    {
    }

    /**
     * The table-level `ENGINE=... DEFAULT CHARSET=... COLLATE=...`
     * clause every `CREATE TABLE` in the engine ends with.
     */
    public static function tableOptionsClause(): string
    {
        return 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci';
    }

    /**
     * The ADR 0017 "at most one live slot per field" functional unique
     * index.
     *
     * The `CASE` expression yields `field_id` only while the row is live
     * (`assigned`, `backfilling`, `ready`) and `NULL` otherwise — and
     * NULLs are exempt from MySQL's UNIQUE constraint, so tombstoned and
     * free rows never block reassignment. Caller
     * ({@see \StarDust\Bootstrap\Bootstrapper::ensureSlotAssignmentFieldLiveUniqueIndex()})
     * owns the idempotency probe and the duplicate-key-name catch; this
     * method only names the DDL text.
     */
    public static function liveSlotUniqueIndexDdl(): string
    {
        return <<<'SQL'
            CREATE UNIQUE INDEX ux_slot_assignments_field_live
                ON stardust_slot_assignments (
                    (CASE WHEN status IN ('assigned', 'backfilling', 'ready')
                          THEN field_id END)
                )
            SQL;
    }
}
