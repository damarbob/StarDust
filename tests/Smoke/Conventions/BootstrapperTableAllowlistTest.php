<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Conventions;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Anti-drift guard for the hand-maintained table-drop allowlists.
 *
 * Five test classes each carry their own copy of "every table the
 * Bootstrapper manages", used to reset the database between tests.
 * CLAUDE.md instructs contributors to extend every list when a phase
 * adds a table — precisely the kind of rule that is forgotten on the
 * one commit where it matters.
 *
 * The failure mode is quiet and confusing rather than loud: a table
 * missing from a list survives the drop, so state leaks from one test
 * into the next and something unrelated fails intermittently. A stale
 * extra name is the opposite rot — a table that no longer exists.
 * Both directions are caught here.
 *
 * DB-free by design; this is a source scan, in the same spirit as
 * {@see \StarDust\Tests\Smoke\EventVocabularyTest}.
 */
final class BootstrapperTableAllowlistTest extends TestCase
{
    private const BOOTSTRAPPER = __DIR__ . '/../../../src/Bootstrap/Bootstrapper.php';

    /**
     * Every allowlist that must stay in step, as class => constant.
     *
     * @var array<class-string, string>
     */
    private const ALLOWLISTS = [
        \StarDust\Tests\Smoke\BootstrapTest::class       => 'TABLES',
        \StarDust\Tests\Smoke\EmptyTableGuardTest::class => 'PHASE_1_TABLES',
        \StarDust\Tests\Smoke\PageProvisionerTest::class => 'PHASE_1_TABLES',
        \StarDust\Tests\Smoke\SlotReserverTest::class    => 'PHASE_1_TABLES',
        \StarDust\Tests\Smoke\WritePathTestCase::class   => 'PHASE_1_TABLES',
    ];

    public function testEveryAllowlistMatchesTheBootstrapperExactly(): void
    {
        $managed = $this->bootstrapperTables();

        self::assertNotEmpty(
            $managed,
            'Found no CREATE TABLE statements in Bootstrapper.php — the scan pattern has rotted.',
        );

        foreach (self::ALLOWLISTS as $class => $constant) {
            $declared = (new ReflectionClass($class))->getConstant($constant);

            self::assertIsArray(
                $declared,
                "{$class}::{$constant} must exist and be an array.",
            );

            /** @var list<string> $declared */
            $sorted = $declared;
            sort($sorted);

            self::assertSame(
                $managed,
                $sorted,
                "{$class}::{$constant} has drifted from the tables Bootstrapper actually creates."
                . ' A missing name leaks state between tests; an extra name is a stale entry.'
                . ' Update every allowlist together — see CLAUDE.md, "Test conventions".',
            );
        }
    }

    /**
     * The tables the Bootstrapper actually creates, sorted.
     *
     * @return list<string>
     */
    private function bootstrapperTables(): array
    {
        $source = (string) file_get_contents(self::BOOTSTRAPPER);
        preg_match_all('/CREATE TABLE IF NOT EXISTS\s+([a-z_]+)/', $source, $matches);

        $tables = array_values(array_unique($matches[1]));
        sort($tables);

        return $tables;
    }
}
