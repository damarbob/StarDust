<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Conventions;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use StarDust\Config\Config;
use StarDust\StarDust;

/**
 * Guards documentation conventions that are otherwise only prose.
 *
 * Every failure here is silent: nothing breaks, the docs simply start
 * lying, and nobody notices until a consumer follows them.
 *
 * DB-free by design.
 */
final class DocsConsistencyTest extends TestCase
{
    private const README        = __DIR__ . '/../../../README.md';
    private const CHANGELOG     = __DIR__ . '/../../../CHANGELOG.md';
    private const GLOSSARY      = __DIR__ . '/../../../GLOSSARY.md';
    private const CONFIGURATION = __DIR__ . '/../../../docs/configuration.md';
    private const DOCS_DIR      = __DIR__ . '/../../../docs';

    /**
     * The README, anything split out of it under docs/, and the
     * consumer-facing glossary are all read by someone outside the
     * project; ADRs are internal design records that ship in a separate
     * repo. A reader who follows "per ADR 0013" finds nothing, so these
     * docs must explain behaviour on their own terms. GLOSSARY.md in
     * particular has its own explicit "never cite an ADR" rule in
     * CLAUDE.md precisely because it diverges from the internal
     * SDDPG/glossary.md on purpose — nothing enforced that rule before
     * this method covered it.
     *
     * `docs/` is scanned as a directory rather than named file-by-file
     * for the same reason `EventVocabularyTest::scanDir()` is — a
     * hardcoded list stays green the moment a new doc is split out of
     * the README and forgotten here.
     */
    public function testReadmeAndDocsCiteNoAdrs(): void
    {
        foreach ($this->consumerDocs() as $path => $label) {
            $contents = (string) file_get_contents($path);

            self::assertSame(
                0,
                preg_match('/\bADR\b/', $contents),
                "{$label} cites an ADR. ADRs are internal — state the behaviour directly"
                . ' instead. See CLAUDE.md, "Sibling docs and what each owns".',
            );
        }
    }

    /**
     * Build phases ("Phase 5", "Phase 6a") are internal sequencing
     * vocabulary, defined only in CLAUDE.md's status table. A consumer
     * has never seen that table, so "Phase 6a's Liberator" tells them
     * nothing "the Liberator" does not — name the feature or daemon.
     * CHANGELOG.md is included: it is read by the same Packagist
     * audience. Only a numbered phase is rejected: the bare word "phase" has
     * ordinary uses (a sampling phase, a rollout phase).
     */
    public function testConsumerDocsNameNoBuildPhases(): void
    {
        $files = $this->consumerDocs() + [self::CHANGELOG => 'CHANGELOG.md'];

        foreach ($files as $path => $label) {
            $contents = (string) file_get_contents($path);

            self::assertSame(
                0,
                preg_match('/\bphases?\s+\d/i', $contents),
                "{$label} refers to a numbered build phase. Phases are internal — name the"
                . ' feature or daemon instead. See CLAUDE.md, "Sibling docs and what each owns".',
            );
        }
    }

    /**
     * README.md, GLOSSARY.md and every page under docs/, keyed by path
     * with a repo-relative label.
     *
     * @return array<string, string>
     */
    private function consumerDocs(): array
    {
        $files = [
            self::README   => 'README.md',
            self::GLOSSARY => 'GLOSSARY.md',
        ];

        foreach (glob(self::DOCS_DIR . '/*.md') ?: [] as $path) {
            $files[$path] = 'docs/' . basename($path);
        }

        return $files;
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

    /**
     * README.md and docs/configuration.md each quote the number of
     * optional `Config` constructor parameters in prose — a number that
     * has drifted from the real count before (37 and 39 were both wrong
     * against a real count of 46) with nothing to catch it. Both files
     * are pinned to the single canonical sentence "N optional `Config`
     * parameters" so one regex extracts the quoted number from each and
     * compares it against `ReflectionMethod`, which is the only source
     * of truth `Config`'s append-only constructor actually has.
     */
    public function testQuotedConfigParameterCountMatchesReflection(): void
    {
        $actual = (new ReflectionMethod(Config::class, '__construct'))->getNumberOfParameters() - 1;

        foreach ([self::README => 'README.md', self::CONFIGURATION => 'docs/configuration.md'] as $path => $label) {
            $contents = (string) file_get_contents($path);

            $matched = preg_match('/(\d+) optional `Config` parameters/', $contents, $matches);

            self::assertSame(
                1,
                $matched,
                "{$label} does not contain the canonical \"N optional `Config` parameters\""
                . ' sentence this guard looks for — the wording drifted and needs restoring,'
                . ' or this regex needs updating alongside it.',
            );

            self::assertSame(
                $actual,
                (int) $matches[1],
                "{$label} says {$matches[1]} optional Config parameters, but"
                . " Config::__construct() actually has {$actual}. Update the doc — or, if this"
                . ' assertion itself is wrong, Config just gained or lost a parameter.',
            );
        }
    }

    /**
     * `docs/` must stay a flat directory — no subdirectories.
     *
     * This guard and `Conventions\DocLinkIntegrityTest` both glob
     * `docs/*.md` one level deep, while CI's markdownlint step globs
     * `docs/**\/*.md`. A page placed under a subdirectory would pass
     * markdownlint but be invisible to both PHP guards — free to cite
     * an ADR and free to link nowhere.
     */
    public function testDocsDirectoryIsFlat(): void
    {
        foreach (scandir(self::DOCS_DIR) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            self::assertFalse(
                is_dir(self::DOCS_DIR . '/' . $name),
                "docs/{$name} is a subdirectory. docs/ must stay flat — both"
                . ' DocsConsistencyTest and DocLinkIntegrityTest glob docs/*.md one level deep,'
                . ' so a nested page would be unguarded by either. Keep docs/ flat, or make both'
                . ' globs recursive in the same change.',
            );
        }
    }
}
