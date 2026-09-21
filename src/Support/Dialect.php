<?php

declare(strict_types=1);

namespace StarDust\Support;

/**
 * The one definition of every engine-specific DDL/SQL construct in the
 * engine.
 *
 * StarDust supports MySQL 8.0.13+ / Percona 8.0.13+ (ADR 0023) and,
 * since ADR 0054/0055, MariaDB 10.11+. Two constructs in the current
 * schema diverge between them:
 *
 * - {@see self::tableOptionsClause()} — the table-level charset/collation
 *   clause. `utf8mb4_0900_ai_ci` is a MySQL 8.0 collation and does not
 *   exist on MariaDB (rejected, errno 1273); the MariaDB branch uses
 *   `utf8mb4_unicode_520_nopad_ci` per ADR 0054 §2.
 * - {@see self::liveSlotUniqueIndexDdl()} — the ADR 0017 "at most one
 *   live slot per field" invariant. MySQL enforces it with a functional
 *   unique index; its `CASE … END` expression inside a
 *   `CREATE UNIQUE INDEX` is 8.0.13+ functional-index syntax and does
 *   not parse on MariaDB (rejected, errno 1064). The MariaDB branch
 *   instead names the plain `UNIQUE` index over the `live_field_id`
 *   generated column that {@see \StarDust\Bootstrap\Bootstrapper}'s
 *   MariaDB-only DDL creates alongside it (ADR 0054 §4) — this method
 *   only knows about the index; the column DDL is a single-caller
 *   literal there.
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
 * comparison first ships — and per ADR 0054 §5 that day cannot arrive
 * at the MariaDB 10.11+ floor, since 10.6 is where that divergence was
 * found and 10.6 is out of scope.
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
 * Every method takes the {@see ServerEngine} to branch on explicitly
 * (ADR 0055 §2) — this class stays a pure function of
 * (construct, engine), never resolving the engine itself, so both
 * branches stay covered by a DB-free source scan.
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
    public static function tableOptionsClause(ServerEngine $engine): string
    {
        return match ($engine) {
            ServerEngine::MYSQL   => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
            ServerEngine::MARIADB => 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_nopad_ci',
        };
    }

    /**
     * The ADR 0017 "at most one live slot per field" invariant's index
     * DDL.
     *
     * **MySQL**: the `CASE` expression yields `field_id` only while the
     * row is live (`assigned`, `backfilling`, `ready`) and `NULL`
     * otherwise — NULLs are exempt from MySQL's UNIQUE constraint, so
     * tombstoned and free rows never block reassignment. Caller
     * ({@see \StarDust\Bootstrap\Bootstrapper::ensureSlotAssignmentFieldLiveUniqueIndex()})
     * owns the idempotency probe and the duplicate-key-name catch; this
     * method only names the DDL text.
     *
     * **MariaDB**: a plain `UNIQUE` index over `live_field_id`, a
     * `PERSISTENT` generated column holding the identical `CASE`
     * expression (ADR 0054 §4, verified on 10.6/10.11/11 — a second
     * *tombstoned* slot for one field is allowed, a second *live* one
     * refused with SQLSTATE 23000, matching the MySQL functional
     * index's observable behaviour exactly). The column itself is
     * created by a separate, MariaDB-only Bootstrapper method, since it
     * has exactly one caller and does not meet this class's
     * more-than-one-package threshold for living here.
     */
    public static function liveSlotUniqueIndexDdl(ServerEngine $engine): string
    {
        return match ($engine) {
            ServerEngine::MYSQL => <<<'SQL'
                CREATE UNIQUE INDEX ux_slot_assignments_field_live
                    ON stardust_slot_assignments (
                        (CASE WHEN status IN ('assigned', 'backfilling', 'ready')
                              THEN field_id END)
                    )
                SQL,
            ServerEngine::MARIADB => <<<'SQL'
                CREATE UNIQUE INDEX ux_slot_assignments_field_live
                    ON stardust_slot_assignments (live_field_id)
                SQL,
        };
    }
}
