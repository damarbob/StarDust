<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Support;

use PDO;
use StarDust\Bootstrap\Bootstrapper;

/**
 * The smoke suite's per-test database reset.
 *
 * Every smoke test wants the same thing: a schema that is exactly what
 * `Bootstrapper::run()` produces, holding no rows. The obvious way to
 * get there — drop every table and bootstrap again — is what the suite
 * did originally, and it is why a full run took tens of minutes: 782
 * test methods x ~22 DDL statements, each of which is an atomic DDL in
 * MySQL 8.0 and therefore fsyncs InnoDB *and* the binlog. Measured on
 * the 8.0.13 floor, that reset costs ~1.5 s, which is more wall-clock
 * than the entire rest of the suite.
 *
 * `DELETE FROM` reaches the identical end state for ~7 ms, because it
 * is DML: no data-dictionary write, no tablespace file churn. Note the
 * trap that rules out the obvious middle ground — **`TRUNCATE TABLE`
 * is DDL in InnoDB**, dropping and recreating the tablespace, and
 * benchmarks no better than the drop-and-rebuild it would replace.
 *
 * Two things a `DELETE` sweep does not restore on its own:
 *
 * - **AUTO_INCREMENT counters.** Mostly harmless — no test asserts a
 *   literal row id — but page *table names* are derived from
 *   `stardust_pages.id`, and a good number of tests name
 *   `entry_slots_page_1` directly. {@see IDENTITY_TABLES}.
 * - **The `stardust_schema_version` singleton**, which the sweep
 *   deletes. `Bootstrapper::run()` reseeds it, which is one of two
 *   reasons this still calls the bootstrapper on every reset; the
 *   other is that it keeps the schema self-healing if a test (or a
 *   future phase's new table) leaves the database in a shape
 *   {@see CORE_TABLES} does not describe.
 *
 * `BootstrapTest` deliberately does NOT reset this way: proving the
 * DDL is idempotent and non-destructive is its subject, so it needs
 * the real drop-and-rebuild — which it takes from {@see dropAll()},
 * against the same table list.
 */
final class SchemaFixture
{
    /**
     * Every table `Bootstrapper::run()` manages.
     *
     * The single copy of a list that used to be pasted into six test
     * classes. It is the sweep's allowlist, so a name missing here
     * survives the reset and leaks state into the next test — a quiet,
     * intermittent failure rather than a loud one.
     * `Conventions\BootstrapperTableAllowlistTest` scans
     * `Bootstrapper.php` and fails if this list drifts in either
     * direction, which is what makes one shared copy safe: the guard
     * has a single target instead of six, and no test class can hold a
     * stale private copy it forgot to register.
     *
     * Phase 2 extension pages (`entry_slots_page_N`) are named
     * dynamically and cannot be listed; both sweeps below discover
     * them from `information_schema` at runtime.
     *
     * The order is the reverse of FK dependency for human readability
     * only — both sweeps disable `FOREIGN_KEY_CHECKS`.
     *
     * @var list<string>
     */
    public const CORE_TABLES = [
        'stardust_slot_assignments',
        'stardust_pages',
        'stardust_fields',
        'stardust_models',
        'stardust_sync_queue',
        'entry_data',
        'stardust_schema_version',
        'stardust_export_jobs',
        'stardust_import_jobs',
        'stardust_reconciler_dlq',
        'backfill_checkpoints',
    ];

    /**
     * Tables whose AUTO_INCREMENT value is observable from a test.
     *
     * Two qualify, for unrelated reasons:
     *
     * - **`stardust_pages`** — `PageProvisioner` names each page table
     *   `entry_slots_page_{id}`, so a counter that kept climbing would
     *   rename the table out from under every fixture that hardcodes
     *   `entry_slots_page_1`.
     * `entry_data` **was** here and came out with ADR 0046. It was
     * listed for one reason: `SlotSweeper`'s gap path advanced the
     * cursor as `$cursor + $chunkSize` — arithmetic on *ids* where ADR
     * 0009 says skip ahead by `LIMIT` rows — and the two agree only
     * while entry ids are dense and 1-based, which dropping the table
     * used to guarantee. The gap now advances by the chunk's own last
     * id, so nothing reads an `entry_data` id as a literal any more and
     * the reset bought only the defect's silence. Removing it saves one
     * `ALTER TABLE` (~18 ms) per test. **Do not re-add it to make a
     * failing sweep test pass** — that is the defect coming back.
     *
     * Deliberately not "all of them" — `ALTER TABLE … AUTO_INCREMENT`
     * is itself DDL (~18 ms each, measured), so resetting all eleven
     * costs ~200 ms per test and buys nothing: model, field and job
     * ids are always captured from the value the API returns. Add a
     * table here only when something genuinely reads its ids as
     * literals, and say what does.
     */
    private const IDENTITY_TABLES = ['stardust_pages'];

    /**
     * Whether a reset in this process has already pinned the counters.
     *
     * The AUTO_INCREMENT skip in {@see reset()} infers "the counter is
     * still 1" from "this reset deleted no rows", which holds only
     * because the *previous* reset left it at 1. That is not true of
     * the first reset in a run: the schema may have survived a crashed
     * earlier run with an advanced counter and no rows left to delete.
     * So the first reset always issues both `ALTER`s, and later ones
     * skip the tables that gave up nothing. Dropping the schema resets
     * the counters but also invalidates the inference, so
     * {@see dropAll()} clears this too.
     */
    private static bool $identityPinned = false;

    /**
     * Return the suite to a bootstrapped, empty schema.
     */
    public static function reset(PDO $pdo): void
    {
        $present    = self::tableNames($pdo);
        $pageTables = self::pageTablesIn($present);

        // A missing core table means the previous test was BootstrapTest
        // (or something failed mid-DDL). Fall back to the slow path
        // rather than issuing DELETE against a table that isn't there.
        if (array_diff(self::CORE_TABLES, $present) !== []) {
            self::dropAll($pdo, $pageTables);
            (new Bootstrapper($pdo))->run();

            return;
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            // Page tables are per-test artifacts with per-test column
            // sets, so these really do have to be dropped.
            foreach ($pageTables as $pageTable) {
                $pdo->exec("DROP TABLE IF EXISTS {$pageTable}");
            }

            /** @var array<string, int> $deleted */
            $deleted = [];
            foreach (self::CORE_TABLES as $t) {
                $deleted[$t] = (int) $pdo->exec("DELETE FROM {$t}");
            }

            // Each ALTER is ~18 ms of DDL — more than everything else
            // in this reset put together — and a table that gave up no
            // rows has had no INSERT since the last reset pinned its
            // counter at 1. {@see $identityPinned} for why the first
            // reset of a run cannot make that inference.
            foreach (self::IDENTITY_TABLES as $t) {
                if (self::$identityPinned && $deleted[$t] === 0) {
                    continue;
                }
                $pdo->exec("ALTER TABLE {$t} AUTO_INCREMENT = 1");
            }
            self::$identityPinned = true;
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        // Reseeds the schema-version singleton the sweep just deleted,
        // and re-creates anything CORE_TABLES failed to describe.
        (new Bootstrapper($pdo))->run();
    }

    /**
     * The original drop-everything sweep, for callers that need virgin
     * DDL rather than empty tables.
     *
     * @param ?list<string> $pageTables discovered when null
     */
    public static function dropAll(PDO $pdo, ?array $pageTables = null): void
    {
        $pageTables ??= self::pageTablesIn(self::tableNames($pdo));

        // Restored in a finally: a session left with FK checks off is a
        // far more confusing failure than whatever tripped the sweep.
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ([...$pageTables, ...self::CORE_TABLES] as $t) {
                try {
                    $pdo->exec("DROP TABLE IF EXISTS {$t}");
                } catch (\Throwable) {
                    // best-effort, same as the per-class sweeps this replaced
                }
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            self::$identityPinned = false;
        }
    }

    /**
     * @param  list<string> $tables
     * @return list<string>
     */
    private static function pageTablesIn(array $tables): array
    {
        return array_values(array_filter(
            $tables,
            static fn (string $t): bool => str_starts_with($t, 'entry_slots_page_'),
        ));
    }

    /** @return list<string> every table in the connected schema */
    private static function tableNames(PDO $pdo): array
    {
        /** @var list<string> $names */
        $names = $pdo
            ->query(
                'SELECT table_name FROM information_schema.TABLES'
                . ' WHERE table_schema = DATABASE()'
            )
            ->fetchAll(PDO::FETCH_COLUMN);

        return $names;
    }
}
