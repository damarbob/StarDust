<?php

declare(strict_types=1);

namespace StarDust\Daemon;

use PDO;
use StarDust\Support\PdoQuery;

/**
 * Per-installation qualifier for MySQL advisory lock names (ADR 0053).
 *
 * **`GET_LOCK` names are scoped to the server, not to the database.**
 * Measured on 8.0.13: two sessions on two different schemas of one
 * mysqld contend on the same name — the second blocked its full
 * timeout and returned `0`. The engine's two lock names were fixed
 * literals (`stardust_page_provision`, `stardust_sweep_page_{id}`),
 * so two StarDust installations sharing one server excluded each
 * other's Watcher and each other's Liberator sweeps. That is the
 * normal case on shared hosting, which ADR 0048 made a supported
 * deployment target, and it is why this class exists.
 *
 * Neither collision corrupted anything — the Watcher catches the
 * timeout and skips provisioning for a tick, the Liberator's
 * zero-timeout acquisition skips the page — so this is a liveness
 * fix, not a correctness one. What it costs unqualified is real
 * though: a Watcher can burn `provisionLockTimeoutSeconds` of a cron
 * tick's budget waiting on a stranger, and sustained contention
 * starves provisioning, which is the one failure the deployment docs
 * single out (capacity is never replenished, and filterable writes
 * fall back to the unindexed JSON payload).
 *
 * ## The discriminator is the schema name
 *
 * StarDust has no table-name prefix — `entry_data` and the
 * `stardust_*` registry tables are fixed — so one database holds at
 * most one installation and `DATABASE()` is a sound identity for it.
 * `Config::$lockNamespace` overrides it for the case the default
 * cannot serve: an installation that must keep one lock identity
 * across a database rename, or two that must deliberately share one.
 *
 * The name is hashed rather than appended raw because a schema name
 * may be 64 characters and `GET_LOCK` rejects a name over 64. Twelve
 * hex characters leaves the longest name the engine builds
 * (`stardust_sweep_page_` + a 19-digit BIGINT + `_` + 12) at 52, so
 * the budget cannot overflow and no runtime length check is needed.
 * The suffix is memoised per instance; share one instance to keep
 * `SELECT DATABASE()` to a single round trip per process
 * ({@see \StarDust\StarDust::lockNamespace()} does).
 *
 * **Upgrading is not zero-downtime.** Qualified and unqualified names
 * do not exclude each other, so a Watcher on the old code and one on
 * the new can provision concurrently, and two Liberators can sweep
 * one page at once. Stop the daemons before deploying rather than
 * rolling them; on a cron-only host, that is one skipped tick.
 */
final class LockNamespace
{
    /** Hex characters of the digest kept — see the length budget above. */
    private const SUFFIX_LENGTH = 12;

    private ?string $suffix = null;

    /**
     * @param string|null $explicit `Config::$lockNamespace`. `null`
     *        derives the discriminator from `DATABASE()` instead.
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?string $explicit = null,
    ) {
    }

    /**
     * Qualifies a base lock name with this installation's suffix.
     *
     * Base names stay greppable — `stardust_page_provision` is still a
     * prefix of what reaches the server, so an operator reading
     * `performance_schema.metadata_locks` can still tell what a held
     * lock is for.
     */
    public function qualify(string $baseName): string
    {
        return $baseName . '_' . $this->suffix();
    }

    private function suffix(): string
    {
        if ($this->suffix === null) {
            $this->suffix = substr(sha1($this->explicit ?? $this->readDatabaseName()), 0, self::SUFFIX_LENGTH);
        }

        return $this->suffix;
    }

    /**
     * `DATABASE()` is NULL when the DSN selected no schema. The engine
     * cannot function in that state at all (every table reference is
     * unqualified), so this resolves to `''` and lets the real failure
     * surface where it is legible, rather than throwing a lock-naming
     * error over it.
     */
    private function readDatabaseName(): string
    {
        $name = PdoQuery::run($this->pdo, 'SELECT DATABASE()')->fetchColumn();

        return is_string($name) ? $name : '';
    }
}
