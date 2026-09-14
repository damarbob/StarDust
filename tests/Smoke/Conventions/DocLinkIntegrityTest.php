<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Conventions;

use PHPUnit\Framework\TestCase;

/**
 * Guards the link graph of the consumer-facing docs: README.md and the
 * pure-lookup pages under docs/.
 *
 * The failure mode this exists for has already happened once. Splitting
 * a section out to docs/ leaves its in-page `](#anchor)` links behind,
 * pointing at a heading that is no longer in the file — and GitHub
 * renders a dead anchor happily, dropping the reader at the top of the
 * page with no error anywhere. Prose review does not catch it; the link
 * still *looks* right.
 *
 * Scope is README.md plus a FLAT glob of docs/*.md, matching
 * `DocsConsistencyTest` (which owns the flat-glob rationale via its own
 * `testDocsDirectoryIsFlat()`). CLAUDE.md is deliberately excluded: it
 * links into SDDPG/, a separate repo that is absent from a normal clone,
 * so every one of those links is unresolvable by design.
 *
 * Anchors are computed with GitHub's slug rule — lowercase, drop
 * everything that is not a letter/number/mark/space/hyphen/underscore,
 * spaces to hyphens. Dropping rather than replacing is why
 * "Construction & schema bootstrap" anchors as
 * `construction--schema-bootstrap` (the `&` vanishes, its flanking
 * spaces don't) and "1 — Bootstrap" as `1--bootstrap` (the em dash is
 * non-ASCII and is dropped the same way).
 *
 * Fenced code blocks are blanked out (line count preserved) before
 * either headings or links are scanned — `docs/cli.md` is one giant
 * `bash` fence full of `#` shell comments that would otherwise register
 * as dozens of bogus headings.
 *
 * DB-free by design.
 */
final class DocLinkIntegrityTest extends TestCase
{
    private const ROOT     = __DIR__ . '/../../..';
    private const README   = self::ROOT . '/README.md';
    private const DOCS_DIR = self::ROOT . '/docs';

    /**
     * Every `](#anchor)` must resolve to a heading in the same file.
     *
     * This is `README.md`'s "below, under [CLI](#cli)" bug, mechanised:
     * once a section moves to docs/ and its heading goes with it, any
     * link left pointing at the old anchor fails here instead of
     * silently landing the reader at the top of the page.
     */
    public function testEveryInPageAnchorResolvesToAHeadingInTheSameFile(): void
    {
        $checked = 0;

        foreach ($this->documents() as $path => $label) {
            $stripped = $this->stripFences((string) file_get_contents($path));
            $anchors  = $this->anchors($stripped);

            foreach ($this->links($stripped) as $link) {
                if ($link['target'] === '' || $link['target'][0] !== '#') {
                    continue;
                }

                $anchor = rawurldecode(substr($link['target'], 1));
                $checked++;

                self::assertContains(
                    $anchor,
                    $anchors,
                    "{$label} links to `#{$anchor}`, which is not a heading in {$label}. This is"
                    . ' what a section move leaves behind — the heading went to docs/, the link'
                    . ' stayed, and GitHub renders a dead anchor without complaining. Point it at'
                    . " the file that owns the heading now (`docs/<page>.md#{$anchor}` from"
                    . " README, `../README.md#{$anchor}` from a doc). See CLAUDE.md,"
                    . ' "Sibling docs and what each owns".',
                );
            }
        }

        self::assertGreaterThan(
            10,
            $checked,
            'Expected to check more than 10 in-page anchors across README.md and docs/*.md — the'
            . ' glob or link regex has probably broken.',
        );
    }

    /**
     * Every relative link (not `#...`, not an absolute URL / mailto)
     * must resolve to a real file, and if it carries a fragment into a
     * `.md` target, that fragment must be a real heading in that file.
     */
    public function testEveryRelativeLinkResolvesToItsTargetFileAndFragment(): void
    {
        $checked = 0;

        foreach ($this->documents() as $path => $label) {
            $stripped = $this->stripFences((string) file_get_contents($path));
            $dir      = dirname($path);

            foreach ($this->links($stripped) as $link) {
                $target = $link['target'];

                if ($target === '' || $target[0] === '#' || $this->isAbsoluteUrl($target)) {
                    continue;
                }

                $checked++;

                [$filePart, $fragment] = array_pad(explode('#', $target, 2), 2, null);
                $resolved = $dir . '/' . rawurldecode($filePart);

                self::assertTrue(
                    file_exists($resolved) || is_dir($resolved),
                    "{$label} links to `{$target}`, which does not exist. A relative link to a"
                    . ' moved or renamed file fails silently on GitHub — nothing in CI would'
                    . ' notice but this.',
                );

                if ($fragment !== null && str_ends_with($filePart, '.md') && file_exists($resolved)) {
                    $targetAnchors = $this->anchors(
                        $this->stripFences((string) file_get_contents($resolved)),
                    );
                    $fragment = rawurldecode($fragment);

                    self::assertContains(
                        $fragment,
                        $targetAnchors,
                        "{$label} links to `{$target}`, but " . basename($filePart) . ' has no'
                        . " heading with the anchor `{$fragment}`. Cross-file anchors are the"
                        . ' first thing a heading rename breaks.',
                    );
                }
            }
        }

        self::assertGreaterThan(
            5,
            $checked,
            'Expected to check more than 5 relative links across README.md and docs/*.md — the'
            . ' glob or link regex has probably broken.',
        );
    }

    /**
     * Every `## ` section in README.md must be named in the Contents
     * list — either the link targets `#<slug>`, or the link text itself
     * slugs to the heading (which is what lets `[Errors](docs/errors.md)`
     * cover `## Errors`).
     *
     * Nothing regenerates the Contents list, so a section added — or
     * split out to docs/ — without a matching entry is invisible to
     * every reader who navigates from the top.
     */
    public function testEveryReadmeSectionAppearsInTheContentsList(): void
    {
        $stripped = $this->stripFences((string) file_get_contents(self::README));
        $parts    = $this->splitReadmeContents($stripped);

        $targetSlugs = [];
        $textSlugs   = [];

        foreach ($this->links($parts['contents']) as $link) {
            if ($link['target'] !== '' && $link['target'][0] === '#') {
                $targetSlugs[] = rawurldecode(substr($link['target'], 1));
            }

            $textSlugs[] = $this->slug($link['text']);
        }

        $checked = 0;

        foreach (explode("\n", $stripped) as $line) {
            if (!preg_match('/^## (.+)$/', $line, $m)) {
                continue;
            }

            $heading = trim($m[1]);

            if ($heading === 'Contents') {
                continue;
            }

            $checked++;
            $slug = $this->slug($heading);

            self::assertTrue(
                in_array($slug, $targetSlugs, true) || in_array($slug, $textSlugs, true),
                "README.md has a `## {$heading}` section the Contents list does not name."
                . ' Contents is the README\'s only table of contents and nothing regenerates it,'
                . ' so a section added — or split out to docs/ — without an entry is invisible to'
                . ' every reader who navigates from the top. See CLAUDE.md, "Sibling docs and'
                . ' what each owns".',
            );
        }

        self::assertGreaterThan(
            10,
            $checked,
            'Expected to check more than 10 README ## sections — the heading scan has probably'
            . ' broken.',
        );
    }

    /**
     * Every page under docs/ must be linked from both README's Contents
     * list and its body. Turns CLAUDE.md's "a new file here needs a
     * one-line pointer from README's Contents list and body" from prose
     * into a check — a page nothing links to is a page nobody reads.
     */
    public function testEveryReferenceDocIsLinkedFromTheReadme(): void
    {
        $stripped = $this->stripFences((string) file_get_contents(self::README));
        $parts    = $this->splitReadmeContents($stripped);

        $contentsTargets = array_column($this->links($parts['contents']), 'target');
        $bodyTargets     = array_column($this->links($parts['body']), 'target');

        $checked = 0;

        foreach (glob(self::DOCS_DIR . '/*.md') ?: [] as $path) {
            $name = basename($path);
            $checked++;

            self::assertTrue(
                $this->linksToDoc($contentsTargets, $name),
                "docs/{$name} is not linked from README's Contents list. Every reference page"
                . ' needs both a Contents entry and a one-line pointer in the body — a page'
                . ' nothing links to is a page nobody reads, and it will rot unnoticed. See'
                . ' CLAUDE.md, "Sibling docs and what each owns".',
            );

            self::assertTrue(
                $this->linksToDoc($bodyTargets, $name),
                "docs/{$name} is not linked from README's body (outside the Contents list)."
                . ' Every reference page needs both a Contents entry and a one-line pointer in'
                . ' the body — a page nothing links to is a page nobody reads, and it will rot'
                . ' unnoticed. See CLAUDE.md, "Sibling docs and what each owns".',
            );
        }

        self::assertGreaterThan(
            0,
            $checked,
            'Expected to check at least one docs/*.md page — the glob has probably broken.',
        );
    }

    /** @return array<string,string> absolute path => display label, README first */
    private function documents(): array
    {
        $files = [self::README => 'README.md'];

        foreach (glob(self::DOCS_DIR . '/*.md') ?: [] as $path) {
            $files[$path] = 'docs/' . basename($path);
        }

        return $files;
    }

    /**
     * Blanks every fenced code block (opening/closing lines included),
     * preserving line count. Tolerates up to 3 leading spaces and any
     * depth of blockquote marker, since README's Config example is a
     * `> ```php` fence inside a blockquote.
     */
    private function stripFences(string $markdown): string
    {
        $lines     = explode("\n", $markdown);
        $fenceChar = null;
        $fenceLen  = 0;

        foreach ($lines as $i => $line) {
            if ($fenceChar === null) {
                if (preg_match('/^ {0,3}(?:>\s?)*(`{3,}|~{3,})/', $line, $m)) {
                    $fenceChar = $m[1][0];
                    $fenceLen  = strlen($m[1]);
                    $lines[$i] = '';
                }

                continue;
            }

            if (
                preg_match('/^ {0,3}(?:>\s?)*(`{3,}|~{3,})\s*$/', $line, $m)
                && $m[1][0] === $fenceChar
                && strlen($m[1]) >= $fenceLen
            ) {
                $fenceChar = null;
                $fenceLen  = 0;
            }

            $lines[$i] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * GitHub's heading slug: lowercase, drop anything that isn't a
     * letter/number/mark/space/hyphen/underscore, then spaces to
     * hyphens. Characters are dropped, not replaced — that's what
     * turns "Construction & schema bootstrap" into
     * `construction--schema-bootstrap`.
     */
    private function slug(string $heading): string
    {
        $heading = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $heading);
        $heading = mb_strtolower($heading, 'UTF-8');
        $heading = (string) preg_replace('/[^\p{L}\p{N}\p{M}\- _]+/u', '', $heading);

        return str_replace(' ', '-', $heading);
    }

    /**
     * Heading slugs in document order, with GitHub's duplicate
     * disambiguation (`foo`, `foo-1`, `foo-2`) — legal here since
     * `.markdownlint.json` sets `MD024` to `siblings_only`.
     *
     * @return list<string>
     */
    private function anchors(string $strippedMarkdown): array
    {
        $seen  = [];
        $slugs = [];

        foreach (explode("\n", $strippedMarkdown) as $line) {
            if (!preg_match('/^ {0,3}(#{1,6})[ \t]+(.+?)[ \t]*#*\s*$/', $line, $m)) {
                continue;
            }

            $base  = $this->slug($m[2]);
            $count = $seen[$base] ?? 0;
            $seen[$base] = $count + 1;
            $slugs[] = $count === 0 ? $base : "{$base}-{$count}";
        }

        return $slugs;
    }

    /** @return list<array{text: string, target: string}> */
    private function links(string $markdown): array
    {
        preg_match_all(
            '/\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/',
            $markdown,
            $matches,
            PREG_SET_ORDER,
        );

        $links = [];

        foreach ($matches as $m) {
            $links[] = ['text' => $m[1], 'target' => $m[2]];
        }

        return $links;
    }

    private function isAbsoluteUrl(string $target): bool
    {
        return preg_match('#^[a-z][a-z0-9+.-]*:#i', $target) === 1;
    }

    /**
     * Splits stripped README markdown into the Contents list body (the
     * lines between `## Contents` and the next `## ` heading) and
     * everything else, so a docs/ page's Contents entry and body
     * pointer can be checked separately.
     *
     * @return array{contents: string, body: string}
     */
    private function splitReadmeContents(string $stripped): array
    {
        $lines = explode("\n", $stripped);
        $n     = count($lines);
        $start = null;
        $end   = $n;

        foreach ($lines as $i => $line) {
            if ($start === null) {
                if (preg_match('/^## Contents\s*$/', $line)) {
                    $start = $i + 1;
                }

                continue;
            }

            if (preg_match('/^## /', $line)) {
                $end = $i;
                break;
            }
        }

        if ($start === null) {
            return ['contents' => '', 'body' => $stripped];
        }

        $contentsLines = array_slice($lines, $start, $end - $start);
        $bodyLines     = array_merge(array_slice($lines, 0, $start), array_slice($lines, $end));

        return [
            'contents' => implode("\n", $contentsLines),
            'body'     => implode("\n", $bodyLines),
        ];
    }

    /** @param list<string> $targets */
    private function linksToDoc(array $targets, string $filename): bool
    {
        foreach ($targets as $target) {
            if ($target === "docs/{$filename}" || str_starts_with($target, "docs/{$filename}#")) {
                return true;
            }
        }

        return false;
    }
}
