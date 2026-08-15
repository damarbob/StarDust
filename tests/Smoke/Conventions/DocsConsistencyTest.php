<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Conventions;

use PHPUnit\Framework\TestCase;
use StarDust\StarDust;

/**
 * Guards two documentation conventions that are otherwise only prose.
 *
 * Both failures are silent: nothing breaks, the docs simply start
 * lying, and nobody notices until a consumer follows them.
 *
 * DB-free by design.
 */
final class DocsConsistencyTest extends TestCase
{
    private const README    = __DIR__ . '/../../../README.md';
    private const CHANGELOG = __DIR__ . '/../../../CHANGELOG.md';

    /**
     * The README is consumer-facing; ADRs are internal design records
     * that ship in a separate repo. A reader who follows "per ADR 0013"
     * finds nothing, so the README must explain behaviour on its own
     * terms.
     */
    public function testReadmeCitesNoAdrs(): void
    {
        $readme = (string) file_get_contents(self::README);

        self::assertSame(
            0,
            preg_match('/\bADR\b/', $readme),
            'README.md cites an ADR. ADRs are internal — state the behaviour directly'
            . ' instead. See CLAUDE.md, "Sibling docs and what each owns".',
        );
    }

    /**
     * `StarDust::VERSION` must name a release the CHANGELOG documents.
     *
     * This is what stops a per-phase version bump: raising VERSION
     * without adding a CHANGELOG heading fails here, so bumping becomes
     * a deliberate release act rather than a reflex during a feature
     * commit.
     */
    public function testVersionMatchesNewestChangelogHeading(): void
    {
        $changelog = (string) file_get_contents(self::CHANGELOG);

        $matched = preg_match('/^## \[([^\]]+)\]/m', $changelog, $matches);

        self::assertSame(
            1,
            $matched,
            'Could not find a `## [x.y.z]` heading in CHANGELOG.md — the Keep a Changelog'
            . ' structure has changed and this guard needs updating.',
        );

        self::assertSame(
            $matches[1],
            StarDust::VERSION,
            'StarDust::VERSION and the newest CHANGELOG heading disagree. The version stays'
            . ' put for the whole release cycle; when it does move, the CHANGELOG entry moves'
            . ' with it. See CLAUDE.md, "Working conventions".',
        );
    }
}
