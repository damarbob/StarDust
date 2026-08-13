<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Slot;

use PHPUnit\Framework\TestCase;
use StarDust\Slot\IndexedSlotPredicate;

/**
 * Anti-drift guard for the shared "is this slot column indexed?"
 * predicate.
 *
 * Two consumers depend on asking the question identically — the
 * reserver filtering candidates, and the Watcher's capacity reporter
 * counting usable inventory. A second inline copy would let them
 * diverge silently, and the failure mode is nasty: the Watcher reports
 * healthy capacity while every reservation against it returns `null`.
 *
 * DB-free by design; this is a source scan, in the same spirit as
 * {@see \StarDust\Tests\Smoke\EventVocabularyTest}.
 */
final class IndexedSlotPredicateTest extends TestCase
{
    private const SRC = __DIR__ . '/../../../src/';

    /** Files that must delegate rather than carry their own copy. */
    private const MUST_NOT_INLINE = [
        'Slot/SlotReserver.php',
        'Watcher/CapacityReporter.php',
    ];

    public function testPredicateIsDefinedInExactlyOnePlace(): void
    {
        foreach (self::MUST_NOT_INLINE as $relative) {
            $path = self::SRC . $relative;
            if (! is_file($path)) {
                continue;
            }

            self::assertStringNotContainsString(
                'information_schema.STATISTICS',
                (string) file_get_contents($path),
                "{$relative} must use IndexedSlotPredicate::existsSql() rather than inlining the"
                . ' predicate — a second copy lets the reserver and the capacity reporter drift.',
            );
        }

        self::assertStringContainsString(
            'information_schema.STATISTICS',
            (string) file_get_contents(self::SRC . 'Slot/IndexedSlotPredicate.php'),
            'IndexedSlotPredicate is the one place the predicate may live.',
        );
    }

    public function testExistsSqlInterpolatesTheGivenAliases(): void
    {
        $sql = IndexedSlotPredicate::existsSql('sa', 'pg');

        self::assertStringContainsString('pg.table_name', $sql);
        self::assertStringContainsString('sa.slot_column', $sql);
        self::assertStringStartsWith('EXISTS (', $sql);
    }

    /** The defaults must match SlotReserver's aliases, or its substitution changes behaviour. */
    public function testDefaultAliasesMatchTheReserverQueryShape(): void
    {
        $sql = IndexedSlotPredicate::existsSql();

        self::assertStringContainsString('p.table_name', $sql);
        self::assertStringContainsString('a.slot_column', $sql);
    }
}
