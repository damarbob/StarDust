<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke;

use PHPUnit\Framework\TestCase;
use StarDust\Support\Dialect;

/**
 * Anti-drift guard for the engine's MySQL-specific DDL/SQL constructs.
 *
 * `Dialect` exists so a future second-engine change is a one-file edit
 * rather than a grep across `Bootstrapper` and `PageProvisioner`. A
 * stray inline copy of either literal defeats that, silently — nothing
 * else would notice until someone tried to make the swap.
 *
 * DB-free by design; this is a source scan, in the same spirit as
 * {@see \StarDust\Tests\Smoke\Slot\IndexedSlotPredicateTest}.
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
            self::assertStringNotContainsString(
                'utf8mb4_0900_ai_ci',
                (string) file_get_contents(self::SRC . $relative),
                "{$relative} must use Dialect::tableOptionsClause() rather than inlining the"
                . ' collation literal — a second copy defeats the point of naming it once.',
            );
        }

        self::assertStringContainsString(
            'utf8mb4_0900_ai_ci',
            (string) file_get_contents(self::SRC . 'Support/Dialect.php'),
            'Dialect is the one place the table collation literal may live.',
        );
    }

    public function testLiveSlotUniqueIndexDdlIsDefinedInExactlyOnePlace(): void
    {
        self::assertStringNotContainsString(
            'CREATE UNIQUE INDEX ux_slot_assignments_field_live',
            (string) file_get_contents(self::SRC . 'Bootstrap/Bootstrapper.php'),
            'Bootstrapper must use Dialect::liveSlotUniqueIndexDdl() rather than inlining the'
            . ' functional-index DDL — a second copy defeats the point of naming it once.',
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
            Dialect::tableOptionsClause(),
        );
    }

    public function testLiveSlotUniqueIndexDdlNamesTheThreeLiveStatuses(): void
    {
        $sql = Dialect::liveSlotUniqueIndexDdl();

        self::assertStringContainsString('ux_slot_assignments_field_live', $sql);
        self::assertStringContainsString('stardust_slot_assignments', $sql);
        self::assertStringContainsString("'assigned', 'backfilling', 'ready'", $sql);
    }
}
