<?php

declare(strict_types=1);

namespace StarDust\Tests\Smoke\Conventions;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Enforces the two mechanical rules of the writing style guide
 * (`.agent/rules/writing-style-guide.md`) on human-read text.
 *
 * Em and en dashes are ratcheted per file. Text written before the
 * rule still has thousands of them, so each file's count is pinned in
 * `dash-baseline.json` and may only go down. A file missing from the
 * baseline is held at zero. When a count drops, lower its entry in the
 * same change (or delete it at zero), so the progress stays locked in.
 *
 * Every `CLAUDE.md` is exempt, because text written for agents is
 * outside the rule. `.agent/rules/` and AGENTS.md are never scanned.
 *
 * DB-free.
 */
final class WritingStyleTest extends TestCase
{
    private const ROOT     = __DIR__ . '/../../..';
    private const BASELINE = __DIR__ . '/dash-baseline.json';

    /**
     * Root-level files the rule covers. They are named rather than
     * globbed, because the project root can also hold local files that
     * are not part of the repository.
     */
    private const ROOT_FILES = [
        'CHANGELOG.md',
        'CONTRIBUTING.md',
        'GLOSSARY.md',
        'README.md',
        'TESTING.md',
        'release.sh',
    ];

    /** Directories scanned recursively. */
    private const DIRS = ['bin', 'docker', 'docs', 'examples', 'src', 'tests'];

    private const DASH = "/[\u{2013}\u{2014}]/u";

    public function testNoFileGainsDashes(): void
    {
        $baseline = $this->baseline();
        $failures = [];

        foreach ($this->coveredFiles() as $path) {
            $count   = $this->dashCount($path);
            $allowed = $baseline[$path] ?? 0;
            if ($count > $allowed) {
                $failures[] = sprintf('%s has %d em or en dashes, and its baseline is %d.', $path, $count, $allowed);
            }
        }

        self::assertSame(
            [],
            $failures,
            'New text must not use em or en dashes. Rewrite with a comma, parentheses,'
            . ' or two sentences. See .agent/rules/writing-style-guide.md.',
        );
    }

    public function testBaselineIsTight(): void
    {
        $covered  = array_flip($this->coveredFiles());
        $failures = [];

        foreach ($this->baseline() as $path => $allowed) {
            if (!isset($covered[$path])) {
                $failures[] = sprintf('%s is in the baseline but is no longer scanned. Delete its entry.', $path);
                continue;
            }
            $count = $this->dashCount($path);
            if ($count < $allowed) {
                $failures[] = $count === 0
                    ? sprintf('%s has no dashes left. Delete its entry.', $path)
                    : sprintf('%s is down to %d dashes. Lower its entry from %d to %d.', $path, $count, $allowed, $count);
            }
        }

        self::assertSame(
            [],
            $failures,
            'dash-baseline.json must match the current counts, so removed dashes cannot quietly come back.',
        );
    }

    public function testHeadingsHaveNoColonOrSemicolon(): void
    {
        $failures = [];

        foreach ($this->coveredFiles() as $path) {
            if (!str_ends_with($path, '.md')) {
                continue;
            }
            $inFence = false;
            foreach ($this->lines($path) as $number => $line) {
                if (preg_match('/^\s*(```|~~~)/', $line) === 1) {
                    $inFence = !$inFence;
                    continue;
                }
                if (!$inFence && preg_match('/^#{1,6}\s/', $line) === 1 && preg_match('/[:;]/', $line) === 1) {
                    $failures[] = sprintf('%s:%d %s', $path, $number + 1, trim($line));
                }
            }
        }

        self::assertSame(
            [],
            $failures,
            'A heading never contains a colon or semicolon. See .agent/rules/writing-style-guide.md.',
        );
    }

    /**
     * Repository-relative paths, sorted, with forward slashes.
     *
     * @return list<string>
     */
    private function coveredFiles(): array
    {
        $root  = (string) realpath(self::ROOT);
        $files = [];

        foreach (self::ROOT_FILES as $name) {
            if (is_file($root . '/' . $name)) {
                $files[] = $name;
            }
        }

        foreach (self::DIRS as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . '/' . $dir, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getFilename() === 'CLAUDE.md') {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($root) + 1);
                $files[]  = str_replace('\\', '/', $relative);
            }
        }

        sort($files);

        return $files;
    }

    /** @return array<string, int> */
    private function baseline(): array
    {
        $decoded = json_decode((string) file_get_contents(self::BASELINE), true);
        self::assertIsArray($decoded, 'dash-baseline.json is not valid JSON.');

        return $decoded;
    }

    private function dashCount(string $path): int
    {
        return (int) preg_match_all(self::DASH, (string) file_get_contents(self::ROOT . '/' . $path));
    }

    /** @return list<string> */
    private function lines(string $path): array
    {
        return preg_split('/\R/', (string) file_get_contents(self::ROOT . '/' . $path)) ?: [];
    }
}
