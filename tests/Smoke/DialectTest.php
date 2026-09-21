<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use PHPUnit\Framework\TestCase;
use StarDust\Support\Dialect;
use StarDust\Support\ServerEngine;

/**
 * Anti-drift guard for the engine's dialect-specific DDL/SQL constructs.
 *
 * `Dialect` exists so a second-engine change is a one-file edit rather
 * than a grep across `Bootstrapper` and `PageProvisioner`. A stray
 * inline copy of any literal defeats that, silently — nothing else
 * would notice until someone tried to make the swap.
 *
 * DB-free by design; this is a source scan, in the same spirit as
 * {@see \StarDust\Tests\Smoke\Slot\IndexedSlotPredicateTest}. Since
 * ADR 0055 both `Dialect` methods take a {@see ServerEngine}, so the
 * scan and the behavioural assertions below cover both branches, not
 * only the MySQL one.
 */
final class DialectTest extends TestCase
{
    private const SRC = __DIR__ . '/../../src/';

    /** Files that must delegate to Dialect rather than carrying their own literal. */
    private const MUST_NOT_INLINE_COLLATION = [
        'Bootstrap/Bootstrapper.php',
        'Page/PageProvisioner.php',
    ];

    public function testTableCollationIsDefinedInExactlyOnePlace(): void
    {
        foreach (self::MUST_NOT_INLINE_COLLATION as $relative) {
            $source = (string) file_get_contents(self::SRC . $relative);
            self::assertStringNotContainsString(
                'utf8mb4_0900_ai_ci',
                $source,
                "{$relative} must use Dialect::tableOptionsClause() rather than inlining the"
                . ' MySQL collation literal — a second copy defeats the point of naming it once.',
            );
            self::assertStringNotContainsString(
                'utf8mb4_unicode_520_nopad_ci',
                $source,
                "{$relative} must use Dialect::tableOptionsClause() rather than inlining the"
                . ' MariaDB collation literal — a second copy defeats the point of naming it once.',
            );
        }

        $dialectSource = (string) file_get_contents(self::SRC . 'Support/Dialect.php');
        self::assertStringContainsString(
            'utf8mb4_0900_ai_ci',
            $dialectSource,
            'Dialect is the one place the MySQL table collation literal may live.',
        );
        self::assertStringContainsString(
            'utf8mb4_unicode_520_nopad_ci',
            $dialectSource,
            'Dialect is the one place the MariaDB table collation literal may live.',
        );
    }

    public function testLiveSlotUniqueIndexDdlIsDefinedInExactlyOnePlace(): void
    {
        self::assertStringNotContainsString(
            'CREATE UNIQUE INDEX ux_slot_assignments_field_live',
            (string) file_get_contents(self::SRC . 'Bootstrap/Bootstrapper.php'),
            'Bootstrapper must use Dialect::liveSlotUniqueIndexDdl() rather than inlining the'
            . ' index DDL — a second copy defeats the point of naming it once.',
        );

        self::assertStringContainsString(
            'CREATE UNIQUE INDEX ux_slot_assignments_field_live',
            (string) file_get_contents(self::SRC . 'Support/Dialect.php'),
            'Dialect is the one place the live-slot unique index DDL may live.',
        );
    }

    public function testTableOptionsClauseMatchesTheMySql80Collation(): void
    {
        self::assertSame(
            'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci',
            Dialect::tableOptionsClause(ServerEngine::MYSQL),
        );
    }

    public function testTableOptionsClauseMatchesTheMariaDbCollation(): void
    {
        self::assertSame(
            'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_nopad_ci',
            Dialect::tableOptionsClause(ServerEngine::MARIADB),
        );
    }

    public function testLiveSlotUniqueIndexDdlNamesTheThreeLiveStatusesOnMySql(): void
    {
        $sql = Dialect::liveSlotUniqueIndexDdl(ServerEngine::MYSQL);

        self::assertStringContainsString('ux_slot_assignments_field_live', $sql);
        self::assertStringContainsString('stardust_slot_assignments', $sql);
        self::assertStringContainsString("'assigned', 'backfilling', 'ready'", $sql);
        self::assertStringContainsString('CASE WHEN status', $sql, 'MySQL branch is a functional index over the CASE expression itself.');
    }

    /**
     * MariaDB has no functional-index syntax (errno 1064), so this
     * branch names a plain index over `live_field_id` instead — the
     * `PERSISTENT` generated column holding the identical `CASE`
     * expression, created by `Bootstrapper::ensureSlotAssignmentLiveFieldIdColumn()`.
     * Verified against real MariaDB 10.6/10.11/11 (2026-09-20): the
     * combination allows a second tombstoned slot per field and refuses
     * a second live one with SQLSTATE 23000, matching MySQL's
     * observable behaviour.
     */
    public function testLiveSlotUniqueIndexDdlIndexesTheGeneratedColumnOnMariaDb(): void
    {
        $sql = Dialect::liveSlotUniqueIndexDdl(ServerEngine::MARIADB);

        self::assertStringContainsString('ux_slot_assignments_field_live', $sql);
        self::assertStringContainsString('stardust_slot_assignments', $sql);
        self::assertStringContainsString('live_field_id', $sql);
        self::assertStringNotContainsString(
            'CASE WHEN',
            $sql,
            'The CASE expression lives in the generated column DDL, not the MariaDB index DDL.',
        );
    }
}
