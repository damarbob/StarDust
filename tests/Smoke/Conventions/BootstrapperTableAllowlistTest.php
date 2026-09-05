<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Conventions;

use PHPUnit\Framework\TestCase;
use StarDust\Tests\Smoke\Support\SchemaFixture;

/**
 * Anti-drift guard for the hand-maintained table-drop allowlist.
 *
 * `SchemaFixture::CORE_TABLES` is "every table the Bootstrapper
 * manages", and both the per-test `DELETE` sweep and the full
 * drop-and-rebuild work from it. CLAUDE.md instructs contributors to
 * extend it when a phase adds a table — precisely the kind of rule
 * that is forgotten on the one commit where it matters.
 *
 * The failure mode is quiet and confusing rather than loud: a table
 * missing from the list survives the sweep, so state leaks from one
 * test into the next and something unrelated fails intermittently. A
 * stale extra name is the opposite rot — a table that no longer
 * exists. Both directions are caught here.
 *
 * The list used to be pasted into six test classes, of which this
 * guard covered five; consolidating it onto the fixture is what lets
 * this assert against a single target with no registration step to
 * forget.
 *
 * DB-free by design; this is a source scan, in the same spirit as
 * {@see \StarDust\Tests\Smoke\EventVocabularyTest}.
 */
final class BootstrapperTableAllowlistTest extends TestCase
{
    private const BOOTSTRAPPER = __DIR__ . '/../../../src/Bootstrap/Bootstrapper.php';

    public function testTheAllowlistMatchesTheBootstrapperExactly(): void
    {
        $managed = $this->bootstrapperTables();

        self::assertNotEmpty(
            $managed,
            'Found no CREATE TABLE statements in Bootstrapper.php — the scan pattern has rotted.',
        );

        $declared = SchemaFixture::CORE_TABLES;
        sort($declared);

        self::assertSame(
            $managed,
            $declared,
            'SchemaFixture::CORE_TABLES has drifted from the tables Bootstrapper actually creates.'
            . ' A missing name leaks state between tests; an extra name is a stale entry.'
            . ' See CLAUDE.md, "Test conventions".',
        );
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
